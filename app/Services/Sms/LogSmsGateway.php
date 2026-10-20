<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Development gateway: "sends" by writing to storage/logs/laravel.log.
 * The message body (which contains the code) is logged here ON PURPOSE so
 * developers can read it. Production refuses to boot with this driver.
 */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): void
    {
        Log::info('[SMS] to '.$phone.': '.$message);
    }
}
