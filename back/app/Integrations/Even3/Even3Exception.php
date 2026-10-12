<?php

namespace App\Integrations\Even3;

use RuntimeException;

/**
 * Erro tipado da Even3. A mensagem é sempre segura: nunca contém o token (o Even3Client redige antes).
 */
class Even3Exception extends RuntimeException
{
    public const BAD_REQUEST = 'BAD_REQUEST';

    public const UNAUTHORIZED = 'UNAUTHORIZED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const TIMEOUT = 'TIMEOUT';

    public const NETWORK_ERROR = 'NETWORK_ERROR';

    public const INVALID_JSON = 'INVALID_JSON';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, [self::TIMEOUT, self::NETWORK_ERROR, self::RATE_LIMITED, self::SERVER_ERROR], true);
    }
}
