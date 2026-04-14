package main

import (
	"flag"
	"fmt"
	"os"

	"github.com/stream-lib/rabbitmq-super-stream/go-helper/internal/app"
)

func main() {
	if len(os.Args) < 2 || os.Args[1] != "serve" {
		_, _ = fmt.Fprintln(os.Stderr, "usage: rabbitmq-super-stream-helper serve --config /path/to/config.json")
		os.Exit(2)
	}

	serveCommand := flag.NewFlagSet("serve", flag.ExitOnError)
	configPath := serveCommand.String("config", "", "Path to the helper configuration file")
	_ = serveCommand.Parse(os.Args[2:])

	if *configPath == "" {
		_, _ = fmt.Fprintln(os.Stderr, "--config is required")
		os.Exit(2)
	}

	if err := app.Run(*configPath); err != nil {
		_, _ = fmt.Fprintln(os.Stderr, err.Error())
		os.Exit(1)
	}
}
