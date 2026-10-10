<?php

namespace HytaleHosting\Core\Hytale;

use Carbon\CarbonImmutable;

final class GameSession
{
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $identityToken,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }
}
