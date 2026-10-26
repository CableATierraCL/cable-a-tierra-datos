<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

/**
 * Outcome of an HTTP GET. A transport failure has a null status and an error message.
 */
final readonly class HttpResponse
{
    public function __construct(
        public ?int $status,
        public ?string $body = null,
        public ?string $error = null,
    ) {
    }
}
