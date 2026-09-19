package main

import (
	"syscall"
	"unsafe"
)

var (
	user32                  = syscall.NewLazyDLL("user32.dll")
	procEnumWindows         = user32.NewProc("EnumWindows")
	procGetWindowTextW      = user32.NewProc("GetWindowTextW")
	procGetWindowTextLength = user32.NewProc("GetWindowTextLengthW")
	procIsWindowVisible     = user32.NewProc("IsWindowVisible")
)

// listWindowTitles returns the titles of every visible top-level window,
// so the user can pick one to share instead of the whole desktop.
func listWindowTitles() []string {
	var titles []string

	callback := syscall.NewCallback(func(hwnd uintptr, lparam uintptr) uintptr {
		visible, _, _ := procIsWindowVisible.Call(hwnd)
		if visible == 0 {
			return 1 // keep enumerating
		}

		length, _, _ := procGetWindowTextLength.Call(hwnd)
		if length == 0 {
			return 1
		}

		buf := make([]uint16, length+1)
		procGetWindowTextW.Call(hwnd, uintptr(unsafe.Pointer(&buf[0])), uintptr(length+1))
		title := syscall.UTF16ToString(buf)
		if title != "" {
			titles = append(titles, title)
		}
		return 1
	})

	procEnumWindows.Call(callback, 0)
	return titles
}
