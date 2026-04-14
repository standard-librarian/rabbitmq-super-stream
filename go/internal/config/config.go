package config

import (
	"encoding/json"
	"fmt"
	"os"
)

type FileConfig struct {
	Connection ConnectionConfig `json:"connection"`
	Runtime    RuntimeConfig    `json:"runtime"`
}

type ConnectionConfig struct {
	Host             string `json:"host"`
	Port             int    `json:"port"`
	Username         string `json:"username"`
	Password         string `json:"password"`
	VHost            string `json:"vhost"`
	SuperStream      string `json:"super_stream"`
	UseTLS           bool   `json:"use_tls"`
	VerifyTLS        bool   `json:"verify_tls"`
	TLSServerName    string `json:"tls_server_name"`
	ConnectTimeoutMs int    `json:"connect_timeout_ms"`
	ConfirmTimeoutMs int    `json:"confirm_timeout_ms"`
}

type RuntimeConfig struct {
	ProtocolVersion     int    `json:"protocol_version"`
	TransportPreference string `json:"transport_preference"`
	SocketPath          string `json:"socket_path"`
	ManifestPath        string `json:"manifest_path"`
	LogPath             string `json:"log_path"`
	AuthToken           string `json:"auth_token"`
	RPCTimeoutMs        int    `json:"rpc_timeout_ms"`
	ShutdownTimeoutMs   int    `json:"shutdown_timeout_ms"`
	MaxQueueSize        int    `json:"max_queue_size"`
}

func Load(path string) (*FileConfig, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config file: %w", err)
	}

	cfg := &FileConfig{}
	if err := json.Unmarshal(content, cfg); err != nil {
		return nil, fmt.Errorf("decode config file: %w", err)
	}

	if err := cfg.Validate(); err != nil {
		return nil, err
	}

	return cfg, nil
}

func (c *FileConfig) Validate() error {
	if c.Connection.Host == "" {
		return fmt.Errorf("connection.host is required")
	}
	if c.Connection.Port < 1 || c.Connection.Port > 65535 {
		return fmt.Errorf("connection.port must be between 1 and 65535")
	}
	if c.Connection.Username == "" {
		return fmt.Errorf("connection.username is required")
	}
	if c.Connection.SuperStream == "" {
		return fmt.Errorf("connection.super_stream is required")
	}
	if c.Runtime.ManifestPath == "" {
		return fmt.Errorf("runtime.manifest_path is required")
	}
	if c.Runtime.AuthToken == "" {
		return fmt.Errorf("runtime.auth_token is required")
	}
	if c.Runtime.ProtocolVersion <= 0 {
		return fmt.Errorf("runtime.protocol_version must be greater than zero")
	}
	if c.Runtime.RPCTimeoutMs <= 0 {
		return fmt.Errorf("runtime.rpc_timeout_ms must be greater than zero")
	}
	if c.Runtime.ShutdownTimeoutMs <= 0 {
		return fmt.Errorf("runtime.shutdown_timeout_ms must be greater than zero")
	}
	if c.Runtime.MaxQueueSize <= 0 {
		return fmt.Errorf("runtime.max_queue_size must be greater than zero")
	}
	if c.Runtime.TransportPreference == "" {
		c.Runtime.TransportPreference = "auto"
	}

	return nil
}
