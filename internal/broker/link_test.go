package broker

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestLinkCompletesOnlyAfterItsCallback(t *testing.T) {
	polls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer token" {
			t.Error("missing authentication")
		}
		switch r.URL.Path {
		case "/api/v1/link/initiate":
			if r.Method != http.MethodPost {
				t.Error("expected POST")
			}
			fmt.Fprint(w, `{"link_session_id":"attempt-id","authorize_url":"https://example.test/auth/link/attempt-id"}`)
		case "/api/v1/link/attempt-id/status":
			polls++
			if polls < 3 {
				fmt.Fprint(w, `{"status":"pending"}`)
			} else {
				fmt.Fprint(w, `{"status":"linked"}`)
			}
		default:
			t.Errorf("unexpected request to %s", r.URL.Path)
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	c := NewClient(srv.URL, "client")
	c.AccessToken = "token"
	c.TokenExpiry = time.Now().Add(time.Hour)
	session, err := c.InitiateLink(context.Background(), "schwab")
	if err != nil {
		t.Fatal(err)
	}
	if session.ID != "attempt-id" || session.AuthorizeURL != "https://example.test/auth/link/attempt-id" {
		t.Fatalf("unexpected session: %+v", session)
	}
	if err := c.waitForLink(context.Background(), session.ID, time.Millisecond); err != nil {
		t.Fatal(err)
	}
	if polls != 3 {
		t.Fatalf("completed before callback: %d polls", polls)
	}
}

func TestLinkWaitFailures(t *testing.T) {
	for _, tc := range []struct {
		name, body, want string
		status           int
	}{
		{"denied", `{"status":"failed"}`, "did not complete", 200},
		{"expired", `{"message":"Link session expired"}`, "410", 410},
		{"unauthenticated", `{"message":"Unauthenticated"}`, "Unauthenticated", 401},
		{"unknown", `{"status":"other"}`, "unexpected link status", 200},
		{"malformed", `oops`, "decode link status", 200},
	} {
		t.Run(tc.name, func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(tc.status)
				fmt.Fprint(w, tc.body)
			}))
			defer srv.Close()
			c := NewClient(srv.URL, "client")
			c.AccessToken = "token"
			c.TokenExpiry = time.Now().Add(time.Hour)
			err := c.waitForLink(context.Background(), "attempt", time.Millisecond)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("got %v, want %s", err, tc.want)
			}
		})
	}
}

func TestLinkWaitHonorsContext(t *testing.T) {
	for _, timeout := range []bool{false, true} {
		t.Run(fmt.Sprint("timeout=", timeout), func(t *testing.T) {
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			want := context.Canceled
			if timeout {
				var stop context.CancelFunc
				ctx, stop = context.WithTimeout(ctx, 30*time.Millisecond)
				defer stop()
				want = context.DeadlineExceeded
			}
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				fmt.Fprint(w, `{"status":"pending"}`)
				if !timeout {
					cancel()
				}
			}))
			defer srv.Close()
			c := NewClient(srv.URL, "client")
			c.AccessToken = "token"
			c.TokenExpiry = time.Now().Add(time.Hour)
			if err := c.waitForLink(ctx, "attempt", time.Hour); !errors.Is(err, want) {
				t.Fatalf("got %v, want %v", err, want)
			}
		})
	}
}
