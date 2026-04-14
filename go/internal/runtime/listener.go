package runtime

import (
	"fmt"
	"net"
	"os"
	"runtime"
	"strings"

	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/config"
)

func Listen(cfg config.RuntimeConfig) (net.Listener, error) {
	if cfg.TransportPreference == "tcp" || runtime.GOOS == "windows" {
		return net.Listen("tcp", "127.0.0.1:0")
	}

	if cfg.SocketPath != "" {
		_ = os.Remove(cfg.SocketPath)
		listener, err := net.Listen("unix", cfg.SocketPath)
		if err == nil {
			return listener, nil
		}

		if cfg.TransportPreference == "unix" && !strings.Contains(err.Error(), "address already in use") {
			return nil, fmt.Errorf("listen on unix socket: %w", err)
		}
	}

	return net.Listen("tcp", "127.0.0.1:0")
}
