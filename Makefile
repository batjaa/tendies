APP     := tendies
VERSION := $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -X main.version=$(VERSION)

.PHONY: build install test clean

build:
	go build -ldflags "$(LDFLAGS)" -o $(APP) ./cmd/tendies

install:
	go install -ldflags "$(LDFLAGS)" ./cmd/tendies

test:
	go test ./...

clean:
	rm -f $(APP)
