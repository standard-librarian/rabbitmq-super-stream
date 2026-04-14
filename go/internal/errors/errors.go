package errors

import (
	"errors"
	"fmt"

	stream "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/stream"
)

type HelperError struct {
	Code      string
	Message   string
	Retryable bool
	Details   map[string]any
}

func (e *HelperError) Error() string {
	return e.Message
}

func New(code string, message string, retryable bool, details map[string]any) *HelperError {
	if details == nil {
		details = map[string]any{}
	}

	return &HelperError{
		Code:      code,
		Message:   message,
		Retryable: retryable,
		Details:   details,
	}
}

func Wrap(code string, message string, retryable bool, err error, details map[string]any) *HelperError {
	if details == nil {
		details = map[string]any{}
	}

	if err != nil {
		details["cause"] = err.Error()
	}

	return New(code, message, retryable, details)
}

func FromStreamError(err error, details map[string]any) *HelperError {
	if err == nil {
		return nil
	}
	var helperErr *HelperError
	if errors.As(err, &helperErr) {
		return helperErr
	}

	switch {
	case errors.Is(err, stream.AuthenticationFailure),
		errors.Is(err, stream.VirtualHostAccessFailure),
		errors.Is(err, stream.AuthenticationFailureLoopbackError):
		return Wrap("authentication_failed", "RabbitMQ authentication failed.", false, err, details)
	case errors.Is(err, stream.StreamDoesNotExist):
		return Wrap("super_stream_not_found", "The configured RabbitMQ super stream does not exist.", false, err, details)
	case errors.Is(err, stream.ConnectionClosed),
		errors.Is(err, stream.StreamNotAvailable),
		errors.Is(err, stream.LeaderNotReady),
		errors.Is(err, stream.InternalError):
		return Wrap("connection_failed", "RabbitMQ stream connection failed.", true, err, details)
	case errors.Is(err, stream.ConfirmationTimoutError):
		return Wrap("publish_indeterminate", "Timed out while waiting for a publish confirmation.", true, err, details)
	default:
		return Wrap("publish_rejected", fmt.Sprintf("Publish failed: %s", err.Error()), false, err, details)
	}
}
