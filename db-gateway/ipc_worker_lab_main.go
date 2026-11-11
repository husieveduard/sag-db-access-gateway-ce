//go:build ignore

package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
)

// Explicit opt-in local Linux worker. Credentials are returned only to the process-bound bootstrap peer.
// Build with: go build -o sag-ipc-worker ipc_worker_lab_main.go
// postgres_session_ipc_server_linux.go postgres_session_ipc_laravel_linux.go
func main() {
	socket := flag.String("socket", "", "private Unix socket path")
	php := flag.String("php", "/usr/local/bin/php", "trusted PHP binary")
	artisan := flag.String("artisan", "/var/www/html/artisan", "trusted Laravel entrypoint")
	peerUID := flag.Uint("trusted-peer-uid", 0, "UID permitted to connect")
	flag.Parse()
	if *socket == "" || *peerUID == 0 || *peerUID > uint(^uint32(0)) {
		fmt.Fprintln(os.Stderr, "IPC_WORKER_CONFIG_INVALID")
		os.Exit(2)
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	if err := pgIPCServeAuthorization(ctx, *socket, uint32(*peerUID), pgIPCLaravelAuthorizer(*php, *artisan), pgIPCLaravelCredentials(*php, *artisan)); err != nil {
		fmt.Fprintln(os.Stderr, "IPC_WORKER_FAILED")
		os.Exit(1)
	}
}
