package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net/url"
	"testing"

	"golang.org/x/oauth2"
)

type fakeDirectOAuth struct {
	input       bytes.Buffer
	callback    func(string) string
	exchanged   string
	exchangeErr error
}

func (f *fakeDirectOAuth) GetAuthURL(state string) string {
	f.input.WriteString(f.callback(state))
	return "https://example.com/authorize?state=" + state
}

func (f *fakeDirectOAuth) ExchangeCode(_ context.Context, code string) (*oauth2.Token, error) {
	f.exchanged = code
	return &oauth2.Token{AccessToken: "test-access", RefreshToken: "test-refresh"}, f.exchangeErr
}

func TestDirectOAuthValidatesCallbackBeforeExchanging(t *testing.T) {
	for _, tc := range []struct {
		name, query, host string
		wantErr           bool
	}{
		{"success", "code=encoded%2Bcode%2Fvalue", "127.0.0.1:8443", false},
		{"wrong host", "code=code", "example.com", true},
		{"missing code", "", "127.0.0.1:8443", true},
		{"denied", "error=access_denied", "127.0.0.1:8443", true},
		{"wrong state", "code=code&state=wrong", "127.0.0.1:8443", true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			client := &fakeDirectOAuth{callback: func(state string) string {
				q, _ := url.ParseQuery(tc.query)
				if !q.Has("state") {
					q.Set("state", state)
				}
				return "https://" + tc.host + "/callback?" + q.Encode()
			}}
			token, err := authorizeDirect(context.Background(), client, "https://127.0.0.1:8443/callback", &client.input, io.Discard)
			if (err != nil) != tc.wantErr {
				t.Fatalf("unexpected result: token=%v err=%v", token, err)
			}
			if tc.wantErr && client.exchanged != "" {
				t.Fatal("exchanged code from invalid callback")
			}
			if !tc.wantErr && (client.exchanged != "encoded+code/value" || token.RefreshToken != "test-refresh") {
				t.Fatal("code or token corrupted")
			}
		})
	}
}

func TestDirectOAuthPropagatesExchangeFailure(t *testing.T) {
	want := errors.New("token endpoint unavailable")
	client := &fakeDirectOAuth{exchangeErr: want, callback: func(state string) string {
		return "https://127.0.0.1:8443/callback?code=test&state=" + state
	}}
	_, err := authorizeDirect(context.Background(), client, "https://127.0.0.1:8443/callback", &client.input, io.Discard)
	if !errors.Is(err, want) {
		t.Fatalf("lost exchange error: %v", err)
	}
}
