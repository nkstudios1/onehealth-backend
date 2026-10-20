<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "forgot my password" attempt. user_id is null when nobody matched (so we never reveal that). */
#[Fillable([
    'user_id', 'channel', 'identifier_hash', 'destination', 'otp_sent_at', 'otp_verified_at',
    'token_hash', 'token_expires_at', 'used_at', 'expires_at', 'ip_address',
])]
#[Hidden(['token_hash'])]
class PasswordReset extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'otp_sent_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
