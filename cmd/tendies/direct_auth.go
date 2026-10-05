package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"strings"

	"github.com/batjaa/tendies/internal/config"
	"github.com/batjaa/tendies/internal/schwab"
	"golang.org/x/oauth2"
)

type directOAuthClient interface {
	GetAuthURL(string) string
	ExchangeCode(context.Context, string) (*oauth2.Token, error)
}

func runDirectLogin() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	if strings.TrimSpace(cfg.ClientID) == "" || strings.TrimSpace(cfg.ClientSecret) == "" || strings.TrimSpace(cfg.RedirectURL) == "" {
		return errors.New("missing Schwab credentials or redirect_url; run tendies --config and set client_id, client_secret, and redirect_url")
	}
	client := schwab.NewClient(cfg.ClientID, cfg.ClientSecret, cfg.RedirectURL)
	token, err := authorizeDirect(context.Background(), client, cfg.RedirectURL, os.Stdin, os.Stdout)
	if err != nil {
		return err
	}
	if err := config.SaveToken(token); err != nil {
		return fmt.Errorf("failed to save Schwab token: %w", err)
	}
	fmt.Println("Schwab connected. Run `tendies --direct --day` to view P&L.")
	return nil
}

func authorizeDirect(ctx context.Context, client directOAuthClient, redirectURL string, input io.Reader, output io.Writer) (*oauth2.Token, error) {
	expected, err := url.Parse(redirectURL)
	if err != nil || expected.Scheme == "" || expected.Host == "" {
		return nil, errors.New("invalid redirect_url in config")
	}
	state, err := randomState()
	if err != nil {
		return nil, err
	}
	fmt.Fprintf(output, "Open this URL in your browser:\n%s\n\nAfter authorizing, paste the full callback URL here (the callback page need not load):\n", client.GetAuthURL(state))
	line, err := bufio.NewReader(input).ReadString('\n')
	if err != nil && (err != io.EOF || strings.TrimSpace(line) == "") {
		return nil, fmt.Errorf("failed to read callback URL: %w", err)
	}
	callback, err := url.Parse(strings.TrimSpace(line))
	if err != nil || callback.Scheme != expected.Scheme || callback.Host != expected.Host || callback.Path != expected.Path {
		return nil, errors.New("callback URL does not match configured redirect_url")
	}
	query := callback.Query()
	if query.Get("state") != state {
		return nil, errors.New("OAuth state mismatch; retry `tendies account link --direct`")
	}
	if query.Get("error") != "" {
		return nil, errors.New("Schwab authorization was denied; retry `tendies account link --direct`")
	}
	if query.Get("code") == "" {
		return nil, errors.New("callback URL is missing an authorization code")
	}
	token, err := client.ExchangeCode(ctx, query.Get("code"))
	if err != nil {
		return nil, fmt.Errorf("Schwab token exchange failed: %w", err)
	}
	return token, nil
}
