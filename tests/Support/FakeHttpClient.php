<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Port\HttpClient;
use CableATierra\Datos\Port\HttpResponse;

/** Replays queued responses (or throws queued Throwables) and records requested URLs. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    public function queue(HttpResponse|\Throwable ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function get(string $url): HttpResponse
    {
        $this->calls[] = $url;
        $next = array_shift($this->queue) ?? new HttpResponse(null, null, 'nothing queued');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
