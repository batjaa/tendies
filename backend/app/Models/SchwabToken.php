<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchwabToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_account_id',
        'encrypted_access_token',
        'encrypted_refresh_token',
        'token_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_access_token' => 'encrypted',
            'encrypted_refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }
}
