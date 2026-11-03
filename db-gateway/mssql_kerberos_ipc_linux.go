package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
)

// Server-side memory only. This object must never be logged or returned to HTTP.
type tdsKerberosMaterial struct {
	Kind      string `json:"kind"`
	ProfileID string `json:"profile_id"`
	Username  string `json:"username"`
	Realm     string `json:"realm"`
	SPN       string `json:"spn"`
	ConfigB64 string `json:"config_b64"`
	KeytabB64 string `json:"keytab_b64"`
}

func (material tdsKerberosMaterial) decoded() (config, keytab []byte, err error) {
	if material.Kind != "kerberos_keytab" || len(material.ProfileID) == 0 || len(material.ProfileID) > 64 ||
		len(material.Username) == 0 || len(material.Username) > 128 || len(material.Realm) == 0 || len(material.Realm) > 128 ||
		len(material.SPN) == 0 || len(material.SPN) > 512 || len(material.ConfigB64) > 5464 || len(material.KeytabB64) > 5464 {
		return nil, nil, errIPCUnavailable
	}
	config, err = base64.StdEncoding.Strict().DecodeString(material.ConfigB64)
	if err != nil || len(config) == 0 || len(config) > 4096 {
		return nil, nil, errIPCUnavailable
	}
	keytab, err = base64.StdEncoding.Strict().DecodeString(material.KeytabB64)
	if err != nil || len(keytab) == 0 || len(keytab) > 4096 {
		return nil, nil, errIPCUnavailable
	}
	return config, keytab, nil
}

func pgIPCTakeKerberos(ctx context.Context, socket, session string, resource int64, workerUID uint32) (tdsKerberosMaterial, error) {
	var empty tdsKerberosMaterial
	body, err := pgIPCBootstrapExchange(ctx, socket, session, resource, workerUID, "bootstrap_kerberos")
	if err != nil {
		return empty, errIPCUnavailable
	}
	var reply struct {
		Username string              `json:"username"`
		Password string              `json:"password"`
		Kerberos tdsKerberosMaterial `json:"kerberos"`
	}
	if json.Unmarshal(body, &reply) != nil || reply.Username != "" || reply.Password != "" {
		return empty, errIPCUnavailable
	}
	if _, _, err := reply.Kerberos.decoded(); err != nil {
		return empty, errIPCUnavailable
	}
	return reply.Kerberos, nil
}
