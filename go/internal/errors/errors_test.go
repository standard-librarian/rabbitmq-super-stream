package errors

import (
	stdErrors "errors"
	"testing"

	stream "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/stream"
)

func TestFromStreamErrorMapsAuthenticationFailure(t *testing.T) {
	err := FromStreamError(stream.AuthenticationFailure, nil)
	if err.Code != "authentication_failed" {
		t.Fatalf("expected authentication_failed, got %s", err.Code)
	}
}

func TestFromStreamErrorReturnsHelperErrorAsIs(t *testing.T) {
	source := New("publish_indeterminate", "timeout", true, nil)
	err := FromStreamError(source, nil)
	if !stdErrors.As(err, &source) {
		t.Fatal("expected helper error to pass through")
	}
}
