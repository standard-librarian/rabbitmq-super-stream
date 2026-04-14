package runtime

import (
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"time"
)

type Manifest struct {
	ProtocolVersion int    `json:"protocol_version"`
	Endpoint        string `json:"endpoint"`
	Transport       string `json:"transport"`
	PID             int    `json:"pid"`
	StartedAt       string `json:"started_at"`
}

type ManifestStore struct {
	Path string
}

func (s ManifestStore) Write(manifest Manifest) error {
	if err := os.MkdirAll(filepath.Dir(s.Path), 0o700); err != nil {
		return fmt.Errorf("create manifest directory: %w", err)
	}

	data, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encode manifest: %w", err)
	}

	tempPath := s.Path + ".tmp"
	if err := os.WriteFile(tempPath, data, 0o600); err != nil {
		return fmt.Errorf("write temp manifest: %w", err)
	}

	if err := os.Rename(tempPath, s.Path); err != nil {
		_ = os.Remove(tempPath)
		return fmt.Errorf("replace manifest: %w", err)
	}

	return nil
}

func (s ManifestStore) Remove() {
	_ = os.Remove(s.Path)
}

func ManifestFromListener(listener net.Listener, protocolVersion int, pid int) Manifest {
	endpoint := listener.Addr().String()
	transport := listener.Addr().Network()
	if transport == "tcp" {
		endpoint = "tcp://" + endpoint
	} else {
		transport = "unix"
		endpoint = "unix://" + endpoint
	}

	return Manifest{
		ProtocolVersion: protocolVersion,
		Endpoint:        endpoint,
		Transport:       transport,
		PID:             pid,
		StartedAt:       time.Now().UTC().Format(time.RFC3339Nano),
	}
}
