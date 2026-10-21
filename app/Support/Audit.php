<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way to write to the audit log.
 *
 *   Audit::record(AuditEvent::LoginFailed, null, ['reason' => 'bad_password']);
 *
 * NEVER put a password, code, token or full NIN in $metadata.
 *
 * A failure to write the audit row must not break the request that caused it
 * (a login should not fail because the log table hiccuped), so errors are
 * reported to the normal log instead of thrown.
 */
final class Audit
{
    /** @param array<string, mixed> $metadata */
    public static function record(AuditEvent $event, ?string $actorId = null, array $metadata = []): void
    {
        try {
            $request = request();

            AuditLog::create([
                'event' => $event,
                'actor_id' => $actorId,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'request_id' => RequestId::get(),
                'metadata' => $metadata === [] ? null : $metadata,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit write failed', ['event' => $event->value, 'error' => $e->getMessage()]);
        }
    }
}
