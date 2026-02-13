package schwab

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"golang.org/x/oauth2"
)

const (
	BaseURL     = "https://api.schwabapi.com/trader/v1"
	AuthURL     = "https://api.schwabapi.com/v1/oauth/authorize"
	TokenURL    = "https://api.schwabapi.com/v1/oauth/token"
)

type Client struct {
	httpClient *http.Client
	config     *oauth2.Config
}

type AccountNumber struct {
	AccountNumber string `json:"accountNumber"`
	HashValue     string `json:"hashValue"`
}

type Transaction struct {
	ActivityID    int64             `json:"activityId"`
	Time          string            `json:"time"`
	Type          string            `json:"type"`
	Status        string            `json:"status"`
	SubAccount    string            `json:"subAccount"`
	TradeDate     string            `json:"tradeDate"`
	PositionID    int64             `json:"positionId"`
	OrderID       int64             `json:"orderId"`
	NetAmount     float64           `json:"netAmount"`
	TransferItems []TransactionItem `json:"transferItems"`
}

type TransactionItem struct {
	Instrument     Instrument `json:"instrument"`
	Amount         float64    `json:"amount"`
	Cost           float64    `json:"cost"`
	Price          float64    `json:"price"`
	FeeType        string     `json:"feeType,omitempty"`
	PositionEffect string     `json:"positionEffect,omitempty"`
}

type Instrument struct {
	AssetType   string `json:"assetType"`
	Cusip       string `json:"cusip"`
	Symbol      string `json:"symbol"`
	Description string `json:"description"`
}

func NewClient(clientID, clientSecret, redirectURL string) *Client {
	config := &oauth2.Config{
		ClientID:     clientID,
		ClientSecret: clientSecret,
		Endpoint: oauth2.Endpoint{
			AuthURL:  AuthURL,
			TokenURL: TokenURL,
		},
		RedirectURL: redirectURL,
	}

	return &Client{
		config: config,
	}
}

// GetAuthURL returns the URL to redirect users for OAuth authorization
func (c *Client) GetAuthURL(state string) string {
	return c.config.AuthCodeURL(state, oauth2.AccessTypeOffline)
}

// ExchangeCode exchanges an authorization code for tokens
func (c *Client) ExchangeCode(ctx context.Context, code string) (*oauth2.Token, error) {
	return c.config.Exchange(ctx, code)
}

// SetToken sets the OAuth token and creates an authenticated HTTP client
func (c *Client) SetToken(token *oauth2.Token) {
	c.httpClient = c.config.Client(context.Background(), token)
}

// RefreshToken refreshes the access token using the refresh token
func (c *Client) RefreshToken(ctx context.Context, token *oauth2.Token) (*oauth2.Token, error) {
	tokenSource := c.config.TokenSource(ctx, token)
	return tokenSource.Token()
}

// GetAccountNumbers retrieves account numbers and their hash values
func (c *Client) GetAccountNumbers(ctx context.Context) ([]AccountNumber, error) {
	resp, err := c.httpClient.Get(BaseURL + "/accounts/accountNumbers")
	if err != nil {
		return nil, fmt.Errorf("failed to get account numbers: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("API error %d: %s", resp.StatusCode, string(body))
	}

	var accounts []AccountNumber
	if err := json.NewDecoder(resp.Body).Decode(&accounts); err != nil {
		return nil, fmt.Errorf("failed to decode response: %w", err)
	}

	return accounts, nil
}

// GetTransactions retrieves transactions for a given account hash
func (c *Client) GetTransactions(ctx context.Context, accountHash string, startDate, endDate time.Time, txnType string) ([]Transaction, error) {
	params := url.Values{}
	params.Set("startDate", startDate.Format(time.RFC3339))
	params.Set("endDate", endDate.Format(time.RFC3339))
	if txnType != "" {
		params.Set("types", txnType)
	}

	reqURL := fmt.Sprintf("%s/accounts/%s/transactions?%s", BaseURL, accountHash, params.Encode())
	resp, err := c.httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("failed to get transactions: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("API error %d: %s", resp.StatusCode, string(body))
	}

	var transactions []Transaction
	if err := json.NewDecoder(resp.Body).Decode(&transactions); err != nil {
		return nil, fmt.Errorf("failed to decode response: %w", err)
	}

	return transactions, nil
}

// GetAccountRaw retrieves raw account JSON with positions for debugging
func (c *Client) GetAccountRaw(ctx context.Context, accountHash string) ([]byte, error) {
	reqURL := fmt.Sprintf("%s/accounts/%s?fields=positions", BaseURL, accountHash)
	resp, err := c.httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("failed to get account: %w", err)
	}
	defer resp.Body.Close()
	return io.ReadAll(resp.Body)
}

// GetAccountDetailsRaw retrieves raw account detail JSON for a specific account hash.
func (c *Client) GetAccountDetailsRaw(ctx context.Context, accountHash string) ([]byte, error) {
	reqURL := fmt.Sprintf("%s/accounts/%s", BaseURL, accountHash)
	resp, err := c.httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("failed to get account details: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("API error %d: %s", resp.StatusCode, string(body))
	}
	return io.ReadAll(resp.Body)
}

// GetTransactionsRaw retrieves raw JSON for debugging
func (c *Client) GetTransactionsRaw(ctx context.Context, accountHash string, startDate, endDate time.Time, txnType string) ([]byte, error) {
	params := url.Values{}
	params.Set("startDate", startDate.Format(time.RFC3339))
	params.Set("endDate", endDate.Format(time.RFC3339))
	if txnType != "" {
		params.Set("types", txnType)
	}

	reqURL := fmt.Sprintf("%s/accounts/%s/transactions?%s", BaseURL, accountHash, params.Encode())
	resp, err := c.httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("failed to get transactions: %w", err)
	}
	defer resp.Body.Close()

	return io.ReadAll(resp.Body)
}
