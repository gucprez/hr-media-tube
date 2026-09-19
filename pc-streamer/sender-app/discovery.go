package main

import (
	"fmt"
	"net"
	"time"
)

const (
	discoveryPort = 40404
	protocolTag   = "HRMEDIATUBE"
)

// startDiscoveryBroadcast announces this PC's IP/port/path over UDP broadcast
// every 2s, so the Android TV app can auto-fill the server address. Uses the
// same wire format the TV app already expects: "HRMEDIATUBE|ip|port|path".
// Returns a channel that stops the loop when closed.
func startDiscoveryBroadcast(rtspPort int, streamPath string) chan struct{} {
	stop := make(chan struct{})

	go func() {
		addr, err := net.ResolveUDPAddr("udp4", "255.255.255.255:40404")
		if err != nil {
			return
		}
		conn, err := net.DialUDP("udp4", nil, addr)
		if err != nil {
			return
		}
		defer conn.Close()

		ticker := time.NewTicker(2 * time.Second)
		defer ticker.Stop()

		for {
			select {
			case <-stop:
				return
			case <-ticker.C:
				ip := localIP()
				msg := fmt.Sprintf("%s|%s|%d|%s", protocolTag, ip, rtspPort, streamPath)
				_, _ = conn.Write([]byte(msg))
			}
		}
	}()

	return stop
}
