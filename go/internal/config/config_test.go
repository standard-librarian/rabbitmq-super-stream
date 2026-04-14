package config

import "testing"

func TestValidateRejectsMissingHost(t *testing.T) {
	cfg := &FileConfig{
		Connection: ConnectionConfig{
			Port:        5552,
			Username:    "guest",
			SuperStream: "orders",
		},
		Runtime: RuntimeConfig{
			ProtocolVersion:     1,
			TransportPreference: "auto",
			ManifestPath:        "/tmp/manifest.json",
			AuthToken:           "secret",
			RPCTimeoutMs:        1000,
			ShutdownTimeoutMs:   1000,
			MaxQueueSize:        128,
		},
	}

	if err := cfg.Validate(); err == nil {
		t.Fatal("expected validation to fail")
	}
}
