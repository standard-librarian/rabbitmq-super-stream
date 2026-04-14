package server

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/config"
	apperrors "github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/errors"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/producer"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/protocol"
)

type Server struct {
	cfg          *config.FileConfig
	listener     net.Listener
	producer     *producer.Manager
	httpServer   *http.Server
	shutdownHook func()
	pid          int
	transport    string
}

func New(cfg *config.FileConfig, listener net.Listener, producerManager *producer.Manager, pid int, shutdownHook func()) *Server {
	server := &Server{
		cfg:          cfg,
		listener:     listener,
		producer:     producerManager,
		shutdownHook: shutdownHook,
		pid:          pid,
		transport:    listener.Addr().Network(),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/v1/health", server.handleHealth)
	mux.HandleFunc("/v1/publish", server.handlePublish)
	mux.HandleFunc("/v1/shutdown", server.handleShutdown)

	server.httpServer = &http.Server{
		Handler:           server.authenticate(mux),
		ReadHeaderTimeout: 5 * time.Second,
	}

	return server
}

func (s *Server) Serve() error {
	err := s.httpServer.Serve(s.listener)
	if err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}

	return nil
}

func (s *Server) Shutdown(ctx context.Context) error {
	return s.httpServer.Shutdown(ctx)
}

func (s *Server) authenticate(next http.Handler) http.Handler {
	return http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		authHeader := request.Header.Get("Authorization")
		expected := "Bearer " + s.cfg.Runtime.AuthToken
		if authHeader != expected {
			writeError(writer, http.StatusUnauthorized, request.Context(), "", apperrors.New("helper_unauthorized", "The helper authorization token is invalid.", false, nil))
			return
		}

		next.ServeHTTP(writer, request)
	})
}

func (s *Server) handleHealth(writer http.ResponseWriter, request *http.Request) {
	writeJSON(writer, http.StatusOK, protocol.Envelope{
		ProtocolVersion: s.cfg.Runtime.ProtocolVersion,
		Status:          "ok",
		Result: protocol.HealthResult{
			HelperPID:       s.pid,
			Transport:       normalizeTransport(s.transport),
			SuperStream:     s.cfg.Connection.SuperStream,
			Ready:           true,
			ProtocolVersion: s.cfg.Runtime.ProtocolVersion,
		},
	})
}

func (s *Server) handlePublish(writer http.ResponseWriter, request *http.Request) {
	if request.Method != http.MethodPost {
		writer.WriteHeader(http.StatusMethodNotAllowed)
		return
	}

	payload := &protocol.PublishRequest{}
	if err := json.NewDecoder(request.Body).Decode(payload); err != nil {
		writeError(writer, http.StatusBadRequest, request.Context(), "", apperrors.Wrap("validation_failed", "The publish payload is not valid JSON.", false, err, nil))
		return
	}

	if payload.ProtocolVersion != s.cfg.Runtime.ProtocolVersion {
		writeError(writer, http.StatusBadRequest, request.Context(), payload.RequestID, apperrors.New("validation_failed", "The protocol version is not supported.", false, map[string]any{
			"expected": s.cfg.Runtime.ProtocolVersion,
			"actual":   payload.ProtocolVersion,
		}))
		return
	}

	if strings.TrimSpace(payload.RequestID) == "" || strings.TrimSpace(payload.Message.RoutingKey) == "" || strings.TrimSpace(payload.Message.BodyBase64) == "" {
		writeError(writer, http.StatusBadRequest, request.Context(), payload.RequestID, apperrors.New("validation_failed", "request_id, message.routing_key, and message.body_base64 are required.", false, nil))
		return
	}

	timeout := time.Duration(payload.Options.ConfirmTimeoutMs) * time.Millisecond
	if timeout <= 0 {
		timeout = time.Duration(s.cfg.Connection.ConfirmTimeoutMs) * time.Millisecond
	}

	ctx := request.Context()
	if payload.Options.WaitForConfirm {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, timeout)
		defer cancel()
	}

	result, helperErr := s.producer.Publish(ctx, payload)
	if helperErr != nil {
		writeError(writer, httpStatusFor(helperErr), request.Context(), payload.RequestID, helperErr)
		return
	}

	result.HelperPID = s.pid
	result.Transport = normalizeTransport(s.transport)

	writeJSON(writer, http.StatusOK, protocol.Envelope{
		ProtocolVersion: s.cfg.Runtime.ProtocolVersion,
		RequestID:       payload.RequestID,
		Status:          "ok",
		Result:          result,
	})
}

func (s *Server) handleShutdown(writer http.ResponseWriter, request *http.Request) {
	if request.Method != http.MethodPost {
		writer.WriteHeader(http.StatusMethodNotAllowed)
		return
	}

	writeJSON(writer, http.StatusOK, protocol.Envelope{
		ProtocolVersion: s.cfg.Runtime.ProtocolVersion,
		Status:          "ok",
		Result: map[string]any{
			"message": "shutdown initiated",
		},
	})

	go s.shutdownHook()
}

func writeError(writer http.ResponseWriter, status int, _ context.Context, requestID string, helperErr *apperrors.HelperError) {
	writeJSON(writer, status, protocol.Envelope{
		ProtocolVersion: 1,
		RequestID:       requestID,
		Status:          "error",
		Error: &protocol.ErrorValue{
			Code:      helperErr.Code,
			Message:   helperErr.Message,
			Retryable: helperErr.Retryable,
			Details:   helperErr.Details,
		},
	})
}

func writeJSON(writer http.ResponseWriter, status int, payload protocol.Envelope) {
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(payload)
}

func httpStatusFor(helperErr *apperrors.HelperError) int {
	switch helperErr.Code {
	case "validation_failed", "configuration_error":
		return http.StatusBadRequest
	case "helper_unauthorized", "authentication_failed":
		return http.StatusUnauthorized
	case "super_stream_not_found":
		return http.StatusNotFound
	case "connection_failed":
		return http.StatusServiceUnavailable
	default:
		return http.StatusInternalServerError
	}
}

func normalizeTransport(network string) string {
	if network == "unix" {
		return "unix"
	}
	return "tcp"
}
