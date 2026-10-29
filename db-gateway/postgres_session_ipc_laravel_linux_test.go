//go:build linux

package main

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestPGIPCLaravelAuthorizerAllowDeny(t *testing.T) {
	dir := t.TempDir()
	stub := filepath.Join(dir, "fake-php")
	script := "#!/bin/sh\nif [ \"$2\" = \"sag:verify-gateway-ipc-session\" ] && [ \"$3\" = \"SAG-TEST\" ] && [ \"$4\" = \"7\" ]; then printf 'ALLOW\\n'; exit 0; fi\nprintf 'DENY\\n'; exit 1\n"
	if err := os.WriteFile(stub, []byte(script), 0700); err != nil {
		t.Fatal(err)
	}
	authorize := pgIPCLaravelAuthorizer(stub, "/trusted/artisan")
	peer := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 12345, StartTicks: "987654321"})
	if !authorize(peer, "SAG-TEST", 7) {
		t.Fatal("expected allow")
	}
	if authorize(peer, "SAG-TEST", 8) {
		t.Fatal("wrong resource allowed")
	}
	if authorize(peer, "OTHER", 7) {
		t.Fatal("wrong session allowed")
	}
	if authorize(peer, "SAG-TEST", 0) {
		t.Fatal("invalid resource allowed")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if authorize(ctx, "SAG-TEST", 7) {
		t.Fatal("cancelled context allowed")
	}
}

func TestPGIPCLaravelAuthorizerFailsClosed(t *testing.T) {
	if pgIPCLaravelAuthorizer("/nonexistent/php", "/artisan")(context.Background(), "SAG-TEST", 7) {
		t.Fatal("missing interpreter allowed")
	}
	if pgIPCLaravelAuthorizer("", "")(context.Background(), "SAG-TEST", 7) {
		t.Fatal("missing config allowed")
	}
}

func TestPGIPCLaravelAuthorizationDoesNotConsumeOnReadOnly(t *testing.T) {
	dir := t.TempDir()
	stub := filepath.Join(dir, "fake-php")
	script := "#!/bin/sh\ncase \"$*\" in\n  *--consume-bootstrap*) printf 'ALLOW\\n'; exit 0 ;;\n  *--peer-pid=*) printf 'DENY\\n'; exit 1 ;;\n  *) printf 'DENY\\n'; exit 1 ;;\nesac\n"
	if err := os.WriteFile(stub, []byte(script), 0700); err != nil {
		t.Fatal(err)
	}
	authorize := pgIPCLaravelAuthorizer(stub, "/trusted/artisan")
	peer := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 12345, StartTicks: "987654321"})
	if authorize(peer, "SAG-TEST", 7) {
		t.Fatal("denied read-only call was allowed")
	}
	bootstrap := context.WithValue(peer, pgIPCBootstrapConsumeKey{}, true)
	if !authorize(bootstrap, "SAG-TEST", 7) {
		t.Fatal("explicit consume was not forwarded")
	}
}

func TestPGIPCLiveAuthorizationAlwaysPassesKernelIdentity(t *testing.T) {
	dir := t.TempDir()
	stub := filepath.Join(dir, "php")
	script := "#!/bin/sh\ncase \"$*\" in\n *--consume-bootstrap*) exit 1 ;;\n *--peer-pid=12345*--peer-start-ticks=987654321*--active-peer*) echo ALLOW ;;\n *) exit 1 ;;\nesac\n"
	if os.WriteFile(stub, []byte(script), 0700) != nil {
		t.Fatal("stub setup failed")
	}
	authorize := pgIPCLaravelAuthorizer(stub, "/trusted/artisan")
	peer := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 12345, StartTicks: "987654321"})
	if !authorize(peer, "session", 7) {
		t.Fatal("live process identity missing")
	}
	if authorize(context.Background(), "session", 7) {
		t.Fatal("missing peer identity allowed")
	}
}

func TestIPCAuthorizationOutputFloodIsBoundedAndDenied(t *testing.T) {
	stub := filepath.Join(t.TempDir(), "php")
	if os.WriteFile(stub, []byte("#!/bin/sh\nhead -c 1048576 /dev/zero\necho ALLOW\n"), 0700) != nil {
		t.Fatal("stub setup failed")
	}
	peer := context.WithValue(context.Background(), pgIPCPeerIdentityKey{}, pgIPCPeerIdentity{PID: 12345, StartTicks: "456"})
	if pgIPCLaravelAuthorizer(stub, "/trusted/artisan")(peer, "session", 7) {
		t.Fatal("oversized authorization output allowed")
	}
}
