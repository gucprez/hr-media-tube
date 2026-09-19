package main

import (
	"archive/zip"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Serializes EnsureBinaries so a background first-run download and a
// user-triggered "Iniciar" click can't both try to download/write the same
// files at once.
var ensureBinariesMu sync.Mutex

// binDir returns the folder where ffmpeg.exe/mediamtx.exe live: right next
// to this program's own executable, so everything stays in one portable folder.
func binDir() string {
	exePath, err := os.Executable()
	if err != nil {
		return "."
	}
	return filepath.Join(filepath.Dir(exePath), "bin")
}

func ffmpegPath() string   { return filepath.Join(binDir(), "ffmpeg.exe") }
func mediamtxPath() string { return filepath.Join(binDir(), "mediamtx.exe") }

type githubAsset struct {
	Name               string `json:"name"`
	BrowserDownloadURL string `json:"browser_download_url"`
}

type githubRelease struct {
	Assets []githubAsset `json:"assets"`
}

func setPrepareMessage(msg string) {
	state.mu.Lock()
	state.prepareMessage = msg
	state.mu.Unlock()
}

// EnsureBinaries makes sure ffmpeg.exe and mediamtx.exe exist in binDir(),
// downloading and extracting them from their official GitHub releases the
// first time the program runs. Safe to call every time: it's a no-op once
// the files are already there.
func EnsureBinaries() error {
	if fileExists(ffmpegPath()) && fileExists(mediamtxPath()) {
		return nil
	}

	ensureBinariesMu.Lock()
	defer ensureBinariesMu.Unlock()

	if fileExists(ffmpegPath()) && fileExists(mediamtxPath()) {
		return nil
	}

	state.mu.Lock()
	state.preparing = true
	state.mu.Unlock()
	defer func() {
		state.mu.Lock()
		state.preparing = false
		state.mu.Unlock()
	}()

	if err := os.MkdirAll(binDir(), 0o755); err != nil {
		return fmt.Errorf("no se pudo crear la carpeta bin: %w", err)
	}

	if !fileExists(mediamtxPath()) {
		setPrepareMessage("Descargando servidor de video (MediaMTX)...")
		if err := downloadReleaseAsset(
			"https://api.github.com/repos/bluenviron/mediamtx/releases/latest",
			"windows_amd64.zip",
			"mediamtx.exe",
			mediamtxPath(),
		); err != nil {
			return fmt.Errorf("descargando MediaMTX: %w", err)
		}
	}

	if !fileExists(ffmpegPath()) {
		setPrepareMessage("Descargando ffmpeg (esto puede tardar unos minutos)...")
		if err := downloadReleaseAsset(
			"https://api.github.com/repos/BtbN/FFmpeg-Builds/releases/latest",
			"win64-gpl.zip",
			"ffmpeg.exe",
			ffmpegPath(),
		); err != nil {
			return fmt.Errorf("descargando ffmpeg: %w", err)
		}
	}

	setPrepareMessage("Listo.")
	return nil
}

func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

// downloadReleaseAsset asks GitHub's release API for the latest release of
// a repo, finds the asset whose name contains assetNameContains, downloads
// it (a .zip), and extracts just the file named wantFileName (matched by
// base name, case-insensitively, wherever it is inside the zip) to destPath.
func downloadReleaseAsset(releaseAPIURL, assetNameContains, wantFileName, destPath string) error {
	client := &http.Client{Timeout: 60 * time.Second}

	req, err := http.NewRequest("GET", releaseAPIURL, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/vnd.github+json")

	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("no se pudo consultar GitHub: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("GitHub respondió %d al consultar %s", resp.StatusCode, releaseAPIURL)
	}

	var release githubRelease
	if err := json.NewDecoder(resp.Body).Decode(&release); err != nil {
		return fmt.Errorf("no se pudo leer la respuesta de GitHub: %w", err)
	}

	var downloadURL string
	for _, a := range release.Assets {
		if strings.Contains(a.Name, assetNameContains) {
			downloadURL = a.BrowserDownloadURL
			break
		}
	}
	if downloadURL == "" {
		return fmt.Errorf("no se encontró un archivo que contenga %q en la última versión", assetNameContains)
	}

	tmpZip, err := os.CreateTemp("", "hrmediatube-*.zip")
	if err != nil {
		return err
	}
	tmpZipPath := tmpZip.Name()
	defer os.Remove(tmpZipPath)

	dlClient := &http.Client{Timeout: 20 * time.Minute}
	dlResp, err := dlClient.Get(downloadURL)
	if err != nil {
		tmpZip.Close()
		return fmt.Errorf("descargando %s: %w", downloadURL, err)
	}
	defer dlResp.Body.Close()

	if _, err := io.Copy(tmpZip, dlResp.Body); err != nil {
		tmpZip.Close()
		return fmt.Errorf("guardando la descarga: %w", err)
	}
	tmpZip.Close()

	return extractFileFromZip(tmpZipPath, wantFileName, destPath)
}

// extractFileFromZip scans every entry in the zip for one whose base name
// matches wantFileName (case-insensitive) and copies it to destPath,
// regardless of how deeply nested it is inside the archive.
func extractFileFromZip(zipPath, wantFileName, destPath string) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return fmt.Errorf("abriendo zip: %w", err)
	}
	defer r.Close()

	want := strings.ToLower(wantFileName)

	for _, f := range r.File {
		base := strings.ToLower(filepath.Base(f.Name))
		if base != want {
			continue
		}

		src, err := f.Open()
		if err != nil {
			return err
		}

		dst, err := os.OpenFile(destPath, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o755)
		if err != nil {
			src.Close()
			return err
		}

		_, copyErr := io.Copy(dst, src)
		src.Close()
		dst.Close()
		if copyErr != nil {
			return copyErr
		}
		return nil
	}

	return fmt.Errorf("no se encontró %s dentro del archivo descargado", wantFileName)
}
