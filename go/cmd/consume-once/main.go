package main

import (
	"context"
	"flag"
	"log"
	"os"
	"time"

	"github.com/rabbitmq/rabbitmq-stream-go-client/pkg/amqp"
	"github.com/rabbitmq/rabbitmq-stream-go-client/pkg/ha"
	stream "github.com/rabbitmq/rabbitmq-stream-go-client/pkg/stream"
)

func main() {
	var (
		host        = flag.String("host", "127.0.0.1", "RabbitMQ stream host")
		port        = flag.Int("port", 5552, "RabbitMQ stream port")
		username    = flag.String("username", "guest", "RabbitMQ username")
		password    = flag.String("password", "guest", "RabbitMQ password")
		vhost       = flag.String("vhost", "/", "RabbitMQ vhost")
		superStream = flag.String("super-stream", "orders", "Super stream name")
		messageID   = flag.String("message-id", "", "Target message id")
		timeout     = flag.Duration("timeout", 10*time.Second, "Consume timeout")
	)
	flag.Parse()

	if *messageID == "" {
		log.Fatal("--message-id is required")
	}

	env, err := stream.NewEnvironment(stream.NewEnvironmentOptions().
		SetHost(*host).
		SetPort(*port).
		SetAddressResolver(stream.AddressResolver{
			Host: *host,
			Port: *port,
		}).
		SetUser(*username).
		SetPassword(*password).
		SetVHost(*vhost))
	if err != nil {
		log.Fatal(err)
	}
	defer env.Close()

	ctx, cancel := context.WithTimeout(context.Background(), *timeout)
	defer cancel()

	found := make(chan struct{}, 1)
	consumer, err := ha.NewReliableSuperStreamConsumer(env, *superStream, func(_ stream.ConsumerContext, message *amqp.Message) {
		if message.Properties != nil && message.Properties.MessageID == *messageID {
			select {
			case found <- struct{}{}:
			default:
			}
		}
	}, stream.NewSuperStreamConsumerOptions().SetOffset(stream.OffsetSpecification{}.First()))
	if err != nil {
		log.Fatal(err)
	}
	defer consumer.Close()

	select {
	case <-found:
		os.Exit(0)
	case <-ctx.Done():
		os.Exit(1)
	}
}
