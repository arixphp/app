<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use InvalidArgumentException;
use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        public readonly HttpStatusCode $status,
        string $message,
        public readonly array $headers = []
    ) {
        if ($status->value < HttpStatusCode::BAD_REQUEST->value) {
            throw new InvalidArgumentException('HTTP error status must represent a client or server error.');
        }

        parent::__construct($message);
    }
}
