package main

import (
	"bytes"
	"io"
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
	"syscall"

	"github.com/jcmturner/gokrb5/v8/client"
	"github.com/jcmturner/gokrb5/v8/config"
	"github.com/jcmturner/gokrb5/v8/gssapi"
	"github.com/jcmturner/gokrb5/v8/keytab"
	"github.com/jcmturner/gokrb5/v8/spnego"
)

// Runtime-owned profile references only. AD secrets never come from a DSN,
// HTTP response, environment fallback, SQL Vault password or command argument.
type tdsKerberosProfile struct {
	Username, Realm, SPN, ConfigFile, KeytabFile string
}

type tdsKerberosAuth struct {
	client *client.Client
	spn    string
	keytabData []byte
}

func tdsNewKerberosAuth(address string, profile tdsKerberosProfile) (tdsIntegratedAuthenticator, error) {
	confBytes, err := tdsKerberosFile(profile.ConfigFile, false, 65536)
	if err != nil {
		return nil, errTDSProtocol
	}
	data, err := tdsKerberosFile(profile.KeytabFile, true, 1<<20)
	if err != nil {
		return nil, errTDSProtocol
	}
	defer clear(data)
	return tdsNewKerberosAuthBytes(address, profile.Username, profile.Realm, profile.SPN, confBytes, data)
}

// In-memory entry point for the process-bound IPC grant; no filesystem fallback.
func tdsNewKerberosAuthBytes(address, username, realmName, spn string, confBytes, data []byte) (tdsIntegratedAuthenticator, error) {
	host, port, err := net.SplitHostPort(address)
	portNumber, portErr := strconv.Atoi(port)
	if err != nil || portErr != nil || portNumber < 1 || portNumber > 65535 || net.ParseIP(host) != nil || !strings.Contains(host, ".") ||
		spn != "MSSQLSvc/"+net.JoinHostPort(strings.ToLower(host), port) ||
		!regexp.MustCompile(`^[A-Za-z0-9_.\-$]{1,128}$`).MatchString(username) ||
		!regexp.MustCompile(`^[A-Z0-9][A-Z0-9.\-]{1,127}$`).MatchString(realmName) ||
		len(confBytes) == 0 || len(confBytes) > 65536 || len(data) == 0 || len(data) > 1<<20 {
		return nil, errTDSProtocol
	}
	cfg, err := config.NewFromReader(bytes.NewReader(confBytes))
	if err != nil {
		return nil, errTDSProtocol
	}
	// Use the pinned SPN and administrator-supplied realm/KDC mapping exactly.
	// No NTLM, DNS canonicalization, credential-cache or SQL password fallback.
	cfg.LibDefaults.DNSLookupKDC = false
	cfg.LibDefaults.DNSLookupRealm = false
	cfg.LibDefaults.DNSCanonicalizeHostname = false
	cfg.LibDefaults.RDNS = false
	cfg.LibDefaults.Canonicalize = false
	cfg.LibDefaults.Forwardable = false
	cfg.LibDefaults.Proxiable = false
	cfg.LibDefaults.UDPPreferenceLimit = 1
	cfg.LibDefaults.AllowWeakCrypto = false
	cfg.LibDefaults.DefaultTktEnctypeIDs = []int32{18, 17}
	cfg.LibDefaults.DefaultTGSEnctypeIDs = []int32{18, 17}
	cfg.LibDefaults.PermittedEnctypeIDs = []int32{18, 17}
	found := false
	for _, realm := range cfg.Realms {
		if realm.Realm == realmName && len(realm.KDC) > 0 {
			found = true
		}
	}
	if !found {
		return nil, errTDSProtocol
	}
	owned := append([]byte(nil), data...)
	kt := &keytab.Keytab{}
	if kt.Unmarshal(owned) != nil {
		clear(owned)
		return nil, errTDSProtocol
	}
	cl := client.NewWithKeytab(username, realmName, kt, cfg, client.DisablePAFXFAST(true))
	return &tdsKerberosAuth{client: cl, spn: spn, keytabData: owned}, nil
}

func tdsKerberosFile(path string, secret bool, limit int64) ([]byte, error) {
	file, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, errTDSProtocol
	}
	defer file.Close()
	stat, err := file.Stat()
	if err != nil || !stat.Mode().IsRegular() || stat.Size() <= 0 || stat.Size() > limit || stat.Mode().Perm()&0022 != 0 {
		return nil, errTDSProtocol
	}
	owner, ok := stat.Sys().(*syscall.Stat_t)
	if !ok || (owner.Uid != uint32(os.Geteuid()) && owner.Uid != 0) || (secret && (stat.Mode().Perm()&0077 != 0 || owner.Uid != uint32(os.Geteuid()))) {
		return nil, errTDSProtocol
	}
	data, err := io.ReadAll(io.LimitReader(file, limit+1))
	if err != nil || int64(len(data)) > limit {
		return nil, errTDSProtocol
	}
	return data, nil
}

func (auth *tdsKerberosAuth) InitialBytes() ([]byte, error) {
	if auth.client == nil || auth.client.Login() != nil {
		return nil, errTDSProtocol
	}
	context := spnego.SPNEGOClient(auth.client, auth.spn)
	token, err := context.InitSecContext()
	if err != nil {
		return nil, errTDSProtocol
	}
	data, err := token.Marshal()
	if err != nil {
		return nil, errTDSProtocol
	}
	return data, nil
}

func (auth *tdsKerberosAuth) NextBytes(data []byte) ([]byte, error) {
	var token spnego.SPNEGOToken
	if token.Unmarshal(data) != nil {
		return nil, errTDSProtocol
	}
	if !token.Resp || token.Init ||
		(len(token.NegTokenResp.SupportedMech) > 0 &&
			!token.NegTokenResp.SupportedMech.Equal(gssapi.OIDKRB5.OID()) &&
			!token.NegTokenResp.SupportedMech.Equal(gssapi.OIDMSLegacyKRB5.OID())) {
		return nil, errTDSProtocol
	}
	ok, status := token.Verify()
	if !ok && status.Code != gssapi.StatusContinueNeeded {
		return nil, errTDSProtocol
	}
	return nil, nil
}

func (auth *tdsKerberosAuth) Free() {
	if auth.client != nil {
		auth.client.Destroy()
		auth.client = nil
	}
	clear(auth.keytabData)
	auth.keytabData = nil
}
