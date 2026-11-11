package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/binary"
	"errors"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/jcmturner/gokrb5/v8/gssapi"
	"github.com/jcmturner/gokrb5/v8/spnego"
)

type tdsTestIntegratedAuth struct {
	initial, reply           []byte
	fail                     bool
	freed, started, verified bool
}

func (a *tdsTestIntegratedAuth) InitialBytes() ([]byte, error) {
	a.started = true
	return a.initial, nil
}
func (a *tdsTestIntegratedAuth) NextBytes(p []byte) ([]byte, error) {
	a.verified = bytes.Equal(p, a.reply)
	if a.fail || !a.verified {
		return nil, errors.New("authentication denied")
	}
	return nil, nil
}
func (a *tdsTestIntegratedAuth) Free() { a.freed = true }

func TestTDSIntegratedLoginHasNoSQLCredentials(t *testing.T) {
	token := []byte{0x60, 0x01, 0x02, 0xff}
	p, err := tdsIntegratedLogin("lab", token)
	if err != nil || p[25]&0x80 == 0 || binary.LittleEndian.Uint16(p[42:]) != 0 || binary.LittleEndian.Uint16(p[46:]) != 0 {
		t.Fatal("integrated LOGIN7 exposed SQL credential fields")
	}
	offset, n := int(binary.LittleEndian.Uint16(p[78:])), int(binary.LittleEndian.Uint16(p[80:]))
	if n != len(token) || !bytes.Equal(p[offset:offset+n], token) || int(binary.LittleEndian.Uint32(p)) != len(p) {
		t.Fatal("SSPI byte length or offset invalid")
	}
	if _, _, err := tdsParseLogin(p, "lab"); err == nil {
		t.Fatal("backend integrated login accepted as temporary frontend login")
	}
	for _, value := range [][]byte{nil, make([]byte, 32769)} {
		if _, err := tdsIntegratedLogin("lab", value); err == nil {
			t.Fatal("unbounded SSPI login accepted")
		}
	}
}

func TestTDSIntegratedBackendUsesVerifiedTLSAndValidatesSSPI(t *testing.T) {
	for _, scenario := range []string{"success", "untrusted_ca", "rejected_sspi"} {
		t.Run(scenario, func(t *testing.T) {
			cert := testBrokerCertificate(t)
			pool := x509.NewCertPool()
			parsed, _ := x509.ParseCertificate(cert.Certificate[0])
			if scenario != "untrusted_ca" {
				pool.AddCert(parsed)
			}
			listener, err := net.Listen("tcp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer listener.Close()
			serverDone := make(chan error, 1)
			go func() {
				raw, err := listener.Accept()
				if err != nil {
					serverDone <- err
					return
				}
				defer raw.Close()
				raw.SetDeadline(time.Now().Add(4 * time.Second))
				if _, err = tdsRead(raw, 18, 4096); err != nil {
					serverDone <- err
					return
				}
				if err = tdsWrite(raw, 4, tdsPrelogin(3)); err != nil {
					serverDone <- err
					return
				}
				transport := &tdsHandshakeConn{Conn: raw, wrapped: true, readKind: 18}
				secure := tls.Server(transport, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12, MaxVersion: tls.VersionTLS12})
				if err = secure.Handshake(); err != nil {
					serverDone <- err
					return
				}
				transport.wrapped = false
				p, err := tdsRead(secure, 16, 65536)
				if err != nil {
					serverDone <- err
					return
				}
				if p[25]&0x80 == 0 || binary.LittleEndian.Uint16(p[42:]) != 0 || binary.LittleEndian.Uint16(p[46:]) != 0 {
					serverDone <- errors.New("SQL fallback observed")
					return
				}
				serverDone <- tdsWrite(secure, 4, append([]byte{0xed, 3, 0, 1, 2, 3}, tdsLoginAck()...))
			}()
			auth := &tdsTestIntegratedAuth{initial: []byte{0x60, 1}, reply: []byte{1, 2, 3}, fail: scenario == "rejected_sspi"}
			ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			conn, err := tdsBackendConnectIntegrated(ctx, listener.Addr().String(), &tls.Config{RootCAs: pool, ServerName: "localhost"}, "lab", auth)
			if conn != nil {
				conn.Close()
			}
			serverErr := <-serverDone
			if (err == nil) != (scenario == "success") || !auth.freed {
				t.Fatal("integrated result or key cleanup mismatch")
			}
			if scenario == "untrusted_ca" && auth.started {
				t.Fatal("Kerberos token generated before target TLS verification")
			}
			if scenario != "untrusted_ca" && (!auth.verified || serverErr != nil) {
				t.Fatal("SSPI response not verified")
			}
		})
	}
}

func TestTDSIntegratedRepliesRejectMalformedOrRepeatedTokens(t *testing.T) {
	for _, p := range [][]byte{{0xed, 0, 0}, {0xed, 10, 0, 1}, {0xaa, 1, 0, 0},
		{0xed, 1, 0, 7, 0xed, 1, 0, 8}, {0xfd, 0, 0}, {0xee, 1, 0, 1}, {0xe3, 1, 0, 20}} {
		if _, _, err := tdsIntegratedReply(p); err == nil {
			t.Fatal("invalid integrated response accepted")
		}
	}
}

func TestKerberosProviderRejectsNTLMNegotiation(t *testing.T) {
	auth := &tdsKerberosAuth{}
	for index, mechanism := range [][]int{{1, 3, 6, 1, 4, 1, 311, 2, 2, 10}, gssapi.OIDKRB5.OID()} {
		token := spnego.SPNEGOToken{Resp: true, NegTokenResp: spnego.NegTokenResp{SupportedMech: mechanism}}
		data, err := token.Marshal()
		if err != nil {
			t.Fatal(err)
		}
		_, err = auth.NextBytes(data)
		if (err == nil) != (index == 1) {
			t.Fatal("unexpected SPNEGO mechanism accepted")
		}
	}
}

func TestKerberosKeytabBoundaryRejectsSharedFilesAndSymlinks(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "service.keytab")
	if err := os.WriteFile(file, []byte{1, 2, 3}, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := tdsKerberosFile(file, true, 16); err != nil {
		t.Fatal("private keytab unreadable")
	}
	if err := os.Chmod(file, 0640); err != nil {
		t.Fatal(err)
	}
	if _, err := tdsKerberosFile(file, true, 16); err == nil {
		t.Fatal("group-readable keytab accepted")
	}
	if err := os.Chmod(file, 0600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(dir, "link")
	if err := os.Symlink(file, link); err != nil {
		t.Fatal(err)
	}
	if _, err := tdsKerberosFile(link, true, 16); err == nil {
		t.Fatal("keytab symlink accepted")
	}
	if _, err := tdsKerberosFile(file, true, 2); err == nil {
		t.Fatal("oversized keytab accepted")
	}
}

func TestKerberosSPNMustMatchTargetAndRealmIsExplicit(t *testing.T) {
	profile := tdsKerberosProfile{Username: "gateway", Realm: "SAG.TEST", SPN: "MSSQLSvc/sql.sag.test:1433"}
	for _, address := range []string{"127.0.0.1:1433", "other.sag.test:1433", "sql.sag.test:1444", "sql:1433"} {
		if _, err := tdsNewKerberosAuth(address, profile); err == nil {
			t.Fatal("unbound SPN accepted")
		}
	}
	profile.Realm = ""
	if _, err := tdsNewKerberosAuth("sql.sag.test:1433", profile); err == nil {
		t.Fatal("environment realm fallback accepted")
	}
}
