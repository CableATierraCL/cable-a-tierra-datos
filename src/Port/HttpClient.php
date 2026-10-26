<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

interface HttpClient
{
    /** Performs a GET. Implementations MUST NOT throw: failures are returned in the response. */
    public function get(string $url): HttpResponse;
}
