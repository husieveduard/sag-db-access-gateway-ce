package main

import "encoding/base64"

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
		return nil, nil, errTDSProtocol
	}
	config, err = base64.StdEncoding.Strict().DecodeString(material.ConfigB64)
	if err != nil || len(config) == 0 || len(config) > 4096 {
		return nil, nil, errTDSProtocol
	}
	keytab, err = base64.StdEncoding.Strict().DecodeString(material.KeytabB64)
	if err != nil || len(keytab) == 0 || len(keytab) > 4096 {
		return nil, nil, errTDSProtocol
	}
	return config, keytab, nil
}

// The managed adapter never opens files or consults a credential cache.
func tdsNewKerberosAuthMaterial(address string, material tdsKerberosMaterial) (tdsIntegratedAuthenticator, error) {
	config, keytab, err := material.decoded()
	if err != nil {
		return nil, errTDSProtocol
	}
	defer clear(keytab)
	return tdsNewKerberosAuthBytes(address, material.Username, material.Realm, material.SPN, config, keytab)
}
