package producer

import (
	"context"
	"crypto/tls"
	"encoding/base64"
	"sync"
	"time"

	"github.com/rabbitmq/rabbitmq-stream-go-client/pkg/amqp"
	"github.com/rabbitmq/rabbitmq-stream-go-client/pkg/ha"
	rmqmessage "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/message"
	stream "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/stream"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/config"
	apperrors "github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/errors"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/protocol"
)

const (
	requestIDHeader  = "x-request-id"
	routingKeyHeader = "x-routing-key"
)

type publishResult struct {
	confirmed   bool
	acceptedAt  time.Time
	confirmedAt time.Time
	err         *apperrors.HelperError
}

type pendingPublish struct {
	request    *protocol.PublishRequest
	resultChan chan publishResult
	acceptedAt time.Time
}

type Manager struct {
	cfg      *config.FileConfig
	env      *stream.Environment
	producer *ha.ReliableSuperStreamProducer
	queue    chan *pendingPublish
	inflight sync.Map

	done chan struct{}
	wg   sync.WaitGroup
}

func New(ctx context.Context, cfg *config.FileConfig) (*Manager, error) {
	env, err := newEnvironment(cfg)
	if err != nil {
		return nil, err
	}

	manager := &Manager{
		cfg:   cfg,
		env:   env,
		queue: make(chan *pendingPublish, cfg.Runtime.MaxQueueSize),
		done:  make(chan struct{}),
	}

	producer, err := newProducer(env, cfg, manager.handleConfirmations)
	if err != nil {
		_ = env.Close()
		return nil, err
	}

	manager.producer = producer
	manager.wg.Add(1)
	go manager.runWorker(ctx)

	return manager, nil
}

func (m *Manager) Publish(ctx context.Context, req *protocol.PublishRequest) (*protocol.PublishResult, *apperrors.HelperError) {
	pending := &pendingPublish{
		request:    req,
		resultChan: make(chan publishResult, 1),
	}

	select {
	case m.queue <- pending:
	case <-ctx.Done():
		return nil, apperrors.New("publish_indeterminate", "Publish request timed out before it could be queued.", true, nil)
	}

	select {
	case result := <-pending.resultChan:
		if result.err != nil {
			return nil, result.err
		}

		response := &protocol.PublishResult{
			MessageID:  req.Message.MessageID,
			Confirmed:  result.confirmed,
			AcceptedAt: result.acceptedAt.UTC().Format(time.RFC3339Nano),
		}
		if !result.confirmedAt.IsZero() {
			response.ConfirmedAt = result.confirmedAt.UTC().Format(time.RFC3339Nano)
		}

		return response, nil
	case <-ctx.Done():
		m.inflight.Delete(req.RequestID)
		return nil, apperrors.New("publish_indeterminate", "Timed out while waiting for the publish result.", true, map[string]any{
			"request_id": req.RequestID,
		})
	}
}

func (m *Manager) Close(ctx context.Context) error {
	close(m.done)
	done := make(chan struct{})
	go func() {
		m.wg.Wait()
		close(done)
	}()

	select {
	case <-done:
	case <-ctx.Done():
	}

	m.failInflight(apperrors.New("publish_indeterminate", "The helper shut down before all publishes were confirmed.", true, nil))

	if m.producer != nil {
		_ = m.producer.Close()
	}
	if m.env != nil {
		return m.env.Close()
	}

	return nil
}

func (m *Manager) runWorker(ctx context.Context) {
	defer m.wg.Done()

	for {
		select {
		case <-m.done:
			return
		case <-ctx.Done():
			return
		case pending := <-m.queue:
			if pending == nil {
				continue
			}

			pending.acceptedAt = time.Now().UTC()
			waitForConfirm := pending.request.Options.WaitForConfirm
			if waitForConfirm {
				m.inflight.Store(pending.request.RequestID, pending)
			}

			if err := m.sendWithRetry(ctx, pending.request); err != nil {
				if waitForConfirm {
					m.inflight.Delete(pending.request.RequestID)
				}
				pending.resultChan <- publishResult{err: err}
				continue
			}

			if !waitForConfirm {
				pending.resultChan <- publishResult{
					confirmed:  false,
					acceptedAt: pending.acceptedAt,
				}
			}
		}
	}
}

func (m *Manager) sendWithRetry(ctx context.Context, req *protocol.PublishRequest) *apperrors.HelperError {
	backoffs := []time.Duration{0, 200 * time.Millisecond, 500 * time.Millisecond, time.Second}

	for attempt, backoff := range backoffs {
		if backoff > 0 {
			timer := time.NewTimer(backoff)
			select {
			case <-ctx.Done():
				timer.Stop()
				return apperrors.New("publish_indeterminate", "Timed out before the publish could be sent.", true, map[string]any{
					"request_id": req.RequestID,
				})
			case <-m.done:
				timer.Stop()
				return apperrors.New("publish_indeterminate", "The helper is shutting down.", true, map[string]any{
					"request_id": req.RequestID,
				})
			case <-timer.C:
			}
		}

		message, helperErr := m.buildMessage(req)
		if helperErr != nil {
			return helperErr
		}

		err := m.producer.Send(message)
		if err == nil {
			return nil
		}

		mapped := apperrors.FromStreamError(err, map[string]any{
			"request_id": req.RequestID,
			"attempt":    attempt + 1,
		})

		if !mapped.Retryable || attempt == len(backoffs)-1 {
			return mapped
		}
	}

	return apperrors.New("publish_rejected", "Publish failed after retries.", false, map[string]any{
		"request_id": req.RequestID,
	})
}

func (m *Manager) handleConfirmations(confirmations []*stream.PartitionPublishConfirm) {
	for _, partitionConfirm := range confirmations {
		for _, status := range partitionConfirm.ConfirmationStatus {
			requestID := requestIDFromMessage(status.GetMessage())
			if requestID == "" {
				continue
			}

			value, ok := m.inflight.LoadAndDelete(requestID)
			if !ok {
				continue
			}

			pending, ok := value.(*pendingPublish)
			if !ok {
				continue
			}

			if status.IsConfirmed() {
				pending.resultChan <- publishResult{
					confirmed:   true,
					acceptedAt:  pending.acceptedAt,
					confirmedAt: time.Now().UTC(),
				}
				continue
			}

			pending.resultChan <- publishResult{
				err: apperrors.FromStreamError(status.GetError(), map[string]any{
					"request_id": requestID,
					"partition":  partitionConfirm.Partition,
					"error_code": status.GetErrorCode(),
				}),
			}
		}
	}
}

func (m *Manager) failInflight(helperErr *apperrors.HelperError) {
	m.inflight.Range(func(key, value any) bool {
		m.inflight.Delete(key)
		pending, ok := value.(*pendingPublish)
		if ok {
			pending.resultChan <- publishResult{err: helperErr}
		}
		return true
	})
}

func requestIDFromMessage(message rmqmessage.StreamMessage) string {
	if message == nil {
		return ""
	}
	props := message.GetApplicationProperties()
	if props == nil {
		return ""
	}
	value, ok := props[requestIDHeader]
	if !ok {
		return ""
	}
	text, ok := value.(string)
	if !ok {
		return ""
	}
	return text
}

func (m *Manager) buildMessage(req *protocol.PublishRequest) (*amqp.AMQP10, *apperrors.HelperError) {
	body, err := base64.StdEncoding.DecodeString(req.Message.BodyBase64)
	if err != nil {
		return nil, apperrors.Wrap("validation_failed", "The publish payload body_base64 is invalid.", false, err, nil)
	}

	message := amqp.NewMessage(body)
	message.Properties = &amqp.MessageProperties{}
	if req.Message.MessageID != "" {
		message.Properties.MessageID = req.Message.MessageID
	}
	if req.Message.CorrelationID != "" {
		message.Properties.CorrelationID = req.Message.CorrelationID
	}
	if req.Message.ContentType != "" {
		message.Properties.ContentType = req.Message.ContentType
	}

	message.ApplicationProperties = map[string]any{
		requestIDHeader:  req.RequestID,
		routingKeyHeader: req.Message.RoutingKey,
	}
	for key, value := range req.Message.Headers {
		message.ApplicationProperties[key] = value
	}

	return message, nil
}

func newEnvironment(cfg *config.FileConfig) (*stream.Environment, error) {
	options := stream.NewEnvironmentOptions().
		SetHost(cfg.Connection.Host).
		SetPort(cfg.Connection.Port).
		SetUser(cfg.Connection.Username).
		SetPassword(cfg.Connection.Password).
		SetVHost(cfg.Connection.VHost).
		SetRPCTimeout(time.Duration(cfg.Runtime.RPCTimeoutMs) * time.Millisecond)

	if cfg.Connection.UseTLS {
		options.IsTLS(true)
		options.SetTLSConfig(&tls.Config{
			InsecureSkipVerify: !cfg.Connection.VerifyTLS,
			ServerName:         cfg.Connection.TLSServerName,
		})
	}

	env, err := stream.NewEnvironment(options)
	if err != nil {
		return nil, err
	}

	return env, nil
}

func newProducer(env *stream.Environment, cfg *config.FileConfig, confirmations ha.PartitionConfirmMessageHandler) (*ha.ReliableSuperStreamProducer, error) {
	options := stream.NewSuperStreamProducerOptions(
		stream.NewHashRoutingStrategy(func(message rmqmessage.StreamMessage) string {
			if message == nil {
				return ""
			}
			props := message.GetApplicationProperties()
			if props == nil {
				return ""
			}
			value, ok := props[routingKeyHeader]
			if !ok {
				return ""
			}
			text, ok := value.(string)
			if !ok {
				return ""
			}
			return text
		}),
	).SetClientProvidedName("rabbitmq-super-stream-helper")

	return ha.NewReliableSuperStreamProducer(env, cfg.Connection.SuperStream, options, confirmations)
}
