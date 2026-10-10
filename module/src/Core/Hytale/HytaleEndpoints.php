<?php

namespace HytaleHosting\Core\Hytale;

/**
 * Endpoints and OAuth client values from the official Hytale
 * "Server Provider Authentication Guide".
 */
final class HytaleEndpoints
{
    public const OAUTH_CLIENT_ID = 'hytale-server';
    public const OAUTH_SCOPE = 'openid offline auth:server';

    public const DEVICE_AUTH_URL = 'https://oauth.accounts.hytale.com/oauth2/device/auth';
    public const TOKEN_URL = 'https://oauth.accounts.hytale.com/oauth2/token';

    public const PROFILES_URL = 'https://account-data.hytale.com/my-account/get-profiles';

    public const SESSION_NEW_URL = 'https://sessions.hytale.com/game-session/new';
    public const SESSION_URL = 'https://sessions.hytale.com/game-session';

    /** Refresh the OAuth access token this many seconds before it expires. */
    public const ACCESS_TOKEN_REFRESH_MARGIN = 300;

    /** The guide documents a 30 day refresh token lifetime. */
    public const REFRESH_TOKEN_TTL_DAYS = 30;
}
