<?php

namespace App\Models;

use App\Enums\AccessRequestStage;
use App\Enums\AccessRequestStatus;
use App\Enums\DecisionChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'patient_id', 'organisation_id', 'requested_by', 'card_id', 'status', 'stage', 'code_attempts',
    'decided_by_patient', 'decided_by_contact', 'decided_via', 'care_session_id', 'escalated_at',
    'expires_at', 'decided_at',
])]
class AccessRequest extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'stage' => AccessRequestStage::class,
            'decided_via' => DecisionChannel::class,
            'decided_by_patient' => 'boolean',
            'code_attempts' => 'integer',
            'escalated_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function notices(): HasMany
    {
        return $this->hasMany(AccessRequestNotice::class, 'access_request_id');
    }

    public function isPending(): bool
    {
        return $this->status === AccessRequestStatus::Pending;
    }
}
