//go:build linux

package main

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"time"
)

// Authorization output is non-secret and must remain bounded even if PHP
// accidentally emits diagnostics. Continue draining until the command deadline.
type pgIPCAuthorizationOutput struct {
	data     []byte
	overflow bool
}

func (w *pgIPCAuthorizationOutput) Write(p []byte) (int, error) {
	n := len(p)
	remaining := 128 - len(w.data)
	if len(p) > remaining {
		w.overflow = true
		p = p[:remaining]
	}
	w.data = append(w.data, p...)
	return n, nil
}

// pgIPCLaravelAuthorizer invokes only the non-secret local Laravel
// session/resource gate. No shell, environment secrets, or Vault data.
// The worker image must contain PHP, artisan and its private DB access.
// Never use user-controlled executable or artisan paths.
func pgIPCLaravelAuthorizer(phpBinary, artisanPath string) pgIPCSessionAuthorize {
	return func(ctx context.Context, sessionUID string, resourceID int64) bool {
		if ctx == nil || ctx.Err() != nil || phpBinary == "" || artisanPath == "" || resourceID <= 0 ||
			len(sessionUID) == 0 || len(sessionUID) > 128 || strings.ContainsAny(sessionUID, "\x00\r\n") {
			return false
		}
		checkCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
		defer cancel()
		args := []string{artisanPath, "sag:verify-gateway-ipc-session", sessionUID, strconv.FormatInt(resourceID, 10), "--no-ansi", "--no-interaction"}
		// Every Unix request carries the kernel-derived process identity.
		peer, valid := ctx.Value(pgIPCPeerIdentityKey{}).(pgIPCPeerIdentity)
		if !valid || peer.PID < 2 || peer.StartTicks == "" {
			return false
		}
		args = append(args, "--peer-pid="+strconv.FormatInt(int64(peer.PID), 10), "--peer-start-ticks="+peer.StartTicks)
		if consume, ok := ctx.Value(pgIPCBootstrapConsumeKey{}).(bool); ok && consume {
			args = append(args, "--consume-bootstrap")
		} else {
			args = append(args, "--active-peer")
		}
		cmd := exec.CommandContext(checkCtx, phpBinary, args...)
		output := &pgIPCAuthorizationOutput{}
		cmd.Stdout = output
		cmd.WaitDelay = 250 * time.Millisecond
		err := cmd.Run()
		if err != nil || checkCtx.Err() != nil || output.overflow {
			return false
		}
		return strings.TrimSpace(string(output.data)) == "ALLOW"
	}
}

var errIPCLocalWorkerRequired = errors.New("IPC authorization requires local trusted Laravel worker")

// Credentials travel through an inherited anonymous pipe (FD 3), never stdout,
// stderr, argv, environment variables, HTTP, or a persistent secret file.
func pgIPCLaravelCredentials(phpBinary, artisanPath string) pgIPCCredentialProvider {
	return func(ctx context.Context, sessionUID string, resourceID int64) (pgIPCCredential, error) {
		var credential pgIPCCredential
		if ctx == nil || ctx.Err() != nil || phpBinary == "" || artisanPath == "" {
			return credential, errIPCLocalWorkerRequired
		}
		peer, ok := ctx.Value(pgIPCPeerIdentityKey{}).(pgIPCPeerIdentity)
		if !ok || peer.PID < 2 || peer.StartTicks == "" || sessionUID == "" || len(sessionUID) > 128 || resourceID <= 0 {
			return credential, errIPCLocalWorkerRequired
		}
		checkCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
		defer cancel()
		reader, writer, err := os.Pipe()
		if err != nil {
			return credential, errIPCLocalWorkerRequired
		}
		defer reader.Close()
		defer writer.Close()
		cmd := exec.CommandContext(checkCtx, phpBinary, artisanPath, "sag:take-gateway-ipc-credentials", sessionUID, strconv.FormatInt(resourceID, 10),
			"--peer-pid="+strconv.FormatInt(int64(peer.PID), 10), "--peer-start-ticks="+peer.StartTicks, "--no-ansi", "--no-interaction")
		cmd.ExtraFiles = []*os.File{writer}
		cmd.WaitDelay = 250 * time.Millisecond
		if cmd.Start() != nil {
			return credential, errIPCLocalWorkerRequired
		}
		writer.Close()
		// Close the reader on cancellation too, including if a child retains FD 3.
		done := make(chan struct{})
		go func() {
			select {
			case <-checkCtx.Done():
				reader.Close()
			case <-done:
			}
		}()
		body, readErr := io.ReadAll(io.LimitReader(reader, 8193))
		close(done)
		waitErr := cmd.Wait()
		if readErr != nil || waitErr != nil || checkCtx.Err() != nil || len(body) > 8192 || json.Unmarshal(body, &credential) != nil || credential.Username == "" || credential.Password == "" {
			return pgIPCCredential{}, errIPCLocalWorkerRequired
		}
		return credential, nil
	}
}
