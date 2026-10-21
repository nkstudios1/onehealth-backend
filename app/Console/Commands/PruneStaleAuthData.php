<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use App\Models\PasswordReset;
use App\Models\Registration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes abandoned sign-ups (they hold a person's name and date of birth, so they must not
 * linger), dead one-time codes and old reset attempts. Scheduled daily in routes/console.php.
 */
#[Signature('onehealth:prune')]
#[Description('Delete expired sign-ups, one-time codes and password reset attempts')]
class PruneStaleAuthData extends Command
{
    public function handle(): int
    {
        $registrations = Registration::where('expires_at', '<', now())->pluck('id');
        OtpCode::whereIn('subject_id', $registrations)->delete();
        Registration::whereIn('id', $registrations)->delete();

        $codes = OtpCode::where('expires_at', '<', now()->subDay())->delete();
        $resets = PasswordReset::where('expires_at', '<', now()->subDay())->delete();

        $this->info("Removed {$registrations->count()} sign-ups, {$codes} codes, {$resets} reset attempts.");

        return self::SUCCESS;
    }
}
