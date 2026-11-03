package main

import (
	"context"
	"encoding/json"
)

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
