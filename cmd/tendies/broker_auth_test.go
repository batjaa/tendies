package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/batjaa/tendies/internal/config"
	"github.com/zalando/go-keyring"
)

func TestBrokerRefreshSurvivesNextInvocation(t *testing.T) {
	keyring.MockInit()
	refreshes := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/oauth/token" {
			refreshes++
			if r.FormValue("refresh_token") != "old-refresh" || refreshes != 1 {
				http.Error(w, "revoked refresh token", http.StatusUnauthorized)
				return
			}
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600}`))
			return
		}
		if r.Header.Get("Authorization") != "Bearer new-access" {
			http.Error(w, "stale access token", 401)
			return
		}
		_, _ = w.Write([]byte(`[]`))
	}))
	defer server.Close()
	if err := config.SaveBrokerToken(&config.BrokerToken{AccessToken: "old-access", RefreshToken: "old-refresh", Expiry: time.Now().Add(-time.Hour)}); err != nil {
		t.Fatal(err)
	}
	cfg := &config.Config{BrokerURL: server.URL, BrokerClientID: "test"}
	for i := 0; i < 2; i++ {
		client, err := buildBrokerClient(cfg)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := client.GetAccountNumbers(context.Background()); err != nil {
			t.Fatalf("invocation %d: %v", i, err)
		}
	}
	saved, err := config.LoadBrokerToken()
	if err != nil {
		t.Fatal(err)
	}
	if refreshes != 1 || saved.RefreshToken != "new-refresh" || saved.Expiry.Before(time.Now()) {
		t.Fatalf("rotation not persisted: refresh count=%d", refreshes)
	}
}

func TestBrokerRefreshStopsOnKeychainFailure(t *testing.T) {
	want := errors.New("keychain unavailable")
	keyring.MockInitWithError(want)
	t.Cleanup(keyring.MockInit)
	apiCalled := false
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/oauth/token" {
			apiCalled = true
		}
		_, _ = w.Write([]byte(`{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600}`))
	}))
	defer server.Close()
	client := newBrokerClient(&config.Config{BrokerURL: server.URL, BrokerClientID: "test"})
	client.RefreshToken = "old-refresh"
	_, err := client.GetAccountNumbers(context.Background())
	if !errors.Is(err, want) || apiCalled {
		t.Fatalf("must stop when persistence fails: err=%v apiCalled=%v", err, apiCalled)
	}
}
