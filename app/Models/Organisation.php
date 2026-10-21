<?php

namespace App\Models;

use App\Enums\VerifyStatus;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A health facility. Its id is its admin user's id. Only `verified` organisations can scan cards. */
#[Fillable(['id', 'name', 'verify_status'])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['verify_status' => VerifyStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ProviderPhone::class, 'organisation_id');
    }

    public function isVerified(): bool
    {
        return $this->verify_status === VerifyStatus::Verified;
    }
}
