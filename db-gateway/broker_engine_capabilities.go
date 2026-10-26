package main

import (
 "errors"
 "strings"
)

// BrokerEngine identifies native database wire protocols. A configured
// engine does not imply that a temporary-credential broker is implemented.
type BrokerEngine string

const (
 BrokerPostgreSQL BrokerEngine = "postgresql"
 BrokerMySQL BrokerEngine = "mysql"
 BrokerMSSQL BrokerEngine = "mssql"
 BrokerOracle BrokerEngine = "oracle"
)

var errUnknownBrokerEngine = errors.New("unknown database engine")
var errBrokerEngineNotImplemented = errors.New("temporary-credential broker not implemented for database engine")

func normalizeBrokerEngine(value string) (BrokerEngine,error) {
 switch strings.ToLower(strings.TrimSpace(value)) {
 case "postgresql","postgres": return BrokerPostgreSQL,nil
 case "mysql","mariadb": return BrokerMySQL,nil
 case "mssql","sqlserver": return BrokerMSSQL,nil
 case "oracle","oracledb": return BrokerOracle,nil
 default:return "",errUnknownBrokerEngine
 }
}

// Only PostgreSQL has a laboratory authenticated broker today.
// Existing pass-through support for other engines is unaffected.
func requireImplementedTemporaryBroker(value string) (BrokerEngine,error) {
 engine,err:=normalizeBrokerEngine(value)
 if err!=nil{return "",err}
 if engine!=BrokerPostgreSQL{return "",errBrokerEngineNotImplemented}
 return engine,nil
}
