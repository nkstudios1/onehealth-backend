<?php

namespace App\Contracts;

use App\Services\Nin\NinRecord;

/**
 * Looks a NIN up at the identity source and returns who it belongs to.
 *
 * Implementations MUST:
 *  - throw ApiException(ErrorCode::NinNotFound) when the number does not exist;
 *  - throw ApiException(ErrorCode::NinServiceUnavailable) when the source is down or times out
 *    (never let a vendor exception or message reach the client);
 *  - never log or keep the raw NIN.
 *
 * The real vendor is not chosen yet. Add a class that implements this interface,
 * then bind it in AppServiceProvider for NIN_DRIVER=<name>.
 */
interface NinVerifier
{
    public function lookup(string $nin): NinRecord;
}
