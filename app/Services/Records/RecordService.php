<?php

namespace App\Services\Records;

use App\Enums\AuditEvent;
use App\Enums\ErrorCode;
use App\Enums\RecordCategory;
use App\Enums\RecordVisibility;
use App\Exceptions\ApiException;
use App\Models\CareSession;
use App\Models\Patient;
use App\Models\Record;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Records are only what people typed in: no calculations, flags or thresholds. Free text everywhere
 * medical. The patient alone decides visibility. Whoever writes a record controls what it says.
 */
class RecordService
{
    /** @return Collection<int, Record> */
    public function listFor(Patient $patient, ?RecordCategory $category = null): Collection
    {
        return Record::where('patient_id', $patient->id)
            ->when($category, fn ($q) => $q->where('category', $category->value))
            ->orderBy('category')->latest()->get();
    }

    /**
     * The patient writes their own history (blood group, allergy...). Private unless they choose public.
     *
     * @param  array{category: string, type: string, title: string, summary?: ?string, payload?: ?array, visibility?: ?string, eventAt?: ?string}  $data
     */
    public function createByPatient(Patient $patient, array $data): Record
    {
        $record = Record::create([
            'patient_id' => $patient->id,
            'category' => $data['category'],
            'type' => trim($data['type']),
            'title' => trim($data['title']),
            'summary' => $data['summary'] ?? null,
            'payload' => $data['payload'] ?? null,
            'visibility' => $data['visibility'] ?? RecordVisibility::Private->value,
            'created_by' => $patient->id,
            'event_at' => $data['eventAt'] ?? null,
        ]);

        Audit::record(AuditEvent::RecordCreated, $patient->id, ['record_id' => $record->id, 'by' => 'patient']);

        return $record;
    }

    /**
     * A clinician writes into the patient's record DURING an open session. The record is private to
     * the patient's choice by default: the hospital cannot make something public on its own.
     *
     * @param  array{category: string, type: string, title: string, summary?: ?string, payload?: ?array}  $data
     */
    public function createInSession(CareSession $session, User $author, array $data): Record
    {
        $record = Record::create([
            'patient_id' => $session->patient_id,
            'category' => $data['category'],
            'type' => trim($data['type']),
            'title' => trim($data['title']),
            'summary' => $data['summary'] ?? null,
            'payload' => $data['payload'] ?? null,
            'visibility' => RecordVisibility::Private,
            'created_by' => $author->id,
            'event_at' => now(),
        ]);

        Audit::record(AuditEvent::RecordCreated, $author->id, [
            'record_id' => $record->id, 'session_id' => $session->id, 'by' => 'organisation',
        ]);

        return $record;
    }

    /** Only the patient can change who sees a record. */
    public function setVisibility(Patient $patient, string $id, RecordVisibility $visibility): Record
    {
        $record = Str::isUuid($id) ? Record::where('patient_id', $patient->id)->find($id) : null;

        if ($record === null) {
            throw new ApiException(ErrorCode::RecordNotFound);
        }

        $record->update(['visibility' => $visibility]);

        Audit::record(AuditEvent::RecordVisibilityChanged, $patient->id, ['record_id' => $record->id, 'to' => $visibility->value]);

        return $record;
    }

    /** @return array<string, mixed> The only shape a record leaves the API in. */
    public static function present(Record $r): array
    {
        return [
            'id' => $r->id,
            'category' => $r->category->value,
            'type' => $r->type,
            'title' => $r->title,
            'summary' => $r->summary,
            'payload' => $r->payload,
            'visibility' => $r->visibility->value,
            'eventAt' => $r->event_at?->toIso8601String(),
            'createdAt' => $r->created_at->toIso8601String(),
        ];
    }
}
