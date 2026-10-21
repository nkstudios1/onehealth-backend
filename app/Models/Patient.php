<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient's profile. Its id IS the user's id (one-to-one), so it is set
 * explicitly on creation instead of being generated.
 */
#[Fillable([
    'id', 'nin_hash', 'nin_last4', 'fullname', 'username', 'dob', 'gender', 'personal_id',
    'home_address', 'work_address', 'consented_at', 'consent_version',
])]
#[Hidden(['nin_hash'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class, 'patient_id')->orderBy('priority');
    }
}
