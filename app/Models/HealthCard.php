<?php

namespace App\Models;

use App\Enums\CardStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'card_number', 'qr_token', 'owner_id', 'status', 'issued_at', 'expires_at', 'activated_at',
    'deactivated_at', 'deactivated_by', 'reason_deactivated', 'replaced_by',
])]
#[Hidden(['qr_token'])]
class HealthCard extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => CardStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'owner_id');
    }

    /** A card can open a request only if it was activated and has not expired. */
    public function isUsable(): bool
    {
        return $this->status === CardStatus::Activated && $this->expires_at->isFuture() && $this->owner_id !== null;
    }
}
