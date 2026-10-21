<?php

namespace Tests\Unit;

use App\Enums\ErrorCode;
use App\Services\Auth\RegistrationService;
use App\Support\Identifier;
use App\Support\Mask;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_phone_normalise(): void
    {
        $this->assertSame('08031234567', Phone::normalise('+234 803 123 4567'));
        $this->assertSame('08031234567', Phone::normalise('2348031234567'));
        $this->assertSame('08031234567', Phone::normalise('0803-123-4567'));
        $this->assertTrue(Phone::isValid('08031234567'));
        $this->assertFalse(Phone::isValid('8031234567'));
    }

    public function test_mask(): void
    {
        $this->assertSame('080****4567', Mask::destination('08031234567'));
        $this->assertSame('ch***@mail.com', Mask::destination('chidi@mail.com'));
    }

    public function test_identifier_classification(): void
    {
        $this->assertSame('phone', Identifier::classify('08031234567')['type']);
        $this->assertSame('personal_id', Identifier::classify('4829175036')['type']);
        $this->assertSame(['type' => 'email', 'value' => 'a@b.com'], Identifier::classify('A@B.com'));
        $this->assertNull(Identifier::classify('0829175036'));   // 10 digits starting 0 is not a personal ID
        $this->assertNull(Identifier::classify('hello'));
    }

    public function test_display_name_uses_first_and_last(): void
    {
        $this->assertSame('Adaeze Okafor', RegistrationService::displayName('ADAEZE chinwe OKAFOR'));
        $this->assertSame('Madonna', RegistrationService::displayName('madonna'));
        $this->assertSame('Ngozi Eze', RegistrationService::displayName("  Ngozi   Amaka  Eze "));
    }

    /** Forgetting status() or message() for a new code would otherwise only fail in production. */
    public function test_every_error_code_has_status_message_and_unique_value(): void
    {
        $seen = [];

        foreach (ErrorCode::cases() as $case) {
            $this->assertGreaterThanOrEqual(400, $case->status(), $case->name);
            $this->assertNotSame('', $case->message(), $case->name);
            $this->assertMatchesRegularExpression('/^[A-Z_]+$/', $case->value);
            $this->assertArrayNotHasKey($case->value, $seen);
            $seen[$case->value] = true;
        }
    }
}
