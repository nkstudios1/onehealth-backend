<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Write through App\Support\Audit::record(). Updating or deleting
 * a row throws, so a bug (or a careless developer) cannot rewrite history.
 * (A database-level rule, such as revoking UPDATE/DELETE from the app's
 * database user, is the real guarantee in production.)
 */
#[Fillable(['event', 'actor_id', 'ip_address', 'user_agent', 'request_id', 'metadata', 'created_at'])]
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log rows cannot be deleted.'));
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
