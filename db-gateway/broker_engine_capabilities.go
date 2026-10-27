package main

import (
    "errors"
    "strings"
)

// BrokerEngine is a normalized wire-protocol family. MariaDB shares the MySQL adapter.
type BrokerEngine string

const (
    BrokerPostgreSQL BrokerEngine = "postgresql"
    BrokerMySQL BrokerEngine = "mysql"
    BrokerMSSQL BrokerEngine = "mssql"
    BrokerOracle BrokerEngine = "oracle"
)

var errUnknownBrokerEngine = errors.New("unknown database engine")
var errBrokerEngineNotImplemented = errors.New("temporary-credential broker not implemented for database engine")

// BrokerCapability records what has been proven implemented, not merely planned.
type BrokerCapability struct {
    Engine BrokerEngine
    Passthrough bool
    TemporaryBroker bool
    CredentialVaultIPC bool
    SQLAudit bool
}

func normalizeBrokerEngine(value string) (BrokerEngine, error) {
    switch strings.ToLower(strings.TrimSpace(value)) {
    case "postgresql", "postgres": return BrokerPostgreSQL, nil
    case "mysql", "mariadb": return BrokerMySQL, nil
    case "mssql", "sqlserver": return BrokerMSSQL, nil
    case "oracle", "oracledb": return BrokerOracle, nil
    default: return "", errUnknownBrokerEngine
    }
}

func brokerCapability(value string) (BrokerCapability, error) {
    engine, err := normalizeBrokerEngine(value)
    if err != nil { return BrokerCapability{}, err }
    switch engine {
    case BrokerPostgreSQL:
        return BrokerCapability{Engine: engine, Passthrough: true, TemporaryBroker: true, SQLAudit: true}, nil
    case BrokerMySQL:
        return BrokerCapability{Engine: engine, Passthrough: true}, nil
    case BrokerMSSQL:
        return BrokerCapability{Engine: engine, Passthrough: true}, nil
    case BrokerOracle:
        return BrokerCapability{Engine: engine}, nil
    default:
        return BrokerCapability{}, errUnknownBrokerEngine
    }
}

// This gate must only change when the native protocol implementation and
// secret transport have regression coverage. Never route unsupported
// temporary credentials into client passthrough.
func requireImplementedTemporaryBroker(value string) (BrokerEngine, error) {
    caps, err := brokerCapability(value)
    if err != nil { return "", err }
    if !caps.TemporaryBroker { return "", errBrokerEngineNotImplemented }
    return caps.Engine, nil
}
