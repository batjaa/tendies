package broker

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"bytes"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"github.com/batjaa/tendies/internal/schwab"
)

const (
	retryAttempts  = 3
	retryBaseDelay = 1 * time.Second
)

// SubscriptionError indicates the user's subscription/trial has expired.
type SubscriptionError struct {
	Message string
}

func (e *SubscriptionError) Error() string {
	return e.Message
}

// RateLimitError indicates the user has exceeded their daily query limit.
type RateLimitError struct {
	Message string
}

func (e *RateLimitError) Error() string {
	return e.Message
}

// TimeframeError indicates the requested timeframe exceeds the user's tier limit.
type TimeframeError struct {
	Message string
}

func (e *TimeframeError) Error() string {
	return e.Message
}

// AccountLimitError indicates the user cannot link more accounts on their tier.
type AccountLimitError struct {
	Message string
}

func (e *AccountLimitError) Error() string {
	return e.Message
}

// apiError is the standard error response shape from the broker backend.
type apiError struct {
	Error   string `json:"error"`
	Message string `json:"message"`
}

// Client talks to the tendies broker backend and implements datasource.DataSource.
type Client struct {
	BrokerURL    string
	ClientID     string
	AccessToken  string
	RefreshToken string
	TokenExpiry  time.Time
	QueryID      string // Unique per CLI invocation; sent as X-Query-ID for rate limiting.
	Timezone     string // Local timezone name; sent as X-Timezone for rate limit day boundary.
	httpClient   *http.Client
}

type tokenResponse struct {
	AccessToken  string `json:"access_token"`
	TokenType    string `json:"token_type"`
	ExpiresIn    int    `json:"expires_in"`
	RefreshToken string `json:"refresh_token"`
}

func NewClient(brokerURL, clientID string) *Client {
	return &Client{
		BrokerURL:  strings.TrimRight(brokerURL, "/"),
		ClientID:   clientID,
		httpClient: &http.Client{Timeout: 30 * time.Second},
	}
}

// Login performs OAuth Authorization Code + PKCE flow:
// 1. Generate PKCE verifier/challenge
// 2. Start local HTTP server
// 3. Open browser to Passport authorize URL
// 4. Receive callback with auth code
// 5. Exchange code for tokens
func (c *Client) Login(ctx context.Context) error {
	verifier, challenge, err := generatePKCE()
	if err != nil {
		return fmt.Errorf("failed to generate PKCE: %w", err)
	}

	state, err := randomHex(16)
	if err != nil {
		return fmt.Errorf("failed to generate state: %w", err)
	}

	// Start local server on a random port.
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return fmt.Errorf("failed to start local server: %w", err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	redirectURI := fmt.Sprintf("http://127.0.0.1:%d/callback", port)

	codeCh := make(chan string, 1)
	errCh := make(chan error, 1)

	mux := http.NewServeMux()
	mux.HandleFunc("/callback", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("state") != state {
			errCh <- fmt.Errorf("state mismatch")
			http.Error(w, "State mismatch", http.StatusForbidden)
			return
		}
		code := r.URL.Query().Get("code")
		if code == "" {
			errCh <- fmt.Errorf("missing code in callback")
			http.Error(w, "Missing code", http.StatusBadRequest)
			return
		}
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		fmt.Fprint(w, `<!DOCTYPE html>
<html><head><title>Tendies</title></head>
<body style="font-family:-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#1a1a1a;color:#fff">
<div style="text-align:center">
<p style="font-size:3rem;margin:0 0 0.5rem">&#127831;</p>
<h1 style="color:#22c55e;margin:0 0 0.5rem">Login successful</h1>
<p style="color:#888">Return to your terminal. You can close this tab.</p>
</div>
</body></html>`)
		if f, ok := w.(http.Flusher); ok {
			f.Flush()
		}
		codeCh <- code
	})

	server := &http.Server{Handler: mux}
	go func() {
		if serveErr := server.Serve(listener); serveErr != nil && serveErr != http.ErrServerClosed {
			errCh <- serveErr
		}
	}()
	defer func() {
		// Give the browser time to receive the response before shutting down.
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		server.Shutdown(shutdownCtx)
	}()

	// Build and open authorize URL.
	authURL := fmt.Sprintf("%s/oauth/authorize?%s", c.BrokerURL, url.Values{
		"client_id":             {c.ClientID},
		"redirect_uri":         {redirectURI},
		"response_type":        {"code"},
		"code_challenge":       {challenge},
		"code_challenge_method": {"S256"},
		"state":                {state},
	}.Encode())

	fmt.Println("Opening browser for login...")
	fmt.Printf("If the browser doesn't open, visit:\n%s\n\n", authURL)
	OpenBrowser(authURL)

	// Wait for callback.
	var code string
	select {
	case code = <-codeCh:
	case err := <-errCh:
		return fmt.Errorf("login callback error: %w", err)
	case <-ctx.Done():
		return ctx.Err()
	}

	// Exchange code for tokens.
	tokenData, err := c.exchangeCode(ctx, code, verifier, redirectURI)
	if err != nil {
		return fmt.Errorf("token exchange failed: %w", err)
	}

	c.AccessToken = tokenData.AccessToken
	c.RefreshToken = tokenData.RefreshToken
	c.TokenExpiry = time.Now().Add(time.Duration(tokenData.ExpiresIn) * time.Second)

	return nil
}

func (c *Client) exchangeCode(ctx context.Context, code, verifier, redirectURI string) (*tokenResponse, error) {
	data := url.Values{
		"grant_type":    {"authorization_code"},
		"client_id":     {c.ClientID},
		"code":          {code},
		"redirect_uri":  {redirectURI},
		"code_verifier": {verifier},
	}

	req, err := http.NewRequestWithContext(ctx, "POST", c.BrokerURL+"/oauth/token", strings.NewReader(data.Encode()))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("token endpoint returned %d: %s", resp.StatusCode, string(body))
	}

	var tok tokenResponse
	if err := json.NewDecoder(resp.Body).Decode(&tok); err != nil {
		return nil, fmt.Errorf("failed to decode token response: %w", err)
	}
	return &tok, nil
}

// RefreshAccessToken refreshes the Passport access token.
func (c *Client) RefreshAccessToken(ctx context.Context) error {
	data := url.Values{
		"grant_type":    {"refresh_token"},
		"client_id":     {c.ClientID},
		"refresh_token": {c.RefreshToken},
	}

	req, err := http.NewRequestWithContext(ctx, "POST", c.BrokerURL+"/oauth/token", strings.NewReader(data.Encode()))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("token refresh returned %d: %s", resp.StatusCode, string(body))
	}

	var tok tokenResponse
	if err := json.NewDecoder(resp.Body).Decode(&tok); err != nil {
		return fmt.Errorf("failed to decode refresh response: %w", err)
	}

	c.AccessToken = tok.AccessToken
	if tok.RefreshToken != "" {
		c.RefreshToken = tok.RefreshToken
	}
	c.TokenExpiry = time.Now().Add(time.Duration(tok.ExpiresIn) * time.Second)
	return nil
}

func (c *Client) ensureValidToken(ctx context.Context) error {
	if time.Now().Before(c.TokenExpiry.Add(-30 * time.Second)) {
		return nil
	}
	if c.RefreshToken == "" {
		return fmt.Errorf("session expired; run `tendies account login` to re-authenticate")
	}
	return c.RefreshAccessToken(ctx)
}

func (c *Client) doGet(ctx context.Context, path string, query url.Values) ([]byte, error) {
	if err := c.ensureValidToken(ctx); err != nil {
		return nil, err
	}

	reqURL := c.BrokerURL + path
	if len(query) > 0 {
		reqURL += "?" + query.Encode()
	}

	var lastErr error
	for attempt := range retryAttempts {
		req, err := http.NewRequestWithContext(ctx, "GET", reqURL, nil)
		if err != nil {
			return nil, err
		}
		req.Header.Set("Authorization", "Bearer "+c.AccessToken)
		req.Header.Set("Accept", "application/json")
		if c.QueryID != "" {
			req.Header.Set("X-Query-ID", c.QueryID)
		}
		if c.Timezone != "" {
			req.Header.Set("X-Timezone", c.Timezone)
		}

		resp, err := c.httpClient.Do(req)
		if err != nil {
			lastErr = err
			if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
				return nil, err
			}
			if attempt < retryAttempts-1 {
				time.Sleep(retryBaseDelay * time.Duration(1<<attempt))
				continue
			}
			return nil, lastErr
		}

		body, readErr := io.ReadAll(resp.Body)
		resp.Body.Close()
		if readErr != nil {
			return nil, readErr
		}

		// Retry on 5xx server errors.
		if resp.StatusCode >= 500 {
			lastErr = fmt.Errorf("broker API error %d: %s", resp.StatusCode, string(body))
			if attempt < retryAttempts-1 {
				time.Sleep(retryBaseDelay * time.Duration(1<<attempt))
				continue
			}
			return nil, lastErr
		}

		// Don't retry 4xx.
		if resp.StatusCode == http.StatusTooManyRequests {
			var errResp apiError
			if json.Unmarshal(body, &errResp) == nil && errResp.Error == "rate_limit_exceeded" {
				return nil, &RateLimitError{Message: errResp.Message}
			}
		}

		if resp.StatusCode == http.StatusForbidden {
			var errResp apiError
			if json.Unmarshal(body, &errResp) == nil {
				switch errResp.Error {
				case "subscription_required":
					return nil, &SubscriptionError{Message: errResp.Message}
				case "timeframe_restricted":
					return nil, &TimeframeError{Message: errResp.Message}
				case "account_limit_reached":
					return nil, &AccountLimitError{Message: errResp.Message}
				}
			}
		}

		if resp.StatusCode == http.StatusUnauthorized {
			var errResp apiError
			if json.Unmarshal(body, &errResp) == nil && errResp.Message != "" {
				return nil, fmt.Errorf("%s", errResp.Message)
			}
			return nil, fmt.Errorf("session expired; run `tendies account login` to re-authenticate")
		}

		if resp.StatusCode != http.StatusOK {
			msg := string(body)
			if len(msg) > 200 {
				msg = msg[:200] + "..."
			}
			return nil, fmt.Errorf("broker API error %d: %s", resp.StatusCode, msg)
		}
		return body, nil
	}
	return nil, lastErr
}

// GetAccountNumbers implements datasource.DataSource.
func (c *Client) GetAccountNumbers(ctx context.Context) ([]schwab.AccountNumber, error) {
	body, err := c.doGet(ctx, "/api/v1/accounts", nil)
	if err != nil {
		return nil, fmt.Errorf("failed to get accounts from broker: %w", err)
	}

	var accounts []schwab.AccountNumber
	if err := json.Unmarshal(body, &accounts); err != nil {
		return nil, fmt.Errorf("failed to decode accounts: %w", err)
	}
	return accounts, nil
}

// GetTransactions implements datasource.DataSource.
func (c *Client) GetTransactions(ctx context.Context, accountHash string, startDate, endDate time.Time, txnType string) ([]schwab.Transaction, error) {
	query := url.Values{
		"account_hash": {accountHash},
		"start":        {startDate.Format(time.RFC3339)},
		"end":          {endDate.Format(time.RFC3339)},
	}
	if txnType != "" {
		query.Set("types", txnType)
	}

	body, err := c.doGet(ctx, "/api/v1/transactions", query)
	if err != nil {
		return nil, fmt.Errorf("failed to get transactions from broker: %w", err)
	}

	var txns []schwab.Transaction
	if err := json.Unmarshal(body, &txns); err != nil {
		return nil, fmt.Errorf("failed to decode transactions: %w", err)
	}
	return txns, nil
}

// AccountStatus holds the response from GET /v1/me.
type AccountStatus struct {
	User struct {
		ID    int    `json:"id"`
		Name  string `json:"name"`
		Email string `json:"email"`
		Tier  string `json:"tier"`
	} `json:"user"`
	LinkedAccounts int     `json:"linked_accounts"`
	TrialEndsAt    *string `json:"trial_ends_at"`
	Subscription   *struct {
		Plan   string `json:"plan"`
		Status string `json:"status"`
	} `json:"subscription"`
}

// GetStatus fetches the current user's account info.
func (c *Client) GetStatus(ctx context.Context) (*AccountStatus, error) {
	body, err := c.doGet(ctx, "/api/v1/me", nil)
	if err != nil {
		return nil, err
	}
	var status AccountStatus
	if err := json.Unmarshal(body, &status); err != nil {
		return nil, fmt.Errorf("failed to decode status: %w", err)
	}
	return &status, nil
}

// InitiateLink starts a new account linking session and returns the authorize URL.
func (c *Client) InitiateLink(ctx context.Context, provider string) (string, error) {
	if err := c.ensureValidToken(ctx); err != nil {
		return "", err
	}

	payload, err := json.Marshal(map[string]string{"provider": provider})
	if err != nil {
		return "", err
	}

	req, err := http.NewRequestWithContext(ctx, "POST", c.BrokerURL+"/api/v1/link/initiate", bytes.NewReader(payload))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.AccessToken)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", err
	}

	if resp.StatusCode == http.StatusForbidden {
		var errResp apiError
		if json.Unmarshal(body, &errResp) == nil && errResp.Error == "account_limit_reached" {
			return "", &AccountLimitError{Message: errResp.Message}
		}
	}

	if resp.StatusCode != http.StatusOK {
		msg := string(body)
		if len(msg) > 200 {
			msg = msg[:200] + "..."
		}
		return "", fmt.Errorf("link initiate failed (%d): %s", resp.StatusCode, msg)
	}

	var result struct {
		AuthorizeURL string `json:"authorize_url"`
	}
	if err := json.Unmarshal(body, &result); err != nil {
		return "", fmt.Errorf("failed to decode link response: %w", err)
	}
	return result.AuthorizeURL, nil
}

// AuthResponse is the response from register/login endpoints.
type AuthResponse struct {
	Token string `json:"token"`
	User  struct {
		ID    int    `json:"id"`
		Name  string `json:"name"`
		Email string `json:"email"`
		Tier  string `json:"tier"`
	} `json:"user"`
}

// Register creates a new account via email/password and returns a PAT.
func (c *Client) Register(ctx context.Context, name, email, password string) (*AuthResponse, error) {
	return c.postAuth(ctx, "/api/auth/register", map[string]string{
		"name":                  name,
		"email":                 email,
		"password":              password,
		"password_confirmation": password,
	})
}

// AuthLogin authenticates with email/password and returns a PAT.
func (c *Client) AuthLogin(ctx context.Context, email, password string) (*AuthResponse, error) {
	return c.postAuth(ctx, "/api/auth/login", map[string]string{
		"email":    email,
		"password": password,
	})
}

// postAuth sends an unauthenticated POST to an auth endpoint.
func (c *Client) postAuth(ctx context.Context, path string, payload map[string]string) (*AuthResponse, error) {
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, "POST", c.BrokerURL+path, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}

	if resp.StatusCode == http.StatusUnprocessableEntity {
		var valErr struct {
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		if json.Unmarshal(respBody, &valErr) == nil {
			// Return the first validation error for a clean CLI message.
			for _, msgs := range valErr.Errors {
				if len(msgs) > 0 {
					return nil, fmt.Errorf("%s", msgs[0])
				}
			}
			return nil, fmt.Errorf("%s", valErr.Message)
		}
	}

	if resp.StatusCode == http.StatusUnauthorized {
		var errResp struct {
			Message string `json:"message"`
		}
		if json.Unmarshal(respBody, &errResp) == nil && errResp.Message != "" {
			return nil, fmt.Errorf("%s", errResp.Message)
		}
		return nil, fmt.Errorf("invalid credentials")
	}

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusCreated {
		msg := string(respBody)
		if len(msg) > 200 {
			msg = msg[:200] + "..."
		}
		return nil, fmt.Errorf("auth request failed (%d): %s", resp.StatusCode, msg)
	}

	var result AuthResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, fmt.Errorf("failed to decode auth response: %w", err)
	}
	return &result, nil
}

// PKCE helpers

func generatePKCE() (verifier, challenge string, err error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", "", err
	}
	verifier = base64.RawURLEncoding.EncodeToString(b)
	h := sha256.Sum256([]byte(verifier))
	challenge = base64.RawURLEncoding.EncodeToString(h[:])
	return verifier, challenge, nil
}

func randomHex(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// OpenBrowser opens the given URL in the user's default browser.
func OpenBrowser(url string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "darwin":
		cmd = exec.Command("open", url)
	case "linux":
		cmd = exec.Command("xdg-open", url)
	default:
		return
	}
	_ = cmd.Start()
}
