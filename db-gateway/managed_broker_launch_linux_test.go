package main

import (
	"bytes"
	"encoding/json"
	"os"
	"testing"
)

func TestManagedLaunchUsesBoundPrivatePipesAndTwoPhaseActivation(t *testing.T) {
	input, writer, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer input.Close()
	defer writer.Close()
	reader, ready, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	defer ready.Close()
	launch := brokerLaunchMessage{Operation: "launch", Engine: "postgresql", Session: "session", Resource: 7, Token: "internal-fixture"}
	if json.NewEncoder(writer).Encode(launch) != nil {
		t.Fatal("launch fixture failed")
	}
	control, token, err := brokerReadManagedLaunch(input, ready, "postgresql", "session", 7)
	if err != nil || token != "internal-fixture" {
		t.Fatal("private launch failed")
	}
	done := make(chan error, 1)
	go func() { done <- control.backendReady() }()
	var frame map[string]any
	if json.NewDecoder(reader).Decode(&frame) != nil || frame["phase"] != "backend_authenticated" {
		t.Fatal("readiness unavailable")
	}
	encoded, _ := json.Marshal(frame)
	if bytes.Contains(encoded, []byte(token)) {
		t.Fatal("token leaked in readiness")
	}
	launch.Operation = "activate"
	launch.Token = ""
	json.NewEncoder(writer).Encode(launch)
	if err := <-done; err != nil {
		t.Fatal("activation failed")
	}
}

func TestManagedLaunchDeniesWrongSessionAndMissingPrivatePipe(t *testing.T) {
	for _, session := range []string{"wrong", ""} {
		input, writer, err := os.Pipe()
		if err != nil {
			t.Fatal(err)
		}
		reader, ready, err := os.Pipe()
		if err != nil {
			t.Fatal(err)
		}
		json.NewEncoder(writer).Encode(brokerLaunchMessage{Operation: "launch", Engine: "postgresql", Session: session, Resource: 7, Token: "fixture"})
		if _, _, e := brokerReadManagedLaunch(input, ready, "postgresql", "expected", 7); e == nil {
			t.Fatal("foreign launch accepted")
		}
		input.Close()
		writer.Close()
		reader.Close()
		ready.Close()
	}
	if _, _, err := brokerReadManagedLaunch(nil, nil, "postgresql", "session", 7); err == nil {
		t.Fatal("missing descriptors accepted")
	}
}
