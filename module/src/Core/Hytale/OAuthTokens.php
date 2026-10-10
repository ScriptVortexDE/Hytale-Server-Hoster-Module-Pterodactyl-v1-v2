<?php

namespace HytaleHosting\Core\Hytale;

use Carbon\CarbonImmutable;

final class OAuthTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly CarbonImmutable $accessTokenExpiresAt,
    ) {
    }
}
