//go:build ignore

// Isolated native TDS/Kerberos acceptance probe. This does not enable the
// application's Windows backend mode or read SQL credentials from the Vault.
package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/binary"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"strings"
	"time"
)

func main() {
	mode := flag.String("mode", "kerberos", "kerberos, ticket or disposable-provision")
	address := flag.String("target", "sql.sag.test:1433", "pinned SQL endpoint")
	caFile := flag.String("ca", "", "target TLS CA")
	keytab := flag.String("keytab", "", "private runtime keytab")
	configFile := flag.String("config", "", "trusted krb5 configuration")
	spn := flag.String("spn", "MSSQLSvc/sql.sag.test:1433", "pinned SPN")
	flag.Parse()
	roots := x509.NewCertPool()
	data, err := os.ReadFile(*caFile)
	if err != nil || !roots.AppendCertsFromPEM(data) {
		kerberosProbeFail("target CA unavailable")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	var conn net.Conn
	if *mode == "disposable-provision" || *mode == "sql-ready" {
		password, e := io.ReadAll(io.LimitReader(os.Stdin, 4097))
		if e != nil || len(password) == 0 || len(password) > 4096 {
			kerberosProbeFail("admin stdin unavailable")
		}
		conn, err = tdsBackendConnect(ctx, *address, &tls.Config{RootCAs: roots}, "sa", strings.TrimSpace(string(password)), "master")
		if err != nil {
			kerberosProbeFail("SQL Server not ready")
		}
		defer conn.Close()
		if *mode == "sql-ready" {
			fmt.Println("sql_server_ready")
			return
		}
		for _, statement := range []string{"CREATE DATABASE sag_kerberos_e2e",
			"CREATE LOGIN [SAG\\gateway] FROM WINDOWS",
			"USE sag_kerberos_e2e; CREATE USER [SAG\\gateway] FOR LOGIN [SAG\\gateway]; ALTER ROLE db_datareader ADD MEMBER [SAG\\gateway]"} {
			if kerberosProbeQuery(conn, statement, false) != nil {
				kerberosProbeFail("AD principal provisioning failed")
			}
		}
		fmt.Println("isolated_windows_login_provisioned")
		return
	}
	if *mode != "kerberos" && *mode != "ticket" {
		kerberosProbeFail("unknown authentication mode")
	}
	auth, err := tdsNewKerberosAuth(*address, tdsKerberosProfile{Username: "gateway", Realm: "SAG.TEST", SPN: *spn, ConfigFile: *configFile, KeytabFile: *keytab})
	if err != nil {
		kerberosProbeFail("Kerberos profile denied")
	}
	if *mode == "ticket" {
		defer auth.Free()
		token, err := auth.InitialBytes()
		if err != nil || len(token) == 0 {
			kerberosProbeFail("Kerberos service ticket denied")
		}
		fmt.Println("kerberos_service_ticket_issued")
		return
	}
	conn, err = tdsBackendConnectIntegrated(ctx, *address, &tls.Config{RootCAs: roots}, "sag_kerberos_e2e", auth)
	if err != nil {
		kerberosProbeFail("Kerberos backend login denied")
	}
	defer conn.Close()
	if kerberosProbeQuery(conn, "IF ORIGINAL_LOGIN() <> N'SAG\\gateway' OR SUSER_SNAME() <> N'SAG\\gateway' THROW 51000, 'unexpected backend identity', 1; SELECT 1", true) != nil {
		kerberosProbeFail("Kerberos SQL identity or SELECT 1 failed")
	}
	fmt.Println("kerberos_principal=SAG\\gateway; SELECT 1 = 1")
}

func kerberosProbeQuery(conn net.Conn, statement string, expectOne bool) error {
	conn.SetDeadline(time.Now().Add(10 * time.Second))
	payload := make([]byte, 22)
	binary.LittleEndian.PutUint32(payload, 22)
	binary.LittleEndian.PutUint32(payload[4:], 18)
	binary.LittleEndian.PutUint16(payload[8:], 2)
	binary.LittleEndian.PutUint32(payload[18:], 1)
	if tdsWrite(conn, 1, append(payload, tdsUTF16(statement)...)) != nil {
		return errTDSProtocol
	}
	reply, err := tdsRead(conn, 4, 65536)
	if err != nil || len(reply) < 13 || reply[len(reply)-13] != 0xfd || binary.LittleEndian.Uint16(reply[len(reply)-12:])&0x103 != 0 {
		return errTDSProtocol
	}
	if expectOne && !bytes.Contains(reply, []byte{0xd1, 1, 0, 0, 0}) {
		return errTDSProtocol
	}
	return nil
}

func kerberosProbeFail(message string) { fmt.Fprintln(os.Stderr, message); os.Exit(1) }
