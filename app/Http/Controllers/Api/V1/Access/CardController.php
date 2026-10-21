<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Access\ActivateCardRequest;
use App\Models\HealthCard;
use App\Services\Access\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardController extends ApiController
{
    public function __construct(private readonly CardService $cards) {}

    /** The patient's active card, with the token their app turns into the QR code. */
    public function show(Request $request): JsonResponse
    {
        $card = $this->cards->activeFor($request->user('web')->patient);

        return $this->ok(['card' => $card === null ? null : $this->present($card)]);
    }

    public function activate(ActivateCardRequest $request): JsonResponse
    {
        $card = $this->cards->activate($request->user('web')->patient, $request->validated('cardNumber'));

        return $this->ok(['card' => $this->present($card)], 'Card activated.');
    }

    /** @return array<string, mixed> */
    private function present(HealthCard $card): array
    {
        return [
            'last4' => substr($card->card_number, -4),
            'status' => $card->status->value,
            'expiresAt' => $card->expires_at->toDateString(),
            // The owner needs this to draw the QR. It is not the card number and can be replaced.
            'qrToken' => $card->makeVisible('qr_token')->qr_token,
        ];
    }
}
