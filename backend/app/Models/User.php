<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Billable, HasFactory, Notifiable;

    const FREE_DAILY_LIMIT = 15;

    const FREE_MAX_DAYS = 7;

    protected $fillable = [
        'name',
        'email',
        'password',
        'trial_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tradingAccounts(): HasMany
    {
        return $this->hasMany(TradingAccount::class);
    }

    public function primaryTradingAccount(): HasOne
    {
        return $this->hasOne(TradingAccount::class)->where('is_primary', true);
    }

    public function tier(): string
    {
        if ($this->subscribed('default')) {
            return 'pro';
        }
        if ($this->onGenericTrial()) {
            return 'trial';
        }

        return 'free';
    }

    public function isAnonymous(): bool
    {
        return $this->email === null;
    }

    public function canLinkMoreAccounts(): bool
    {
        return $this->tier() !== 'free' || $this->tradingAccounts()->count() === 0;
    }

    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tier' => $this->tier(),
        ];
    }

    public function subscriptionSummary(): ?array
    {
        $sub = $this->subscription('default');
        if (! $sub) {
            return null;
        }

        return [
            'plan' => match ($sub->stripe_price) {
                config('services.stripe.yearly_price_id') => 'yearly',
                default => 'monthly',
            },
            'status' => $sub->pastDue() ? 'past_due' : 'active',
        ];
    }
}
