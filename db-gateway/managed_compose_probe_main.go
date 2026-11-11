//go:build ignore

// CLI probe for disposable Compose acceptance. Passwords enter only on stdin.
package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	mysql "github.com/go-sql-driver/mysql"
	mssql "github.com/microsoft/go-mssqldb"
	"github.com/microsoft/go-mssqldb/msdsn"
)

func main() {
	engine := flag.String("engine", "", "mysql, mariadb, or mssql")
	address := flag.String("address", "", "broker address")
	database := flag.String("database", "", "database")
	username := flag.String("username", "", "temporary username")
	caPath := flag.String("ca", "", "frontend CA")
	tlsName := flag.String("tls-name", "localhost", "verified server name")
	provision := flag.Bool("provision", false, "create disposable database and backend user")
	countBackend := flag.Bool("count-backend", false, "require no remaining backend sessions")
	hold := flag.Bool("hold-for-revoke", false, "keep querying until queued revocation closes the connection")
	flag.Parse()
	passwordBytes, err := io.ReadAll(io.LimitReader(os.Stdin, 4097))
	if err != nil || len(passwordBytes) == 0 || len(passwordBytes) > 4096 {
		fatal("temporary credential unavailable")
	}
	password := strings.TrimSpace(string(passwordBytes))
	var backendPassword string
	if *provision {
		var input struct {
			Admin   string `json:"admin"`
			Backend string `json:"backend"`
		}
		if json.Unmarshal(passwordBytes, &input) != nil || !regexp.MustCompile(`^[0-9a-f]{48}$`).MatchString(input.Backend) {
			fatal("provisioning input invalid")
		}
		password, backendPassword = input.Admin, input.Backend
	}
	ca, err := os.ReadFile(*caPath)
	if err != nil {
		fatal("frontend CA unavailable")
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(ca) {
		fatal("frontend CA invalid")
	}
	tlsConfig := &tls.Config{
		RootCAs: roots, ServerName: *tlsName, MinVersion: tls.VersionTLS12,
	}
	var db *sql.DB
	switch *engine {
	case "mysql", "mariadb":
		name := "sag-compose-acceptance"
		if err := mysql.RegisterTLSConfig(name, tlsConfig); err != nil {
			fatal("MySQL TLS configuration failed")
		}
		cfg := mysql.NewConfig()
		cfg.User = *username
		cfg.Passwd = password
		cfg.Net = "tcp"
		cfg.Addr = *address
		cfg.DBName = *database
		cfg.TLSConfig = name
		cfg.AllowCleartextPasswords = true
		cfg.Timeout = 5 * time.Second
		cfg.ReadTimeout = 10 * time.Second
		cfg.WriteTimeout = 10 * time.Second
		db, err = sql.Open("mysql", cfg.FormatDSN())
	case "mssql":
		host, portText, splitErr := net.SplitHostPort(*address)
		port, portErr := strconv.Atoi(portText)
		if splitErr != nil || portErr != nil {
			fatal("MSSQL address invalid")
		}
		connector := mssql.NewConnectorConfig(msdsn.Config{
			Host: host, Port: uint64(port), User: *username, Password: password,
			Database: *database, Encryption: msdsn.EncryptionRequired,
			TLSConfig: tlsConfig, PacketSize: 4096, DisableRetry: true,
			DialTimeout: 5 * time.Second, ConnTimeout: 10 * time.Second,
			Protocols: []string{"tcp"}, NoTraceID: true,
		})
		db = sql.OpenDB(connector)
	default:
		fatal("unsupported engine")
	}
	if err != nil {
		fatal("client initialization failed")
	}
	defer db.Close()
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(1)
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	if *provision {
		statements := []string{
			"CREATE DATABASE sag_compose_e2e",
			"CREATE USER 'sag_compose_backend'@'%' IDENTIFIED BY '" + backendPassword + "' REQUIRE SSL",
			"GRANT SELECT ON sag_compose_e2e.* TO 'sag_compose_backend'@'%'",
		}
		if *engine == "mssql" {
			statements = []string{
				"CREATE DATABASE sag_compose_e2e",
				"CREATE LOGIN sag_compose_backend WITH PASSWORD='" + backendPassword + "', CHECK_POLICY=OFF",
				"USE sag_compose_e2e; CREATE USER sag_compose_backend FOR LOGIN sag_compose_backend; ALTER ROLE db_datareader ADD MEMBER sag_compose_backend",
			}
		}
		for _, statement := range statements {
			if _, err := db.ExecContext(ctx, statement); err != nil {
				fatal("disposable provisioning failed")
			}
		}
		fmt.Println("backend_provisioned")
		return
	}
	if *countBackend {
		query := "SELECT count(*) FROM information_schema.processlist WHERE USER='sag_compose_backend'"
		if *engine == "mssql" {
			query = "SELECT count(*) FROM sys.dm_exec_sessions WHERE login_name='sag_compose_backend'"
		}
		var count int
		if db.QueryRowContext(ctx, query).Scan(&count) != nil || count != 0 {
			fatal("backend connection remained")
		}
		fmt.Println("backend_connections=0")
		return
	}
	var value int
	if err := db.QueryRowContext(ctx, "SELECT 1").Scan(&value); err != nil {
		fatal("SELECT 1 failed: " + strings.ReplaceAll(err.Error(), password, "[redacted]"))
	}
	if value != 1 {
		fatal("SELECT 1 returned an unexpected value")
	}
	fmt.Println("SELECT 1 = 1")
	if *hold {
		// The pool retains the authenticated connection after SELECT 1.
		for ctx.Err() == nil {
			time.Sleep(200 * time.Millisecond)
			if db.QueryRowContext(ctx, "SELECT 1").Scan(&value) != nil {
				fmt.Println("revoked_connection_closed")
				return
			}
		}
		fatal("revoked connection remained usable")
	}
}

func fatal(message string) {
	fmt.Fprintln(os.Stderr, message)
	os.Exit(1)
}
