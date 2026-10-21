<?php

namespace App\Models;

use App\Enums\RegistrationStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A sign-up in progress. Deleted when the account is created or the sign-up expires. */
#[Fillable([
    'phone', 'nin_hash', 'nin_last4', 'full_name', 'dob', 'gender', 'photo_url', 'step', 'contacts',
    'consented_at', 'consent_version', 'otp_sent_at', 'expires_at', 'ip_address',
])]
class Registration extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'step' => RegistrationStep::class,
            'contacts' => 'array',
            'dob' => 'date',
            'consented_at' => 'datetime',
            'otp_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
