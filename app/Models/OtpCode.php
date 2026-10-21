<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A one-time code. Only the HMAC is stored. Use OtpService, never this model directly. */
#[Fillable(['purpose', 'subject_id', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'created_at'])]
class OtpCode extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
