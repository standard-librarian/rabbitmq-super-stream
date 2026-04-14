SHELL := /bin/sh

.PHONY: test test-unit test-integration build-helper build-release-binaries fmt go-test docker-up docker-down

test: test-unit

test-unit:
	composer test

test-integration:
	composer test:integration

build-helper:
	./scripts/build-helper.sh

build-release-binaries:
	./scripts/release-binaries.sh

fmt:
	cd go && gofmt -w ./...

go-test:
	cd go && go test ./...

docker-up:
	docker compose -f docker/compose.yml up -d
	./docker/setup.sh

docker-down:
	docker compose -f docker/compose.yml down -v
