package main

import (
	"os/exec"
	"strconv"
)

const firewallRuleName = "HRMediaTube RTSP"

// ensureFirewallRule tries to open the RTSP port to the LAN in Windows
// Firewall automatically. This is the #1 real-world cause of "the TV never
// connects even though the PC says it's streaming": MediaMTX listens fine
// on 127.0.0.1 (so our own startup check passes), but Windows Firewall
// silently blocks the same port when another device on the network (a TV)
// tries to reach it, unless a rule allows it.
//
// Adding a firewall rule requires administrator rights. If the program
// isn't running elevated this will fail — that's reported back so the UI
// can tell the user exactly what to do instead of failing silently.
func ensureFirewallRule(port int) error {
	portStr := strconv.Itoa(port)

	// Remove any previous rule with the same name first so re-running the
	// program doesn't pile up duplicate rules; ignore errors (fails
	// harmlessly if it doesn't exist yet).
	_ = exec.Command("netsh", "advfirewall", "firewall", "delete", "rule",
		"name="+firewallRuleName).Run()

	cmd := exec.Command("netsh", "advfirewall", "firewall", "add", "rule",
		"name="+firewallRuleName,
		"dir=in",
		"action=allow",
		"protocol=TCP",
		"localport="+portStr,
		"profile=private,public",
	)
	hidden(cmd)
	return cmd.Run()
}
