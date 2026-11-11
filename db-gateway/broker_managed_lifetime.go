package main

import (
	"context"
	"net"
	"time"
)

// The laboratory process belongs to one managed session. Revocation and worker
// loss must stop its listener even when no SQL client is connected. The returned
// context also cancels handshakes and established sessions. This does not enable
// managed mode in the production listener.
func brokerWatchManagedListener(parent context.Context, listener net.Listener, check func(context.Context) bool) (context.Context, func()) {
	ctx, cancel := context.WithCancel(parent)
	done := make(chan struct{})
	go func() {
		defer func() { cancel(); _ = listener.Close(); close(done) }()
		ticker := time.NewTicker(2 * time.Second)
		defer ticker.Stop()
		for {
			checkCtx, stopCheck := context.WithTimeout(ctx, 3*time.Second)
			allowed := check != nil && ctx.Err() == nil && check(checkCtx)
			stopCheck()
			if !allowed {
				return
			}
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
			}
		}
	}()
	return ctx, func() { cancel(); <-done }
}
