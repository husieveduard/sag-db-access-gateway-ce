package main

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/binary"
	"net"
	"net/http"
	"strings"
	"time"
)

func myFrontend(ctx context.Context, raw net.Conn, config *tls.Config, database string, verify func(string, string) bool) (net.Conn, error) {
	if config == nil || len(config.Certificates) == 0 || !mySafeName(database) || verify == nil {
		return nil, errMyProtocol
	}
	raw.SetDeadline(time.Now().Add(10 * time.Second))
	seed := make([]byte, 20)
	if _, e := rand.Read(seed); e != nil {
		return nil, errMyProtocol
	}
	seq := byte(0)
	if myWrite(raw, &seq, myGreeting(seed)) != nil {
		return nil, errMyProtocol
	}
	ssl, e := myRead(raw, &seq)
	if e != nil || len(ssl) != 32 {
		return nil, errMyProtocol
	}
	flags := binary.LittleEndian.Uint32(ssl)
	required := myProtocol | mySSL | mySecure | myDatabase | myPlugin
	// Standard clients offer LOCAL_FILES and MULTI_RESULTS even when the server
	// does not advertise them. Ignore those offers; never negotiate either.
	if ssl[8] != 45 || flags&required != required || flags&^(myCapabilities|0x80|0x20000) != 0 {
		return nil, errMyProtocol
	}
	secure := tls.Server(raw, config)
	if secure.HandshakeContext(ctx) != nil {
		return nil, errMyProtocol
	}
	p, e := myRead(secure, &seq)
	if e != nil || len(p) < 34 || p[8] != 45 || binary.LittleEndian.Uint32(p) != flags {
		return nil, errMyProtocol
	}
	user, r, e := myCString(p[32:])
	if e != nil || !mySafeName(user) || len(r) < 1 {
		return nil, errMyProtocol
	}
	n := int(r[0])
	r = r[1:]
	if n < 2 || len(r) < n {
		return nil, errMyProtocol
	}
	password := string(r[:n-1])
	if r[n-1] != 0 || strings.ContainsRune(password, 0) {
		return nil, errMyProtocol
	}
	r = r[n:]
	db, r, e := myCString(r)
	if e != nil || db != database {
		return nil, errMyProtocol
	}
	plugin, r, e := myCString(r)
	if e != nil || plugin != "mysql_clear_password" || len(r) != 0 || !verify(user, password) {
		return nil, errMyProtocol
	}
	secure.SetDeadline(time.Time{})
	return secure, nil
}

// Text and prepared binary commands require both independent HTTP and
// kernel/process-bound local authorization remain required throughout.
func myServeSession(ctx context.Context, raw net.Conn, frontendTLS, backendTLS *tls.Config, client *http.Client, control, token, session, engine, database, target string, vault func(context.Context) (BrokerBackendCredential, error), local func(context.Context) bool) error {
	if raw == nil {
		return errMyProtocol
	}
	defer raw.Close()
	if ctx == nil || ctx.Err() != nil || local == nil || vault == nil || !local(ctx) {
		return errMyProtocol
	}
	front, e := myFrontend(ctx, raw, frontendTLS, database, func(user, password string) bool {
		allowed, e := verifyTemporaryCredential(ctx, client, control, token, session, user, password)
		return e == nil && allowed
	})
	if e != nil {
		return e
	}
	credentials, e := vault(ctx)
	if e != nil {
		return errMyProtocol
	}
	backend, e := myBackendConnect(target, backendTLS, credentials.Username, credentials.Password, database)
	credentials.Password = ""
	if e != nil {
		return errMyProtocol
	}
	defer backend.Close()
	if !checkBrokerEngineSessionActive(ctx, client, control, token, session, engine, local) {
		return errMyProtocol
	}
	stop := watchBrokerEngineSession(ctx, client, control, token, session, engine, front, backend, local)
	defer stop()
	seq := byte(3)
	if myWrite(front, &seq, []byte{0, 0, 0, 2, 0, 0, 0}) != nil {
		return errMyProtocol
	}
	statements := make(map[uint32]*myPreparedStatement)
	for {
		front.SetReadDeadline(time.Now().Add(30 * time.Second))
		seq = 0
		p, e := myRead(front, &seq)
		if e != nil {
			return e
		}
		if p[0] == 1 && len(p) == 1 {
			return nil
		}
		var statement *myPreparedStatement
		switch p[0] {
		case 3:
			if len(p) < 2 {
				return errMyProtocol
			}
		case 0x16:
			if len(p) < 2 || len(statements) >= myMaxStatements {
				return errMyProtocol
			}
		case 0x17, 0x19, 0x1a:
			id, ok := myStatementID(p)
			if !ok {
				return errMyProtocol
			}
			statement = statements[id]
			if statement == nil {
				return errMyProtocol
			}
			if p[0] == 0x17 {
				if !myValidateExecute(p, statement) {
					return errMyProtocol
				}
			} else if len(p) != 5 {
				return errMyProtocol
			}
		default:
			return errMyProtocol
		}
		if !checkBrokerEngineSessionActive(ctx, client, control, token, session, engine, local) {
			return errMyProtocol
		}
		backend.SetDeadline(time.Now().Add(30 * time.Second))
		front.SetWriteDeadline(time.Now().Add(30 * time.Second))
		seq = 0
		if myWrite(backend, &seq, p) != nil {
			return errMyProtocol
		}
		switch p[0] {
		case 3:
			if myRelayResult(backend, front) != nil {
				return errMyProtocol
			}
		case 0x16:
			prepared, e := myRelayPrepare(backend, front)
			if e != nil {
				return e
			}
			if prepared != nil {
				if statements[prepared.id] != nil {
					return errMyProtocol
				}
				statements[prepared.id] = prepared
			}
		case 0x17:
			if myRelayRows(backend, front, true) != nil {
				return errMyProtocol
			}
		case 0x19:
			delete(statements, statement.id) // CLOSE has no server response.
		case 0x1a:
			if myRelayStatementReset(backend, front) != nil {
				return errMyProtocol
			}
		}
	}
}
