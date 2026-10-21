<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\CareSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An organisation's access to one patient, from approval until check-out, revocation or auto-close. */
#[Fillable([
    'patient_id', 'organisation_id', 'opened_by', 'access_method', 'access_level', 'expires_at', 'status',
    'emergency_contact_id', 'started_at', 'ended_at',
])]
class CareSession extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => CareSessionStatus::class,
            'access_level' => AccessLevel::class,
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    /** Active, and not past an explicit expiry (null expiry = until check-out). */
    public function isOpen(): bool
    {
        return $this->status === CareSessionStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
