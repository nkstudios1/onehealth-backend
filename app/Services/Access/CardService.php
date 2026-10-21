<?php

namespace App\Services\Access;

use App\Enums\AuditEvent;
use App\Enums\CardStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\HealthCard;
use App\Models\Patient;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Physical cards: the platform issues them (printed with a number and a QR), the patient
 * activates them by typing the number. A card is valid for 4 years, like a bank card.
 */
class CardService
{
    /** Platform side: create a blank card ready to be printed. */
    public function issue(): HealthCard
    {
        return HealthCard::create([
            'card_number' => $this->newNumber(),
            // The QR holds this random token, never the card number, so a replaced card's QR is useless.
            'qr_token' => Str::random(40),
            'status' => CardStatus::Issued,
            'issued_at' => now(),
            'expires_at' => now()->addYears(4),
        ]);
    }

    /**
     * Patient side: "Activate OneHealth card" screen. Someone else's card, an unknown number and
     * an already-used card all answer CARD_NOT_FOUND, so numbers cannot be probed.
     */
    public function activate(Patient $patient, string $rawNumber): HealthCard
    {
        $number = preg_replace('/\D/', '', $rawNumber) ?? '';

        $card = DB::transaction(function () use ($patient, $number) {
            $card = HealthCard::where('card_number', $number)->lockForUpdate()->first();

            if ($card === null || $card->expires_at->isPast()) {
                throw new ApiException(ErrorCode::CardNotFound);
            }

            if ($card->status === CardStatus::Activated && $card->owner_id === $patient->id) {
                return $card; // pressing the button twice is fine
            }

            if ($card->status !== CardStatus::Issued) {
                throw new ApiException(ErrorCode::CardNotFound);
            }

            // One active card per patient: a new one replaces the old.
            HealthCard::where('owner_id', $patient->id)->where('status', CardStatus::Activated->value)->update([
                'status' => CardStatus::Deactivated->value,
                'deactivated_at' => now(),
                'deactivated_by' => 'patient',
                'reason_deactivated' => 'replaced',
                'replaced_by' => $card->id,
            ]);

            $card->update(['owner_id' => $patient->id, 'status' => CardStatus::Activated, 'activated_at' => now()]);

            return $card;
        });

        Audit::record(AuditEvent::CardActivated, $patient->id, ['card_id' => $card->id]);

        return $card;
    }

    public function activeFor(Patient $patient): ?HealthCard
    {
        return HealthCard::where('owner_id', $patient->id)->where('status', CardStatus::Activated->value)->latest('activated_at')->first();
    }

    private function newNumber(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $n = '';
            for ($d = 0; $d < 4; $d++) {
                $n .= str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            }

            if (! HealthCard::where('card_number', $n)->exists()) {
                return $n;
            }
        }

        throw new \RuntimeException('Could not generate a unique card number.');
    }
}
