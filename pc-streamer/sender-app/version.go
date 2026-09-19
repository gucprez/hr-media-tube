package main

import "fmt"

// Bump appVersion (and releases/version.txt in the repo, to the same value)
// every time a new build is published, so running copies can detect it.
const appVersion = "3"

const (
	updateOwner  = "gucprez"
	updateRepo   = "hr-media-tube"
	updateBranch = "claude/apk-video-streaming-android-tv-jz88xb"
)

// rawURL builds a raw.githubusercontent.com URL that always serves whatever
// is currently on updateBranch (unlike a commit-pinned URL), so published
// updates are picked up automatically.
func rawURL(path string) string {
	return fmt.Sprintf("https://raw.githubusercontent.com/%s/%s/%s/%s", updateOwner, updateRepo, updateBranch, path)
}
