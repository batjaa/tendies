package schwab

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

const (
	RglBaseURL  = "https://ausgateway.schwab.com/api/is.RealizedGainLoss/V1/Rgl"
	AuthScopeURL = "https://client.schwab.com/api/auth/authorize/scope/api"
)

// RglClient calls the internal Schwab Realized Gain/Loss API
type RglClient struct {
	cookies   string
	accountID string
	client    *http.Client
}

type RglResponse struct {
	FromDate  string       `json:"fromDate"`
	ToDate    string       `json:"toDate"`
	Timestamp string       `json:"timestamp"`
	Accounts  []RglAccount `json:"accounts"`
	Totals    RglTotals    `json:"totals"`
}

type RglAccount struct {
	AccountID           string               `json:"accountId"`
	ClosingTransactions []ClosingTransaction `json:"closingTransactions"`
	Total               RglTotals            `json:"total"`
}

type ClosingTransaction struct {
	TransactionID string       `json:"transactionId"`
	Quantity      float64      `json:"quantity"`
	IsShort       bool         `json:"isShort"`
	CloseDate     string       `json:"closeDate"`
	TradeDate     string       `json:"tradeDate"`
	SymbolDetail  SymbolDetail `json:"symbolDetail"`
	CostBasis     CostBasis    `json:"costBasis"`
	GainLoss      GainLoss     `json:"gainLoss"`
	Proceeds      Proceeds     `json:"proceeds"`
}

type SymbolDetail struct {
	Symbol            string `json:"symbol"`
	Description       string `json:"description"`
	SecurityGroupCode string `json:"securityGroupCode"`
}

type CostBasis struct {
	CostBasis    float64 `json:"costBasis"`
	CostPerShare float64 `json:"costPerShare"`
}

type GainLoss struct {
	GainLoss        float64 `json:"gainLoss"`
	GainLossPercent float64 `json:"gainLossPercent"`
	DisallowedLoss  float64 `json:"disallowedLoss,omitempty"`
}

type Proceeds struct {
	ProceedAmount    float64 `json:"proceedAmount"`
	ProceedsPerShare float64 `json:"proceedsPerShare"`
}

type RglTotals struct {
	ProceedsAmount float64 `json:"proceedsAmount"`
	CostBasis      float64 `json:"costBasis"`
	GainLoss       float64 `json:"gainLoss"`
	TotalGains     float64 `json:"totalGains"`
	TotalLosses    float64 `json:"totalLosses"`
	NetGain        float64 `json:"netGain"`
}

func NewRglClient(cookies, accountID string) *RglClient {
	return &RglClient{
		cookies:   cookies,
		accountID: accountID,
		client:    &http.Client{Timeout: 30 * time.Second},
	}
}

// getToken fetches a fresh bearer token using cookies
func (c *RglClient) getToken() (string, error) {
	req, err := http.NewRequest("GET", AuthScopeURL, nil)
	if err != nil {
		return "", fmt.Errorf("failed to create auth request: %w", err)
	}

	req.Header.Set("Accept", "*/*")
	req.Header.Set("Cookie", c.cookies)
	req.Header.Set("Referer", "https://client.schwab.com/App/Accounts/RGL")
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36")

	resp, err := c.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("auth request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("auth error %d: %s", resp.StatusCode, string(body))
	}

	// Response is the bearer token as plain text
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read auth response: %w", err)
	}

	return string(body), nil
}

// GetRealizedGainLoss fetches realized gain/loss for the given date range
func (c *RglClient) GetRealizedGainLoss(fromDate, toDate time.Time) (*RglResponse, error) {
	// First, get a fresh token
	token, err := c.getToken()
	if err != nil {
		return nil, fmt.Errorf("failed to get auth token: %w", err)
	}

	url := fmt.Sprintf("%s?selectedTimeFrame=Custom&fromDate=%s&toDate=%s&filterKey=EquityAndOptionsForSymbol&hasPresto=true",
		RglBaseURL,
		fromDate.Format("01/02/2006"),
		toDate.Format("01/02/2006"),
	)

	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Accept", "application/json")
	req.Header.Set("Accept-Language", "en-US,en;q=0.9")
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Cache-Control", "no-cache")
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Origin", "https://client.schwab.com")
	req.Header.Set("Pragma", "no-cache")
	req.Header.Set("Referer", "https://client.schwab.com/")
	req.Header.Set("schwab-channelcode", "IO")
	req.Header.Set("schwab-client-appid", "AD00008376")
	req.Header.Set("schwab-client-channel", "IO")
	req.Header.Set("schwab-client-ids", c.accountID)
	req.Header.Set("schwab-env", "PROD")
	req.Header.Set("schwab-environment", "PROD")
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36")

	resp, err := c.client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("API error %d: %s", resp.StatusCode, string(body))
	}

	var rglResp RglResponse
	if err := json.NewDecoder(resp.Body).Decode(&rglResp); err != nil {
		return nil, fmt.Errorf("failed to decode response: %w", err)
	}

	return &rglResp, nil
}
