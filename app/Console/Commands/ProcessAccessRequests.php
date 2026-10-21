<?php

namespace App\Console\Commands;

use App\Services\Access\AccessRequestService;
use App\Services\Access\SessionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: send unanswered requests on to the emergency contacts, expire dead ones, and close
 * care sessions nobody checked out of. (Requests are also advanced whenever someone reads them.)
 */
#[Signature('onehealth:process-access')]
#[Description('Escalate unanswered access requests, expire old ones, auto-close stale sessions')]
class ProcessAccessRequests extends Command
{
    public function handle(AccessRequestService $requests, SessionService $sessions): int
    {
        $moved = $requests->processDue();
        $closed = $sessions->autoCloseStale();

        $this->info("Requests moved on: {$moved}. Sessions auto-closed: {$closed}.");

        return self::SUCCESS;
    }
}
