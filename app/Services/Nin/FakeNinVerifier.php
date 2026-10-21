<?php

namespace App\Services\Nin;

use App\Contracts\NinVerifier;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Development and test stand-in. No network. Production refuses to boot with it
 * (see AppServiceProvider).
 *
 *   00000000000  -> NIN not found
 *   anything else -> a made-up person chosen by the last digit (mod 4)
 */
final class FakeNinVerifier implements NinVerifier
{
    public const NOT_FOUND_NIN = '00000000000';

    /** @var list<array{0:string,1:string,2:string}> */
    private const PEOPLE = [
        ['Adaeze Chinwe Okafor', '1992-04-17', 'Female'],
        ['Tunde Babatunde Adeyemi', '1988-11-02', 'Male'],
        ['Ngozi Amaka Eze', '1995-07-23', 'Female'],
        ['Musa Ibrahim Danjuma', '1990-01-30', 'Male'],
    ];

    public function lookup(string $nin): NinRecord
    {
        if ($nin === self::NOT_FOUND_NIN) {
            throw new ApiException(ErrorCode::NinNotFound);
        }

        [$name, $dob, $gender] = self::PEOPLE[((int) substr($nin, -1)) % 4];

        return new NinRecord($name, $dob, $gender, null);
    }
}
