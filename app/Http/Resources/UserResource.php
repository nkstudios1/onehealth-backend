<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape in which a logged-in user leaves the API. Add a field here on purpose;
 * never return the model directly (it would expose whatever columns get added later).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $patient = $this->patient;

        $org = $patient === null ? $this->organisation() : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'fullName' => $patient?->fullname,
            'personalId' => $patient?->personal_id,
            'username' => $patient?->username,
            'organisation' => $org === null ? null : ['id' => $org->id, 'name' => $org->name],
        ];
    }
}
