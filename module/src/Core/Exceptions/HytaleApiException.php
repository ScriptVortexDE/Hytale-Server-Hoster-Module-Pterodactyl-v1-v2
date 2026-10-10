<?php

namespace HytaleHosting\Core\Exceptions;

use RuntimeException;

/**
 * An error response (or transport failure) from one of the Hytale services.
 * Messages never contain tokens.
 */
class HytaleApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, $status);
    }

    public function isInvalidGrant(): bool
    {
        return $this->errorCode === 'invalid_grant';
    }
}
