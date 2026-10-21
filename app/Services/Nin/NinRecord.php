<?php

namespace App\Services\Nin;

/** What the identity source tells us about a NIN. Plain data, no behaviour. */
final readonly class NinRecord
{
    public function __construct(
        public string $fullName,
        public string $dateOfBirth,   // Y-m-d
        public string $gender,        // as the source gives it, e.g. "Male" / "Female"
        public ?string $photoUrl = null,
    ) {}
}
