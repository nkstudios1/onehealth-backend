<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Up to 3 per patient. Contacts need no OneHealth account. */
#[Fillable(['patient_id', 'name', 'relationship', 'phone', 'email', 'priority'])]
class EmergencyContact extends Model
{
    use HasUuids;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
