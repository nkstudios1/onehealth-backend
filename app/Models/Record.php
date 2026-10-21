<?php

namespace App\Models;

use App\Enums\RecordCategory;
use App\Enums\RecordVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One medical record. Content is free text; `visibility` is controlled only by the patient. */
#[Fillable(['patient_id', 'category', 'type', 'title', 'summary', 'payload', 'visibility', 'created_by', 'event_at'])]
class Record extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'category' => RecordCategory::class,
            'visibility' => RecordVisibility::class,
            'payload' => 'array',
            'event_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
