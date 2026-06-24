package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	"unicode/utf16"
)

type CurrentQuery struct {
	UID        string
	SQL        string
	StartedAt  time.Time
	CommandTag string
	Status     string
	ErrorCode  string
	ErrorMsg   string
	Rows       *int64
	EOFCount   int
}

type ConnState struct {
	SessionUID   string
	ConnectionID string
	ClientIP     string
	ClientPort   int
	TargetAddr   string
	DBName       string
	DBUser       string
	DBEngine     string

	mu           sync.Mutex
	currentQuery *CurrentQuery

	bytesC2D uint64
	bytesD2C uint64
}

type DBAdapter interface {
	Engine() string
	Mode() string
	Proxy(client net.Conn, backend net.Conn, state *ConnState)
}

var (
	controlURL        = env("SAG_CONTROL_PLANE_URL", "http://127.0.0.1")
	internalToken     = env("SAG_INTERNAL_TOKEN", "")
	sessionUID        = env("SAG_DB_SESSION_UID", "SAG-DB-POC-001")
	listenAddr        = env("SAG_DB_LISTEN", "127.0.0.1:15432")
	targetAddr        = env("SAG_DB_TARGET", "127.0.0.1:5432")
	dbName            = env("SAG_DB_NAME", "security_access_gateway")
	dbUser            = env("SAG_DB_USER", "sag_user")
	dbEngine          = strings.ToLower(env("SAG_DB_ENGINE", "postgresql"))
	allowedSourceCIDR = strings.TrimSpace(env("SAG_DB_ALLOWED_SOURCE_CIDR", ""))
)

func main() {
	if internalToken == "" {
		log.Fatal("SAG_INTERNAL_TOKEN is empty")
	}

	if strings.EqualFold(dbName, "security_access_gateway") && os.Getenv("SAG_ALLOW_INTERNAL_DB_PROXY") != "1" {
		log.Fatal("Refusing to proxy the SAG internal database security_access_gateway. Use a separate test DB. To override intentionally set SAG_ALLOW_INTERNAL_DB_PROXY=1")
	}

	adapter, err := selectAdapter(dbEngine)
	if err != nil {
		log.Fatal(err)
	}

	ln, err := net.Listen("tcp", listenAddr)
	if err != nil {
		log.Fatalf("listen %s failed: %v", listenAddr, err)
	}

	log.Printf("SAG DB Gateway listening on %s → %s engine=%s mode=%s allowed_source_cidr=%s", listenAddr, targetAddr, adapter.Engine(), adapter.Mode(), allowedSourceCIDR)

	for {
		client, err := ln.Accept()
		if err != nil {
			log.Printf("accept failed: %v", err)
			continue
		}

		go handleClient(client, adapter)
	}
}

var (
	deniedAuditMu   sync.Mutex
	deniedAuditLast = make(map[string]time.Time)
)

func shouldEmitDeniedAudit(clientIP string) bool {
	key := sessionUID + "|" + clientIP + "|" + allowedSourceCIDR
	now := time.Now()

	deniedAuditMu.Lock()
	defer deniedAuditMu.Unlock()

	if previous, exists := deniedAuditLast[key]; exists && now.Sub(previous) < 30*time.Second {
		return false
	}

	deniedAuditLast[key] = now
	return true
}

func auditDeniedConnection(clientIP string, clientPort int, remote string, adapter DBAdapter) {
	if !shouldEmitDeniedAudit(clientIP) {
		return
	}

	postJSON("/api/internal/db/connection-denied", map[string]any{
		"session_uid":         sessionUID,
		"client_ip":           clientIP,
		"client_port":         clientPort,
		"db_engine":           adapter.Engine(),
		"db_name":             dbName,
		"db_username":         dbUser,
		"allowed_source_cidr": allowedSourceCIDR,
		"reason_code":         "source_ip_not_allowed",
		"metadata": map[string]any{
			"source":      "sag-db-gateway",
			"mode":        adapter.Mode(),
			"remote_addr": remote,
			"rate_limit":  "30s_per_session_ip",
		},
	})
}

func auditDeniedSession(clientIP string, clientPort int, remote string, adapter DBAdapter, reasonCode string) {
	if !shouldEmitDeniedAudit(clientIP) {
		return
	}

	postJSON("/api/internal/db/connection-denied", map[string]any{
		"session_uid":         sessionUID,
		"client_ip":           clientIP,
		"client_port":         clientPort,
		"db_engine":           adapter.Engine(),
		"db_name":             dbName,
		"db_username":         dbUser,
		"allowed_source_cidr": allowedSourceCIDR,
		"reason_code":         reasonCode,
		"metadata": map[string]any{
			"source":               "sag-db-gateway",
			"mode":                 adapter.Mode(),
			"remote_addr":          remote,
			"session_status_check": true,
			"rate_limit":           "30s_per_session_ip",
		},
	})
}

func isClientSourceAllowed(host string) bool {
	if allowedSourceCIDR == "" {
		return true
	}

	ip := net.ParseIP(strings.TrimSpace(host))
	if ip == nil {
		log.Printf(
			"connection denied: cannot parse client ip host=%s allowed_source_cidr=%s session_uid=%s",
			host,
			allowedSourceCIDR,
			sessionUID,
		)
		return false
	}

	rule := strings.TrimSpace(allowedSourceCIDR)

	if strings.Contains(rule, "/") {
		_, allowedNet, err := net.ParseCIDR(rule)
		if err != nil {
			log.Printf(
				"connection denied: invalid allowed_source_cidr=%s err=%v session_uid=%s",
				rule,
				err,
				sessionUID,
			)
			return false
		}

		return allowedNet.Contains(ip)
	}

	allowedIP := net.ParseIP(rule)
	if allowedIP == nil {
		log.Printf(
			"connection denied: invalid allowed_source_ip=%s session_uid=%s",
			rule,
			sessionUID,
		)
		return false
	}

	return allowedIP.Equal(ip)
}

func checkSessionStatus(adapter DBAdapter) (bool, string) {
	payload, err := json.Marshal(map[string]string{
		"session_uid": sessionUID,
	})
	if err != nil {
		log.Printf("session status payload build failed: %v", err)
		return false, "session_status_unavailable"
	}

	req, err := http.NewRequest(
		"POST",
		strings.TrimRight(controlURL, "/")+"/api/internal/db/session-status",
		bytes.NewReader(payload),
	)
	if err != nil {
		log.Printf("session status request build failed: %v", err)
		return false, "session_status_unavailable"
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-SAG-Internal-Token", internalToken)

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("session status request failed: %v", err)
		return false, "session_status_unavailable"
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		log.Printf(
			"session status rejected: status=%d body=%s session_uid=%s",
			resp.StatusCode,
			strings.TrimSpace(string(body)),
			sessionUID,
		)
		return false, "session_status_unavailable"
	}

	var result struct {
		OK                bool   `json:"ok"`
		Allowed           bool   `json:"allowed"`
		Reason            string `json:"reason"`
		DBEngine          string `json:"db_engine"`
		AllowedSourceCIDR string `json:"allowed_source_cidr"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		log.Printf("session status response decode failed: %v", err)
		return false, "session_status_unavailable"
	}

	if !result.OK {
		return false, "session_status_invalid"
	}

	if !result.Allowed {
		if strings.TrimSpace(result.Reason) != "" {
			return false, result.Reason
		}

		return false, "session_not_allowed"
	}

	if result.DBEngine != "" && !strings.EqualFold(result.DBEngine, adapter.Engine()) {
		return false, "db_engine_mismatch"
	}

	if strings.TrimSpace(result.AllowedSourceCIDR) != "" &&
		strings.TrimSpace(result.AllowedSourceCIDR) != strings.TrimSpace(allowedSourceCIDR) {
		return false, "allowed_source_cidr_mismatch"
	}

	return true, ""
}

func selectAdapter(engine string) (DBAdapter, error) {
	switch strings.ToLower(engine) {
	case "postgresql", "postgres":
		return &PostgreSQLAdapter{}, nil
	case "mysql", "mariadb":
		return &MySQLAdapter{}, nil
	case "mssql", "sqlserver":
		return &MSSQLAdapter{}, nil
	default:
		return nil, fmt.Errorf("unsupported DB engine: %s", engine)
	}
}

func handleClient(client net.Conn, adapter DBAdapter) {
	defer client.Close()

	remote := client.RemoteAddr().String()
	host, portStr, _ := net.SplitHostPort(remote)
	port, _ := strconv.Atoi(portStr)

	if allowed, reason := checkSessionStatus(adapter); !allowed {
		log.Printf(
			"connection denied by session status: remote_ip=%s remote=%s reason=%s session_uid=%s",
			host,
			remote,
			reason,
			sessionUID,
		)

		auditDeniedSession(host, port, remote, adapter, reason)
		return
	}

	if !isClientSourceAllowed(host) {
		log.Printf(
			"connection denied by allowed source policy: remote_ip=%s remote=%s allowed_source_cidr=%s session_uid=%s",
			host,
			remote,
			allowedSourceCIDR,
			sessionUID,
		)

		auditDeniedConnection(host, port, remote, adapter)
		return
	}

	state := &ConnState{
		SessionUID:   sessionUID,
		ConnectionID: fmt.Sprintf("CONN-%d", time.Now().UnixNano()),
		ClientIP:     host,
		ClientPort:   port,
		TargetAddr:   targetAddr,
		DBName:       dbName,
		DBUser:       dbUser,
		DBEngine:     adapter.Engine(),
	}

	backend, err := net.Dial("tcp", targetAddr)
	if err != nil {
		log.Printf("backend connect failed: %v", err)
		return
	}
	defer backend.Close()

	postJSON("/api/internal/db/connection-started", map[string]any{
		"session_uid":    state.SessionUID,
		"connection_uid": state.ConnectionID,
		"client_ip":      state.ClientIP,
		"client_port":    state.ClientPort,
		"db_engine":      state.DBEngine,
		"db_name":        state.DBName,
		"db_username":    state.DBUser,
		"target_host":    strings.Split(state.TargetAddr, ":")[0],
		"target_port":    parsePort(state.TargetAddr),
		"metadata": map[string]any{
			"source": "sag-db-gateway",
			"mode":   adapter.Mode(),
		},
	})

	adapter.Proxy(client, backend, state)

	postJSON("/api/internal/db/connection-ended", map[string]any{
		"connection_uid":     state.ConnectionID,
		"status":             "ended",
		"bytes_client_to_db": atomic.LoadUint64(&state.bytesC2D),
		"bytes_db_to_client": atomic.LoadUint64(&state.bytesD2C),
		"metadata": map[string]any{
			"closed_at": time.Now().Format(time.RFC3339),
			"engine":    state.DBEngine,
			"mode":      adapter.Mode(),
		},
	})

	log.Printf("connection ended: %s engine=%s", state.ConnectionID, state.DBEngine)
}

/*
|--------------------------------------------------------------------------
| PostgreSQL adapter
|--------------------------------------------------------------------------
*/

type PostgreSQLAdapter struct{}

func (a *PostgreSQLAdapter) Engine() string { return "postgresql" }
func (a *PostgreSQLAdapter) Mode() string   { return "postgresql-aware-query-audit" }

func (a *PostgreSQLAdapter) Proxy(client net.Conn, backend net.Conn, state *ConnState) {
	done := make(chan struct{}, 2)

	go func() {
		a.clientToBackend(client, backend, state)
		done <- struct{}{}
	}()

	go func() {
		a.backendToClient(backend, client, state)
		done <- struct{}{}
	}()

	<-done
}

func (a *PostgreSQLAdapter) clientToBackend(client net.Conn, backend net.Conn, state *ConnState) {
	startupDone := false

	for {
		if !startupDone {
			packet, err := readPgStartupPacket(client)
			if err != nil {
				return
			}

			if isPgSSLRequest(packet) {
				_, _ = client.Write([]byte{'N'})
				continue
			}

			atomic.AddUint64(&state.bytesC2D, uint64(len(packet)))
			if _, err := backend.Write(packet); err != nil {
				return
			}

			startupDone = true
			continue
		}

		msgType, payload, raw, err := readPgMessage(client)
		if err != nil {
			return
		}

		if msgType == 'Q' {
			sql := strings.TrimRight(string(payload), "\x00")
			beginQuery(state, "simple", sql, "", "")
			log.Printf("postgresql query: %s", summarizeSQL(sql))
		}

		atomic.AddUint64(&state.bytesC2D, uint64(len(raw)))
		if _, err := backend.Write(raw); err != nil {
			return
		}
	}
}

func (a *PostgreSQLAdapter) backendToClient(backend net.Conn, client net.Conn, state *ConnState) {
	for {
		msgType, payload, raw, err := readPgMessage(backend)
		if err != nil {
			return
		}

		switch msgType {
		case 'C':
			tag := strings.TrimRight(string(payload), "\x00")
			updateCurrentQueryCommand(state, tag, rowsFromCommandTag(tag))
		case 'E':
			code, msg := parsePgErrorResponse(payload)
			updateCurrentQueryError(state, code, msg)
		case 'Z':
			finalizeCurrentQuery(state)
		}

		atomic.AddUint64(&state.bytesD2C, uint64(len(raw)))
		if _, err := client.Write(raw); err != nil {
			return
		}
	}
}

/*
|--------------------------------------------------------------------------
| MySQL / MariaDB adapter
|--------------------------------------------------------------------------
*/

type MySQLAdapter struct{}

func (a *MySQLAdapter) Engine() string { return "mysql" }
func (a *MySQLAdapter) Mode() string   { return "mysql-aware-com-query-audit" }

func (a *MySQLAdapter) Proxy(client net.Conn, backend net.Conn, state *ConnState) {
	done := make(chan struct{}, 2)

	go func() {
		a.clientToBackend(client, backend, state)
		done <- struct{}{}
	}()

	go func() {
		a.backendToClient(backend, client, state)
		done <- struct{}{}
	}()

	<-done
}

func (a *MySQLAdapter) clientToBackend(client net.Conn, backend net.Conn, state *ConnState) {
	for {
		payload, raw, err := readMySQLPacket(client)
		if err != nil {
			return
		}

		if len(payload) > 0 {
			cmd := payload[0]

			// COM_QUERY = 0x03
			if cmd == 0x03 && len(payload) > 1 {
				sql := string(payload[1:])
				beginQuery(state, "mysql_com_query", sql, "", "")
				log.Printf("mysql query: %s", summarizeSQL(sql))
			}

			// COM_STMT_PREPARE = 0x16
			if cmd == 0x16 && len(payload) > 1 {
				sql := string(payload[1:])
				beginQuery(state, "mysql_stmt_prepare", sql, "", "")
				log.Printf("mysql prepare: %s", summarizeSQL(sql))
			}
		}

		atomic.AddUint64(&state.bytesC2D, uint64(len(raw)))
		if _, err := backend.Write(raw); err != nil {
			return
		}
	}
}

func (a *MySQLAdapter) backendToClient(backend net.Conn, client net.Conn, state *ConnState) {
	for {
		payload, raw, err := readMySQLPacket(backend)
		if err != nil {
			return
		}

		if len(payload) > 0 {
			switch payload[0] {
			case 0x00: // OK packet
				rows := parseMySQLAffectedRows(payload)
				updateCurrentQueryCommand(state, "OK", rows)
				finalizeCurrentQuery(state)
			case 0xff: // ERR packet
				code, msg := parseMySQLError(payload)
				updateCurrentQueryError(state, code, msg)
				finalizeCurrentQuery(state)
			case 0xfe: // EOF packet, often final marker for SELECT result sets
				state.mu.Lock()
				if state.currentQuery != nil {
					state.currentQuery.EOFCount++
					if state.currentQuery.EOFCount >= 2 {
						state.currentQuery.CommandTag = "EOF"
						state.mu.Unlock()
						finalizeCurrentQuery(state)
					} else {
						state.mu.Unlock()
					}
				} else {
					state.mu.Unlock()
				}
			}
		}

		atomic.AddUint64(&state.bytesD2C, uint64(len(raw)))
		if _, err := client.Write(raw); err != nil {
			return
		}
	}
}

/*
|--------------------------------------------------------------------------
| MSSQL / TDS adapter
|--------------------------------------------------------------------------
*/

type MSSQLAdapter struct{}

func (a *MSSQLAdapter) Engine() string { return "mssql" }
func (a *MSSQLAdapter) Mode() string   { return "mssql-aware-tds-sqlbatch-audit" }

func (a *MSSQLAdapter) Proxy(client net.Conn, backend net.Conn, state *ConnState) {
	done := make(chan struct{}, 2)

	go func() {
		a.clientToBackend(client, backend, state)
		done <- struct{}{}
	}()

	go func() {
		a.backendToClient(backend, client, state)
		done <- struct{}{}
	}()

	<-done
}

func (a *MSSQLAdapter) clientToBackend(client net.Conn, backend net.Conn, state *ConnState) {
	for {
		packetType, payload, raw, err := readTDSPacket(client)
		if err != nil {
			return
		}

		// TDS SQLBatch packet type = 0x01.
		// Payload is usually UCS-2LE SQL text after login.
		if packetType == 0x01 && len(payload) > 0 {
			sql := decodeUTF16LE(payload)
			sql = strings.TrimSpace(sql)

			if sql != "" {
				beginQuery(state, "tds_sql_batch", sql, "", "")
				log.Printf("mssql sqlbatch: %s", summarizeSQL(sql))
			}
		}

		atomic.AddUint64(&state.bytesC2D, uint64(len(raw)))
		if _, err := backend.Write(raw); err != nil {
			return
		}
	}
}

func (a *MSSQLAdapter) backendToClient(backend net.Conn, client net.Conn, state *ConnState) {
	for {
		_, payload, raw, err := readTDSPacket(backend)
		if err != nil {
			return
		}

		parseTDSResponseTokens(payload, state)

		atomic.AddUint64(&state.bytesD2C, uint64(len(raw)))
		if _, err := client.Write(raw); err != nil {
			return
		}
	}
}

/*
|--------------------------------------------------------------------------
| Query lifecycle
|--------------------------------------------------------------------------
*/

func beginQuery(state *ConnState, protocolMode, sql, statementName, portalName string) {
	queryUID := fmt.Sprintf("QUERY-%d", time.Now().UnixNano())
	started := time.Now()

	state.mu.Lock()
	state.currentQuery = &CurrentQuery{
		UID:       queryUID,
		SQL:       sql,
		StartedAt: started,
		Status:    "running",
	}
	state.mu.Unlock()

	postJSON("/api/internal/db/query-event", map[string]any{
		"session_uid":       state.SessionUID,
		"connection_uid":    state.ConnectionID,
		"query_uid":         queryUID,
		"db_engine":         state.DBEngine,
		"protocol_mode":     protocolMode,
		"statement_name":    statementName,
		"portal_name":       portalName,
		"query_text_masked": maskSQL(sql),
		"query_hash":        sha256hex(sql),
		"status":            "running",
		"started_at":        started.Format(time.RFC3339Nano),
		"metadata": map[string]any{
			"source": "db_gateway_adapter",
			"engine": state.DBEngine,
		},
	})
}

func updateCurrentQueryCommand(state *ConnState, tag string, rows *int64) {
	state.mu.Lock()
	defer state.mu.Unlock()

	if state.currentQuery != nil {
		state.currentQuery.CommandTag = tag
		state.currentQuery.Rows = rows
	}
}

func updateCurrentQueryError(state *ConnState, code string, msg string) {
	state.mu.Lock()
	defer state.mu.Unlock()

	if state.currentQuery != nil {
		state.currentQuery.Status = "error"
		state.currentQuery.ErrorCode = code
		state.currentQuery.ErrorMsg = msg
	}
}

func finalizeCurrentQuery(state *ConnState) {
	state.mu.Lock()
	q := state.currentQuery
	state.currentQuery = nil
	state.mu.Unlock()

	if q == nil {
		return
	}

	ended := time.Now()
	durationMs := ended.Sub(q.StartedAt).Milliseconds()
	status := q.Status
	if status == "" || status == "running" {
		status = "success"
	}

	payload := map[string]any{
		"session_uid":       state.SessionUID,
		"connection_uid":    state.ConnectionID,
		"query_uid":         q.UID,
		"db_engine":         state.DBEngine,
		"protocol_mode":     "adapter_final",
		"query_text_masked": maskSQL(q.SQL),
		"query_hash":        sha256hex(q.SQL),
		"status":            status,
		"started_at":        q.StartedAt.Format(time.RFC3339Nano),
		"ended_at":          ended.Format(time.RFC3339Nano),
		"duration_ms":       durationMs,
		"command_tag":       q.CommandTag,
	}

	if q.Rows != nil {
		payload["rows_affected"] = *q.Rows
	}
	if q.ErrorCode != "" {
		payload["error_code"] = q.ErrorCode
	}
	if q.ErrorMsg != "" {
		payload["error_message_masked"] = q.ErrorMsg
	}

	postJSON("/api/internal/db/query-event", payload)
}

/*
|--------------------------------------------------------------------------
| PostgreSQL helpers
|--------------------------------------------------------------------------
*/

func readPgStartupPacket(r io.Reader) ([]byte, error) {
	lenBuf := make([]byte, 4)
	if _, err := io.ReadFull(r, lenBuf); err != nil {
		return nil, err
	}

	n := int(binary.BigEndian.Uint32(lenBuf))
	if n < 8 || n > 10*1024*1024 {
		return nil, fmt.Errorf("invalid startup packet length: %d", n)
	}

	body := make([]byte, n-4)
	if _, err := io.ReadFull(r, body); err != nil {
		return nil, err
	}

	return append(lenBuf, body...), nil
}

func isPgSSLRequest(packet []byte) bool {
	if len(packet) != 8 {
		return false
	}

	code := binary.BigEndian.Uint32(packet[4:8])
	return code == 80877103
}

func readPgMessage(r io.Reader) (byte, []byte, []byte, error) {
	typeBuf := make([]byte, 1)
	if _, err := io.ReadFull(r, typeBuf); err != nil {
		return 0, nil, nil, err
	}

	lenBuf := make([]byte, 4)
	if _, err := io.ReadFull(r, lenBuf); err != nil {
		return 0, nil, nil, err
	}

	n := int(binary.BigEndian.Uint32(lenBuf))
	if n < 4 || n > 50*1024*1024 {
		return 0, nil, nil, fmt.Errorf("invalid message length: %d", n)
	}

	payload := make([]byte, n-4)
	if _, err := io.ReadFull(r, payload); err != nil {
		return 0, nil, nil, err
	}

	raw := make([]byte, 0, 1+4+len(payload))
	raw = append(raw, typeBuf...)
	raw = append(raw, lenBuf...)
	raw = append(raw, payload...)

	return typeBuf[0], payload, raw, nil
}

func parsePgErrorResponse(payload []byte) (string, string) {
	code := ""
	message := ""

	parts := bytes.Split(payload, []byte{0})
	for _, p := range parts {
		if len(p) < 2 {
			continue
		}

		fieldType := p[0]
		value := string(p[1:])

		switch fieldType {
		case 'C':
			code = value
		case 'M':
			message = value
		}
	}

	return code, message
}

/*
|--------------------------------------------------------------------------
| MySQL helpers
|--------------------------------------------------------------------------
*/

func readMySQLPacket(r io.Reader) ([]byte, []byte, error) {
	header := make([]byte, 4)
	if _, err := io.ReadFull(r, header); err != nil {
		return nil, nil, err
	}

	payloadLen := int(header[0]) | int(header[1])<<8 | int(header[2])<<16
	if payloadLen < 0 || payloadLen > 64*1024*1024 {
		return nil, nil, fmt.Errorf("invalid mysql packet length: %d", payloadLen)
	}

	payload := make([]byte, payloadLen)
	if _, err := io.ReadFull(r, payload); err != nil {
		return nil, nil, err
	}

	raw := append(header, payload...)
	return payload, raw, nil
}

func parseMySQLAffectedRows(payload []byte) *int64 {
	if len(payload) < 2 || payload[0] != 0x00 {
		return nil
	}

	n, _ := readLenEncInt(payload[1:])
	return &n
}

func readLenEncInt(b []byte) (int64, int) {
	if len(b) == 0 {
		return 0, 0
	}

	switch b[0] {
	case 0xfc:
		if len(b) < 3 {
			return 0, 1
		}
		return int64(binary.LittleEndian.Uint16(b[1:3])), 3
	case 0xfd:
		if len(b) < 4 {
			return 0, 1
		}
		v := int64(b[1]) | int64(b[2])<<8 | int64(b[3])<<16
		return v, 4
	case 0xfe:
		if len(b) < 9 {
			return 0, 1
		}
		return int64(binary.LittleEndian.Uint64(b[1:9])), 9
	default:
		return int64(b[0]), 1
	}
}

func parseMySQLError(payload []byte) (string, string) {
	if len(payload) < 3 || payload[0] != 0xff {
		return "", ""
	}

	code := binary.LittleEndian.Uint16(payload[1:3])
	msgOffset := 3

	if len(payload) > 9 && payload[3] == '#' {
		msgOffset = 9
	}

	return fmt.Sprintf("%d", code), string(payload[msgOffset:])
}

/*
|--------------------------------------------------------------------------
| MSSQL / TDS helpers
|--------------------------------------------------------------------------
*/

func readTDSPacket(r io.Reader) (byte, []byte, []byte, error) {
	header := make([]byte, 8)
	if _, err := io.ReadFull(r, header); err != nil {
		return 0, nil, nil, err
	}

	packetType := header[0]
	length := int(binary.BigEndian.Uint16(header[2:4]))
	if length < 8 || length > 64*1024*1024 {
		return 0, nil, nil, fmt.Errorf("invalid tds packet length: %d", length)
	}

	payload := make([]byte, length-8)
	if _, err := io.ReadFull(r, payload); err != nil {
		return 0, nil, nil, err
	}

	raw := append(header, payload...)
	return packetType, payload, raw, nil
}

func decodeUTF16LE(b []byte) string {
	if len(b)%2 != 0 {
		b = b[:len(b)-1]
	}

	u := make([]uint16, 0, len(b)/2)
	for i := 0; i+1 < len(b); i += 2 {
		u = append(u, binary.LittleEndian.Uint16(b[i:i+2]))
	}

	return string(utf16.Decode(u))
}

func parseTDSResponseTokens(payload []byte, state *ConnState) {
	for i := 0; i < len(payload); {
		token := payload[i]
		i++

		switch token {
		case 0xaa: // ERROR token
			if i+2 > len(payload) {
				return
			}
			tokenLen := int(binary.LittleEndian.Uint16(payload[i : i+2]))
			i += 2
			if i+tokenLen > len(payload) {
				return
			}
			msg := "MSSQL error"
			updateCurrentQueryError(state, "TDS_ERROR", msg)
			i += tokenLen

		case 0xfd, 0xfe, 0xff: // DONE / DONEPROC / DONEINPROC
			if i+12 > len(payload) {
				return
			}
			rowCount := int64(binary.LittleEndian.Uint64(payload[i+4 : i+12]))
			updateCurrentQueryCommand(state, "DONE", &rowCount)
			finalizeCurrentQuery(state)
			i += 12

		default:
			// Unknown token. Cannot safely parse length for every token in MVP.
			// Stop parsing this packet but continue proxying traffic.
			return
		}
	}
}

/*
|--------------------------------------------------------------------------
| Common helpers
|--------------------------------------------------------------------------
*/

func postJSON(path string, payload map[string]any) {
	b, _ := json.Marshal(payload)

	req, err := http.NewRequest("POST", strings.TrimRight(controlURL, "/")+path, bytes.NewReader(b))
	if err != nil {
		log.Printf("api request build failed: %v", err)
		return
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-SAG-Internal-Token", internalToken)

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("api post failed %s: %v", path, err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 300 {
		body, _ := io.ReadAll(resp.Body)
		log.Printf("api post failed %s: status=%d body=%s", path, resp.StatusCode, string(body))
	}
}

func rowsFromCommandTag(tag string) *int64 {
	fields := strings.Fields(tag)
	if len(fields) == 0 {
		return nil
	}

	last := fields[len(fields)-1]
	n, err := strconv.ParseInt(last, 10, 64)
	if err != nil {
		return nil
	}

	return &n
}

func maskSQL(sql string) string {
	sql = strings.ReplaceAll(sql, "\x00", "")
	sql = strings.Join(strings.Fields(sql), " ")
	return sql
}

func summarizeSQL(sql string) string {
	sql = maskSQL(sql)
	if len(sql) > 160 {
		return sql[:160] + "..."
	}
	return sql
}

func sha256hex(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

func parsePort(addr string) int {
	_, p, err := net.SplitHostPort(addr)
	if err != nil {
		return 0
	}
	n, _ := strconv.Atoi(p)
	return n
}

func env(k, def string) string {
	v := os.Getenv(k)
	if v == "" {
		return def
	}
	return v
}
