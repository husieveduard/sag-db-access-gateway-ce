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
	engine := flag.String("engine", "mysql", "mysql or mariadb")
	listen := flag.String("listen", "127.0.0.1:13306", "loopback listener")
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
	if !*supervised || (*engine != "mysql" && *engine != "mariadb") || *mode != "verify-full" || *target == "" || *resource <= 0 || *session == "" || *socket == "" || *control == "" || *workerUID > uint(^uint32(0)) {
		myFatal()
	}
	if !*clean {
		if brokerReexecManaged(os.Args[1:]) != nil {
			myFatal()
		}
	}
	cert, e := tls.LoadX509KeyPair(*certPath, *keyPath)
	if e != nil {
		myFatal()
	}
	roots := x509.NewCertPool()
	data, e := os.ReadFile(*ca)
	if e != nil || !roots.AppendCertsFromPEM(data) {
		myFatal()
	}
	backendTLS := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}
	launch, token, e := brokerReadManagedLaunch(os.Stdin, os.NewFile(3, "launcher-ready"), *engine, *session, *resource)
	if e != nil {
		myFatal()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	credentials, e := pgIPCTakeCredentials(ctx, *socket, *session, *resource, uint32(*workerUID))
	cancel()
	if e != nil {
		myFatal()
	}
	probe, e := myBackendConnect(*target, backendTLS, credentials.Username, credentials.Password, *database)
	if e != nil {
		myFatal()
	}
	probe.Close()
	listener, e := net.Listen("tcp", *listen)
	if e != nil {
		myFatal()
	}
	defer listener.Close()
	if launch.backendReady() != nil {
		myFatal()
	}
	local := pgIPCBoundSessionCheck(*socket, *session, *resource, uint32(*workerUID))
	lifetime, stopLifetime := brokerWatchManagedListener(context.Background(), listener, func(ctx context.Context) bool {
		return checkBrokerEngineSessionActive(ctx, &http.Client{Timeout: 3 * time.Second}, *control, token, *session, *engine, local)
	})
	defer stopLifetime()
	for {
		conn, e := listener.Accept()
		if e != nil {
			if lifetime.Err() != nil {
				return
			}
			myFatal()
		}
		ctx, cancel := context.WithTimeout(lifetime, 5*time.Minute)
		stopConn := context.AfterFunc(ctx, func() { _ = conn.Close() })
		e = myServeSession(ctx, conn, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}, backendTLS, &http.Client{Timeout: 5 * time.Second}, *control, token, *session, *engine, *database, *target, func(ctx context.Context) (BrokerBackendCredential, error) {
			if !local(ctx) {
				return BrokerBackendCredential{}, errIPCUnavailable
			}
			return credentials, nil
		}, local)
		stopConn()
		cancel()
		if e != nil {
			fmt.Fprintln(os.Stderr, "MYSQL_LAB_SESSION_FAILED")
		}
	}
}
func myFatal() { fmt.Fprintln(os.Stderr, "MYSQL_LAB_BROKER_FAILED"); os.Exit(1) }
