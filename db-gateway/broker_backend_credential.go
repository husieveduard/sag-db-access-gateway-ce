package main

// Server-side memory only; never serialize credentials to a frontend or logs.
type BrokerBackendCredential struct {
	Username string
	Password string
}
type PgBrokerBackendCredential = BrokerBackendCredential
