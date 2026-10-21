<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A front-desk or clinical staff member. Their id is their user id. */
#[Fillable(['id', 'organisation_id', 'fullname'])]
class OrganisationStaff extends Model
{
    protected $table = 'organisation_staff';

    public $incrementing = false;

    protected $keyType = 'string';

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }
}
