<?php

namespace App\Console\Commands;

use App\Services\Access\CardService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Platform side of card printing. Prints the number and QR token to put on the card. */
#[Signature('onehealth:issue-card {count=1}')]
#[Description('Create blank cards ready to be printed')]
class IssueCard extends Command
{
    public function handle(CardService $cards): int
    {
        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            $card = $cards->issue();
            $this->line("{$card->card_number}  qr:{$card->makeVisible('qr_token')->qr_token}  expires:{$card->expires_at->toDateString()}");
        }

        return self::SUCCESS;
    }
}
