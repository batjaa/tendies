<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 *  ┌──────────┐     ┌────────────────┐     ┌─────────────┐
 *  │   User   │────<│ TradingAccount │────┤│ SchwabToken  │
 *  │          │ 1:N │                │ 1:1 │              │
 *  └──────────┘     │                │     └──────────────┘
 *                   │                │────<┌──────────────────────┐
 *                   │                │ 1:N │ TradingAccountHash   │
 *                   └────────────────┘     │ hash_value (unique)  │
 *                                          └──────────────────────┘
 */
class TradingAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'display_name',
        'is_primary',
        'last_synced_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schwabToken(): HasOne
    {
        return $this->hasOne(SchwabToken::class);
    }

    public function hashes(): HasMany
    {
        return $this->hasMany(TradingAccountHash::class);
    }
}
