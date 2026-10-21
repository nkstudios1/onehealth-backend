<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Anyone who can log in, of any type. Patient details live in `Patient`
 * (same id). The portal's staff and admins will get their own profile tables
 * the same way.
 */
#[Fillable(['type', 'email', 'email_verified_at', 'phone', 'photo', 'password', 'google_sub', 'status', 'last_login_at'])]
#[Hidden(['password', 'google_sub'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => AccountStatus::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Emails are always stored lower-case so "A@x.com" and "a@x.com" are one account. */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    /** No "remember me" feature, so no remember_token column. An empty name switches it off. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class, 'id');
    }

    /** The organisation this user acts for: an org admin IS the organisation, staff belong to one. */
    public function organisation(): ?Organisation
    {
        return match ($this->type) {
            UserType::OrgAdmin => Organisation::find($this->id),
            UserType::OrgStaff => OrganisationStaff::with('organisation')->find($this->id)?->organisation,
            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
