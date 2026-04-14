package app

import (
	"context"
	"log"
	"net"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/config"
	apperrors "github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/errors"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/producer"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/runtime"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/server"
)

func Run(configPath string) error {
	cfg, err := config.Load(configPath)
	if err != nil {
		return apperrors.Wrap("configuration_error", "Failed to load the helper configuration.", false, err, nil)
	}

	if cfg.Runtime.LogPath != "" {
		logFile, openErr := os.OpenFile(cfg.Runtime.LogPath, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
		if openErr == nil {
			log.SetOutput(logFile)
		}
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	producerManager, err := producer.New(ctx, cfg)
	if err != nil {
		return apperrors.FromStreamError(err, nil)
	}

	listener, err := runtime.Listen(cfg.Runtime)
	if err != nil {
		shutdownCtx, cancel := context.WithTimeout(context.Background(), time.Duration(cfg.Runtime.ShutdownTimeoutMs)*time.Millisecond)
		defer cancel()
		_ = producerManager.Close(shutdownCtx)
		return apperrors.Wrap("helper_startup_failed", "Failed to create the helper listener.", false, err, nil)
	}

	manifestStore := runtime.ManifestStore{Path: cfg.Runtime.ManifestPath}
	manifest := runtime.ManifestFromListener(listener, cfg.Runtime.ProtocolVersion, os.Getpid())
	if err := manifestStore.Write(manifest); err != nil {
		shutdownCtx, cancel := context.WithTimeout(context.Background(), time.Duration(cfg.Runtime.ShutdownTimeoutMs)*time.Millisecond)
		defer cancel()
		_ = producerManager.Close(shutdownCtx)
		return apperrors.Wrap("helper_startup_failed", "Failed to write the helper manifest.", false, err, nil)
	}

	shutdownOnce := make(chan struct{}, 1)
	shutdownHook := func() {
		select {
		case shutdownOnce <- struct{}{}:
			stop()
		default:
		}
	}

	httpServer := server.New(cfg, listener, producerManager, os.Getpid(), shutdownHook)
	serveErr := make(chan error, 1)
	go func() {
		serveErr <- httpServer.Serve()
	}()

	select {
	case <-ctx.Done():
	case err = <-serveErr:
		if err != nil {
			manifestStore.Remove()
			return apperrors.Wrap("helper_startup_failed", "The helper server exited unexpectedly.", false, err, nil)
		}
	}

	shutdownCtx, cancel := context.WithTimeout(context.Background(), time.Duration(cfg.Runtime.ShutdownTimeoutMs)*time.Millisecond)
	defer cancel()

	defer manifestStore.Remove()
	defer removeSocket(listener)

	_ = httpServer.Shutdown(shutdownCtx)
	return producerManager.Close(shutdownCtx)
}

func removeSocket(listener interface{ Addr() net.Addr }) {
	if listener.Addr().Network() == "unix" {
		_ = os.Remove(listener.Addr().String())
	}
}
