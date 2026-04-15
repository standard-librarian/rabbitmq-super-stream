package producer

import (
	"errors"
	"fmt"
	"slices"
	"sync"

	rmqmessage "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/message"
	stream "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/stream"
	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/config"
)

const helperClientProvidedName = "rabbitmq-super-stream-helper"

type partitionConfirmHandler func(partition string, statuses []*stream.ConfirmationStatus)

type superStreamProducer struct {
	env        *stream.Environment
	partitions []string
	producers  map[string]*stream.Producer
	filter     *stream.ProducerFilter
	routing    stream.RoutingStrategy
	onConfirm  partitionConfirmHandler

	mu     sync.RWMutex
	closed chan struct{}
}

func newSuperStreamProducer(env *stream.Environment, cfg *config.FileConfig, onConfirm partitionConfirmHandler) (*superStreamProducer, error) {
	partitions, err := env.QueryPartitions(cfg.Connection.SuperStream)
	if err != nil {
		return nil, err
	}

	partitions = uniquePartitions(partitions)
	if len(partitions) == 0 {
		return nil, fmt.Errorf("super stream %s has no partitions", cfg.Connection.SuperStream)
	}

	producer := &superStreamProducer{
		env:        env,
		partitions: partitions,
		producers:  make(map[string]*stream.Producer, len(partitions)),
		routing: stream.NewHashRoutingStrategy(func(message rmqmessage.StreamMessage) string {
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
		onConfirm: onConfirm,
		closed:    make(chan struct{}),
	}

	for _, partition := range partitions {
		if _, err := producer.connectPartition(partition); err != nil {
			_ = producer.Close()
			return nil, err
		}
	}

	return producer, nil
}

func (p *superStreamProducer) Send(message rmqmessage.StreamMessage) error {
	partition, err := p.route(message)
	if err != nil {
		return err
	}

	producer, err := p.ensureProducer(partition)
	if err != nil {
		return err
	}

	err = producer.Send(message)
	if err == nil {
		return nil
	}

	if shouldDropProducer(err) {
		p.dropProducer(partition, producer)
	}

	return err
}

func (p *superStreamProducer) Close() error {
	select {
	case <-p.closed:
	default:
		close(p.closed)
	}

	p.mu.Lock()
	producers := make([]*stream.Producer, 0, len(p.producers))
	for _, producer := range p.producers {
		producers = append(producers, producer)
	}
	p.producers = map[string]*stream.Producer{}
	p.mu.Unlock()

	var closeErr error
	for _, producer := range producers {
		if err := producer.Close(); err != nil && closeErr == nil {
			closeErr = err
		}
	}

	return closeErr
}

func (p *superStreamProducer) route(message rmqmessage.StreamMessage) (string, error) {
	p.mu.RLock()
	partitions := append([]string(nil), p.partitions...)
	p.mu.RUnlock()

	routes, err := p.routing.Route(message, partitions)
	if err != nil {
		return "", err
	}
	if len(routes) == 0 {
		return "", stream.ErrMessageRouteNotFound
	}

	return routes[0], nil
}

func (p *superStreamProducer) ensureProducer(partition string) (*stream.Producer, error) {
	p.mu.RLock()
	existing := p.producers[partition]
	p.mu.RUnlock()
	if existing != nil {
		return existing, nil
	}

	return p.connectPartition(partition)
}

func (p *superStreamProducer) connectPartition(partition string) (*stream.Producer, error) {
	p.mu.Lock()
	if !slices.Contains(p.partitions, partition) {
		p.mu.Unlock()
		return nil, fmt.Errorf("partition %s not found", partition)
	}
	if existing := p.producers[partition]; existing != nil {
		p.mu.Unlock()
		return existing, nil
	}
	p.mu.Unlock()

	options := stream.NewProducerOptions().SetClientProvidedName(helperClientProvidedName)
	if p.filter != nil {
		options = options.SetFilter(p.filter)
	}

	producer, err := p.env.NewProducer(partition, options)
	if err != nil {
		return nil, err
	}

	confirmations := producer.NotifyPublishConfirmation()
	closed := producer.NotifyClose()

	p.mu.Lock()
	if existing := p.producers[partition]; existing != nil {
		p.mu.Unlock()
		_ = producer.Close()
		return existing, nil
	}
	p.producers[partition] = producer
	p.mu.Unlock()

	go p.forwardConfirmations(partition, confirmations)
	go p.watchClose(partition, producer, closed)

	return producer, nil
}

func (p *superStreamProducer) forwardConfirmations(partition string, confirmations <-chan []*stream.ConfirmationStatus) {
	for statuses := range confirmations {
		if len(statuses) == 0 {
			continue
		}

		p.onConfirm(partition, statuses)
	}
}

func (p *superStreamProducer) watchClose(partition string, producer *stream.Producer, closed <-chan stream.Event) {
	select {
	case <-p.closed:
		return
	case _, ok := <-closed:
		if !ok {
			return
		}
	}

	p.dropProducer(partition, producer)
}

func (p *superStreamProducer) dropProducer(partition string, producer *stream.Producer) {
	p.mu.Lock()
	current := p.producers[partition]
	if current == producer {
		delete(p.producers, partition)
	}
	p.mu.Unlock()
}

func uniquePartitions(partitions []string) []string {
	seen := make(map[string]struct{}, len(partitions))
	unique := make([]string, 0, len(partitions))
	for _, partition := range partitions {
		if partition == "" {
			continue
		}
		if _, ok := seen[partition]; ok {
			continue
		}
		seen[partition] = struct{}{}
		unique = append(unique, partition)
	}

	return unique
}

func shouldDropProducer(err error) bool {
	return errors.Is(err, stream.ConnectionClosed) ||
		errors.Is(err, stream.StreamNotAvailable) ||
		errors.Is(err, stream.LeaderNotReady) ||
		errors.Is(err, stream.InternalError) ||
		errors.Is(err, stream.ErrProducerNotFound)
}
