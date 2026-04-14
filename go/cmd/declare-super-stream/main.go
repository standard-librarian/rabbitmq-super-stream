package main

import (
	"flag"
	"fmt"
	"log"
	"os"

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
		partitions  = flag.Int("partitions", 3, "Partition count")
	)
	flag.Parse()

	env, err := stream.NewEnvironment(stream.NewEnvironmentOptions().
		SetHost(*host).
		SetPort(*port).
		SetUser(*username).
		SetPassword(*password).
		SetVHost(*vhost))
	if err != nil {
		log.Fatal(err)
	}
	defer env.Close()

	err = env.DeclareSuperStream(*superStream, stream.NewPartitionsOptions(*partitions))
	if err != nil && err != stream.StreamAlreadyExists {
		log.Fatal(err)
	}

	_, _ = fmt.Fprintln(os.Stdout, "declared")
}
