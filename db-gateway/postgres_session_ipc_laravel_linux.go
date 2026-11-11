//go:build linux

package main

import (
	"context"
	"encoding/json"
	"encoding/base64"
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

		args := []string{artisanPath, "sag:take-gateway-ipc-credentials", sessionUID, strconv.FormatInt(resourceID, 10),
			"--peer-pid="+strconv.FormatInt(int64(peer.PID), 10), "--peer-start-ticks="+peer.StartTicks, "--no-ansi", "--no-interaction"}
		kerberos, _ := ctx.Value(pgIPCKerberosBootstrapKey{}).(bool)
		limit := int64(8192)
		if kerberos {
			args = append(args, "--kerberos")
			limit = 16384
		}
		cmd := exec.CommandContext(checkCtx, phpBinary, args...)
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
		body, readErr := io.ReadAll(io.LimitReader(reader, limit+1))
		close(done)
		waitErr := cmd.Wait()
		if readErr != nil || waitErr != nil || checkCtx.Err() != nil || int64(len(body)) > limit {
			return pgIPCCredential{}, errIPCLocalWorkerRequired
		}
		if kerberos {
			var material map[string]string
			if len(body) > 12001 || json.Unmarshal(body, &material) != nil || len(material) != 7 ||
				material["kind"] != "kerberos_keytab" || material["profile_id"] == "" ||
				material["username"] == "" || material["realm"] == "" || material["spn"] == "" {
				return pgIPCCredential{}, errIPCLocalWorkerRequired
			}
			for _, field := range []string{"config_b64", "keytab_b64"} {
				decoded, err := base64.StdEncoding.Strict().DecodeString(material[field])
				if err != nil || len(decoded) == 0 || len(decoded) > 4096 {
					return pgIPCCredential{}, errIPCLocalWorkerRequired
				}
			}
			return pgIPCCredential{Kerberos: json.RawMessage(body)}, nil
		}
		if json.Unmarshal(body, &credential) != nil || credential.Username == "" || credential.Password == "" || len(credential.Kerberos) != 0 {
			return pgIPCCredential{}, errIPCLocalWorkerRequired
		}
		return credential, nil
	}
}
