<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\RecordingLogger;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;

final class RepositoryLoggingTest extends RepositoryTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
        $this->repo = new Repository($this->http, $this->clock, $this->cache, $this->scheduler, logger: $this->logger);
    }

    public function test_a_successful_refresh_logs_nothing(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame([], $this->logger->messages);
    }

    public function test_a_transport_error_is_logged_with_dataset_region_and_reason(): void
    {
        $this->http->queue(new HttpResponse(null, null, 'cURL error 28: Operation timed out'));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertCount(1, $this->logger->messages);
        $this->assertSame('risk/nuble: transport error: cURL error 28: Operation timed out', $this->logger->messages[0]);
    }

    public function test_a_non_200_is_logged_with_the_status_and_never_the_body(): void
    {
        $this->http->queue(new HttpResponse(502, '<html>SECRET-BODY-TOKEN</html>'));

        $this->repo->refresh('biobio', Dataset::Burned);

        $this->assertSame(['burned/biobio: HTTP 502'], $this->logger->messages);
    }

    public function test_a_payload_that_fails_normalization_is_logged_without_the_body(): void
    {
        $this->http->queue(new HttpResponse(200, '{"success":false,"hint":"SECRET-BODY-TOKEN"}'));

        $this->repo->refresh('araucania', Dataset::Risk);

        $this->assertSame(['risk/araucania: invalid payload'], $this->logger->messages);
    }

    public function test_a_throwing_http_client_is_logged_by_class_and_message(): void
    {
        $this->http->queue(new \RuntimeException('boom'));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(['risk/nuble: unexpected RuntimeException: boom'], $this->logger->messages);
    }

    public function test_long_multiline_reasons_are_collapsed_and_truncated(): void
    {
        $this->http->queue(new HttpResponse(null, null, "line one\nline two " . str_repeat('x', 500)));

        $this->repo->refresh('nuble', Dataset::Risk);

        $message = $this->logger->messages[0];
        $this->assertStringNotContainsString("\n", $message);
        $this->assertStringStartsWith('risk/nuble: transport error: line one line two xxx', $message);
        $this->assertLessThanOrEqual(260, strlen($message));
    }

    public function test_requests_skipped_by_backoff_log_nothing(): void
    {
        $this->http->queue(new HttpResponse(502, 'x'));
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->logger->messages = [];

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame([], $this->logger->messages);
    }

    public function test_without_a_logger_failures_stay_silent(): void
    {
        $repo = new Repository($this->http, $this->clock, $this->cache, $this->scheduler);
        $this->http->queue(new HttpResponse(502, 'x'));

        $result = $repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(Source::None, $result->source);
    }
}
