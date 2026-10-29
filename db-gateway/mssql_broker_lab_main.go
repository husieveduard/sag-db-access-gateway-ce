//go:build ignore

package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"flag"
	"fmt"
	"net"
	"net/http"
	"os"
	"time"
)

func main() {
	supervised := flag.Bool("supervised", false, "require private launch control")
	clean := flag.Bool("managed-fds-ready", false, "internal descriptor boundary")
	engine := flag.String("engine", "mssql", "mssql or sqlserver")
	listen := flag.String("listen", "127.0.0.1:11433", "loopback listener")
	target := flag.String("target", "", "backend address")
	database := flag.String("database", "", "bound database")
	mode := flag.String("backend-tls", "verify-full", "verified backend TLS only")
	ca := flag.String("backend-ca", "", "trusted backend CA bundle")
	certPath := flag.String("cert", "", "frontend certificate")
	keyPath := flag.String("key", "", "frontend key")
	socket := flag.String("ipc-socket", "", "private worker socket")
	workerUID := flag.Uint("worker-uid", uint(os.Getuid()), "trusted worker UID")
	session := flag.String("session", "", "bound session")
	resource := flag.Int64("resource", 0, "bound resource")
	control := flag.String("control", "", "control plane")
	flag.Parse()
	if !*supervised || (*engine != "mssql" && *engine != "sqlserver") || *mode != "verify-full" || *target == "" || *resource <= 0 || *session == "" || *socket == "" || *control == "" || *workerUID > uint(^uint32(0)) {
		tdsFatal()
	}
	if !*clean {
		if brokerReexecManaged(os.Args[1:]) != nil {
			tdsFatal()
		}
	}
	cert, e := tls.LoadX509KeyPair(*certPath, *keyPath)
	if e != nil {
		tdsFatal()
	}
	roots := x509.NewCertPool()
	data, e := os.ReadFile(*ca)
	if e != nil || !roots.AppendCertsFromPEM(data) {
		tdsFatal()
	}
	backendTLS := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}
	launch, token, e := brokerReadManagedLaunch(os.Stdin, os.NewFile(3, "launcher-ready"), *engine, *session, *resource)
	if e != nil {
		tdsFatal()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	credentials, e := pgIPCTakeCredentials(ctx, *socket, *session, *resource, uint32(*workerUID))
	cancel()
	if e != nil {
		tdsFatal()
	}
	probe, e := tdsBackendConnect(ctx, *target, backendTLS, credentials.Username, credentials.Password, *database)
	if e != nil {
		tdsFatal()
	}
	probe.Close()
	listener, e := net.Listen("tcp", *listen)
	if e != nil {
		tdsFatal()
	}
	defer listener.Close()
	if launch.backendReady() != nil {
		tdsFatal()
	}
	for {
		conn, e := listener.Accept()
		if e != nil {
			tdsFatal()
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		local := pgIPCBoundSessionCheck(*socket, *session, *resource, uint32(*workerUID))
		e = tdsServeSession(ctx, conn, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, backendTLS, &http.Client{Timeout: 5 * time.Second}, *control, token, *session, *engine, *database, *target, func(ctx context.Context) (BrokerBackendCredential, error) {
			if !local(ctx) {
				return BrokerBackendCredential{}, errIPCUnavailable
			}
			return credentials, nil
		}, local)
		cancel()
		if e != nil {
			fmt.Fprintln(os.Stderr, "MSSQL_LAB_SESSION_FAILED")
		}
	}
}
func tdsFatal() { fmt.Fprintln(os.Stderr, "MSSQL_LAB_BROKER_FAILED"); os.Exit(1) }
