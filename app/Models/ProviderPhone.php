<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A number a hospital registered as its front desk. Only verified ones are ever shown. */
#[Fillable(['organisation_id', 'phone', 'label', 'verified_at'])]
class ProviderPhone extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
