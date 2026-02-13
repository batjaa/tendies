package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/zalando/go-keyring"
	"golang.org/x/oauth2"
)

const (
	ServiceName = "tendies"
	ConfigDir   = ".tendies"
	ConfigFile  = "config.json"
)

type Config struct {
	ClientID         string                           `json:"client_id"`
	ClientSecret     string                           `json:"client_secret"`
	RedirectURL      string                           `json:"redirect_url"`
	Accounts         []string                         `json:"accounts,omitempty"` // Selected account hashes
	Instruments      []string                         `json:"instruments,omitempty"`
	RefreshMins      int                              `json:"refresh_mins"`
	DisplayNameCache map[string]DisplayNameCacheEntry `json:"display_name_cache,omitempty"`
	// Internal Schwab web API (for realized gain/loss)
	SchwabWebToken  string `json:"schwab_web_token,omitempty"`
	SchwabAccountID string `json:"schwab_account_id,omitempty"`
	SchwabCookies   string `json:"schwab_cookies,omitempty"` // Full cookie string from browser
}

type DisplayNameCacheEntry struct {
	Name      string `json:"name"`
	UpdatedAt string `json:"updated_at"`
}

type StoredToken struct {
	AccessToken  string    `json:"access_token"`
	TokenType    string    `json:"token_type"`
	RefreshToken string    `json:"refresh_token"`
	Expiry       time.Time `json:"expiry"`
}

func GetConfigPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", fmt.Errorf("failed to get home directory: %w", err)
	}
	return filepath.Join(home, ConfigDir, ConfigFile), nil
}

func Load() (*Config, error) {
	path, err := GetConfigPath()
	if err != nil {
		return nil, err
	}

	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return &Config{
				RefreshMins: 1,
				RedirectURL: "https://127.0.0.1:8443/callback",
			}, nil
		}
		return nil, fmt.Errorf("failed to read config: %w", err)
	}

	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("failed to parse config: %w", err)
	}

	return &cfg, nil
}

func (c *Config) Save() error {
	path, err := GetConfigPath()
	if err != nil {
		return err
	}

	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("failed to create config directory: %w", err)
	}

	data, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return fmt.Errorf("failed to marshal config: %w", err)
	}

	if err := os.WriteFile(path, data, 0600); err != nil {
		return fmt.Errorf("failed to write config: %w", err)
	}

	return nil
}

// SaveToken stores the OAuth token securely in the system keychain
func SaveToken(token *oauth2.Token) error {
	stored := StoredToken{
		AccessToken:  token.AccessToken,
		TokenType:    token.TokenType,
		RefreshToken: token.RefreshToken,
		Expiry:       token.Expiry,
	}

	data, err := json.Marshal(stored)
	if err != nil {
		return fmt.Errorf("failed to marshal token: %w", err)
	}

	if err := keyring.Set(ServiceName, "oauth_token", string(data)); err != nil {
		return fmt.Errorf("failed to save token to keychain: %w", err)
	}

	return nil
}

// LoadToken retrieves the OAuth token from the system keychain
func LoadToken() (*oauth2.Token, error) {
	data, err := keyring.Get(ServiceName, "oauth_token")
	if err != nil {
		if err == keyring.ErrNotFound {
			return nil, nil
		}
		return nil, fmt.Errorf("failed to load token from keychain: %w", err)
	}

	var stored StoredToken
	if err := json.Unmarshal([]byte(data), &stored); err != nil {
		return nil, fmt.Errorf("failed to parse token: %w", err)
	}

	return &oauth2.Token{
		AccessToken:  stored.AccessToken,
		TokenType:    stored.TokenType,
		RefreshToken: stored.RefreshToken,
		Expiry:       stored.Expiry,
	}, nil
}

// DeleteToken removes the OAuth token from the keychain
func DeleteToken() error {
	return keyring.Delete(ServiceName, "oauth_token")
}
