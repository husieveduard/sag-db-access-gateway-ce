//go:build ignore

package main

import (
	"context"
	"crypto/tls"
	"errors"
	"flag"
	"fmt"
	"net"
	"net/http"
	"os"
	"strings"
	"time"
)

// Explicit laboratory runner. Backend credentials come only from the private
// process-bound, one-time Laravel Vault IPC bootstrap.
// Binds localhost by default and permits only one concurrent connection.
func main() {
	listen := flag.String("listen", "127.0.0.1:15432", "laboratory bind address")
	target := flag.String("target", "sag-ce-lab-postgres:5432", "backend address")
	mode := flag.String("backend-tls", "disable", "backend transport")
	db := flag.String("database", "sag_ce_lab", "allowed database")
	socket := flag.String("ipc-socket", "", "private credential worker Unix socket")
	resource := flag.Int64("resource", 0, "bound resource ID")
	workerUID := flag.Uint("worker-uid", uint(os.Getuid()), "trusted worker UID")
	certFile := flag.String("cert", "", "frontend TLS certificate")
	keyFile := flag.String("key", "", "frontend TLS private key")
	control := flag.String("control", "", "control plane URL")
	tokenFile := flag.String("token-file", "", "internal API token file")
	session := flag.String("session", "", "temporary access session UID")
	flag.Parse()
	if *socket == "" || *resource <= 0 || *workerUID > uint(^uint32(0)) || *certFile == "" || *keyFile == "" || *tokenFile == "" || *session == "" || *control == "" {
		fatal(errors.New("missing required lab configuration"))
	}
	if *mode != "disable" {
		fatal(errors.New("this lab runner supports only explicitly selected disable backend TLS mode"))
	}
	cert, err := tls.LoadX509KeyPair(*certFile, *keyFile)
	if err != nil {
		fatal(err)
	}
	readSecret := func(path string) (string, error) {
		data, e := os.ReadFile(path)
		if e != nil {
			return "", e
		}
		if len(data) == 0 || len(data) > 4096 {
			return "", errors.New("invalid secret file size")
		}
		return strings.TrimSuffix(strings.TrimSuffix(string(data), "\n"), "\r"), nil
	}
	token, err := readSecret(*tokenFile)
	if err != nil {
		fatal(err)
	}
	if token == "" {
		fatal(errors.New("empty internal token"))
	}
	bootstrapCtx, bootstrapCancel := context.WithTimeout(context.Background(), 4*time.Second)
	credentials, err := pgIPCTakeCredentials(bootstrapCtx, *socket, *session, *resource, uint32(*workerUID))
	bootstrapCancel()
	if err != nil {
		fatal(errors.New("bootstrap denied"))
	}
	listener, err := net.Listen("tcp", *listen)
	if err != nil {
		fatal(err)
	}
	defer listener.Close()
	fmt.Printf("LAB_BROKER_LISTEN=%s\n", listener.Addr())
	for {
		conn, e := listener.Accept()
		if e != nil {
			fatal(e)
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		e = pgBrokerServeAuthenticatedSession(ctx, conn, &tls.Config{
			Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12,
		}, &http.Client{Timeout: 5 * time.Second}, *control, token, *session, *db, *target, *mode, nil,
			func(context.Context) (PgBrokerBackendCredential, error) {
				if !pgIPCCheckSession(context.Background(), *socket, *session, *resource) {
					return PgBrokerBackendCredential{}, errors.New("session denied")
				}
				return credentials, nil
			})
		cancel()
		if e != nil {
			fmt.Fprintln(os.Stderr, "LAB_SESSION_FAILED")
		} else {
			fmt.Println("LAB_SESSION_ENDED")
		}
	}
}
func fatal(err error) { fmt.Fprintln(os.Stderr, "LAB_BROKER_ERROR"); os.Exit(1) }
