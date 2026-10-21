<?php

namespace App\Models;

use App\Enums\NoticeChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['access_request_id', 'recipient_type', 'emergency_contact_id', 'channel', 'code_hash', 'token_hash', 'sent_at', 'used_at'])]
#[Hidden(['code_hash', 'token_hash'])]
class AccessRequestNotice extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'channel' => NoticeChannel::class,
            'sent_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class, 'access_request_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmergencyContact::class, 'emergency_contact_id');
    }
}
