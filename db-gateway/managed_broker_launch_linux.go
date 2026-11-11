package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"os"
	"strconv"
	"syscall"
	"time"
)

// Shared launcher contract for native adapters. All control messages use
// inherited anonymous pipes; no launch capability or token appears in argv.
type brokerLaunchControl struct {
	input           *os.File
	ready           *os.File
	reader          *bufio.Reader
	engine, session string
	resource        int64
}
type brokerLaunchMessage struct {
	Operation string `json:"operation"`
	Engine    string `json:"engine"`
	Session   string `json:"session_uid"`
	Resource  int64  `json:"resource_id"`
	Token     string `json:"internal_token,omitempty"`
}

func brokerReadManagedLaunch(input, ready *os.File, engine, session string, resource int64) (*brokerLaunchControl, string, error) {
	deny := errors.New("managed launch denied")
	if input == nil || ready == nil {
		return nil, "", deny
	}
	for _, file := range []*os.File{input, ready} {
		stat, err := file.Stat()
		if err != nil || stat.Mode()&os.ModeNamedPipe == 0 {
			return nil, "", errors.New("MANAGED_CONTROL_PIPE_INVALID")
		}
	}
	// proc_open inherits blocking descriptors. Rewrap duplicated nonblocking
	// descriptors so Go's poller can enforce deadlines on real inherited pipes.
	input, err := brokerManagedPollablePipe(input)
	if err != nil {
		return nil, "", deny
	}
	ready, err = brokerManagedPollablePipe(ready)
	if err != nil {
		input.Close()
		return nil, "", deny
	}
	control := &brokerLaunchControl{input: input, ready: ready, reader: bufio.NewReaderSize(input, 8192), engine: engine, session: session, resource: resource}
	message, err := control.read("launch")
	if err != nil || len(message.Token) == 0 || len(message.Token) > 4096 {
		input.Close()
		ready.Close()
		if err != nil {
			return nil, "", err
		}
		return nil, "", deny
	}
	return control, message.Token, nil
}
func (c *brokerLaunchControl) read(operation string) (brokerLaunchMessage, error) {
	var message brokerLaunchMessage
	deny := errors.New("managed launch denied")
	if c.input.SetReadDeadline(time.Now().Add(10*time.Second)) != nil {
		return message, errors.New("MANAGED_CONTROL_DEADLINE_UNAVAILABLE")
	}
	frame, err := c.reader.ReadSlice('\n')
	if err != nil || len(frame) > 8192 {
		return message, errors.New("MANAGED_CONTROL_FRAME_UNAVAILABLE")
	}
	decoder := json.NewDecoder(bytes.NewReader(frame))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&message) != nil || message.Operation != operation || message.Engine != c.engine || message.Session != c.session || message.Resource != c.resource {
		return brokerLaunchMessage{}, errors.New("MANAGED_CONTROL_BINDING_INVALID")
	}
	if decoder.Decode(&struct{}{}) != io.EOF {
		return brokerLaunchMessage{}, deny
	}
	return message, nil
}

// Called only after the adapter has completed native backend authentication
// and bound its listener. PHP records started before releasing activation.
func (c *brokerLaunchControl) backendReady() error {
	defer c.ready.Close()
	if c.ready.SetWriteDeadline(time.Now().Add(3*time.Second)) != nil {
		return errors.New("managed readiness unavailable")
	}
	if json.NewEncoder(c.ready).Encode(map[string]any{"phase": "backend_authenticated", "engine": c.engine, "session_uid": c.session, "resource_id": c.resource}) != nil {
		return errors.New("managed readiness unavailable")
	}
	message, err := c.read("activate")
	c.input.Close()
	if err != nil || message.Token != "" {
		return errors.New("managed activation denied")
	}
	return nil
}

func brokerManagedPollablePipe(original *os.File) (*os.File, error) {
	fd, err := syscall.Dup(int(original.Fd()))
	if err != nil {
		return nil, err
	}
	syscall.CloseOnExec(fd)
	if err = syscall.SetNonblock(fd, true); err != nil {
		syscall.Close(fd)
		return nil, err
	}
	file := os.NewFile(uintptr(fd), "managed-control")
	original.Close()
	if file == nil {
		syscall.Close(fd)
		return nil, errors.New("managed control unavailable")
	}
	return file, nil
}

// PHP/Laravel can hold extra copies of stdout/stderr or DB descriptors. Re-exec
// the same binary/PID once, closing every inherited descriptor except the four
// explicit launch channels. CLOEXEC preserves the current Go runtime until exec.
func brokerReexecManaged(args []string) error {
	executable, err := os.Executable()
	if err != nil {
		return errors.New("MANAGED_EXEC_UNAVAILABLE")
	}
	entries, err := os.ReadDir("/proc/self/fd")
	if err != nil {
		return errors.New("MANAGED_FD_LIST_UNAVAILABLE")
	}
	for _, entry := range entries {
		fd, e := strconv.Atoi(entry.Name())
		if e == nil && fd >= 4 {
			syscall.CloseOnExec(fd)
		}
	}
	for fd := 0; fd <= 3; fd++ {
		if _, _, e := syscall.Syscall(syscall.SYS_FCNTL, uintptr(fd), syscall.F_SETFD, 0); e != 0 {
			return errors.New("MANAGED_FD_UNAVAILABLE")
		}
	}
	return syscall.Exec(executable, append([]string{executable}, append(args, "--managed-fds-ready")...), os.Environ())
}
