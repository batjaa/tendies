<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchwabToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
