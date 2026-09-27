<?php

namespace App\Domain\PartnerApi\Exceptions;

use RuntimeException;

/**
 * An error the Partner API returns as
 * `{ "error": { "code", "message", "request_id" } }` with a stable code
 * partners can program against (Architecture §10).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
