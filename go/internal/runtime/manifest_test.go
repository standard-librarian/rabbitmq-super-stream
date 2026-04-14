package runtime

import (
	"net"
	"os"
	"path/filepath"
	"testing"
)

func TestManifestStoreWriteAndReplace(t *testing.T) {
	directory := t.TempDir()
	path := filepath.Join(directory, "manifest.json")
	store := ManifestStore{Path: path}

	if err := store.Write(Manifest{
		ProtocolVersion: 1,
		Endpoint:        "tcp://127.0.0.1:9000",
		Transport:       "tcp",
		PID:             123,
	}); err != nil {
		t.Fatalf("write manifest: %v", err)
	}

	if _, err := os.Stat(path); err != nil {
		t.Fatalf("stat manifest: %v", err)
	}
}

func TestManifestFromListener(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer listener.Close()

	manifest := ManifestFromListener(listener, 1, 123)
	if manifest.Transport != "tcp" {
		t.Fatalf("expected tcp transport, got %s", manifest.Transport)
	}
}
