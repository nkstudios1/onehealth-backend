<?php

namespace Tests\Support;

use App\Contracts\SmsGateway;
use RuntimeException;

/** Records every SMS instead of sending it, so tests can check who was texted what. */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $phone, string $message): void
    {
        if ($this->fail) {
            throw new RuntimeException('provider down');
        }

        $this->sent[] = ['phone' => $phone, 'message' => $message];
    }

    public function sentTo(string $phone): int
    {
        return count(array_filter($this->sent, fn ($s) => $s['phone'] === $phone));
    }
}
