<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Tests\Support\StubWpError;
use CableATierra\Datos\Wp\WpHttpClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class WpHttpClientTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const URL = 'https://nofires-backend-production.up.railway.app/api/x';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->alias(static fn ($v): bool => $v instanceof StubWpError);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($r) => $r['response']['code'] ?? '');
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['body'] ?? '');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sends_a_bounded_get_with_own_user_agent_and_safe_url_checks(): void
    {
        Functions\expect('wp_remote_get')->once()->with(self::URL, \Mockery::on(static function (array $args): bool {
            return $args['timeout'] === 5
                && $args['redirection'] === 2
                && $args['reject_unsafe_urls'] === true
                && $args['limit_response_size'] === 2_000_000
                && str_starts_with($args['user-agent'], 'CableATierraDatos/' . CAT_DATOS_VERSION . ' (+https://cableatierrachile.com)');
        }))->andReturn(['response' => ['code' => 200], 'body' => '{"ok":1}']);

        $response = (new WpHttpClient())->get(self::URL);

        $this->assertSame(200, $response->status);
        $this->assertSame('{"ok":1}', $response->body);
        $this->assertNull($response->error);
    }

    public function test_wp_error_becomes_a_transport_failure(): void
    {
        Functions\when('wp_remote_get')->justReturn(new StubWpError());

        $response = (new WpHttpClient())->get(self::URL);

        $this->assertNull($response->status);
        $this->assertNull($response->body);
        $this->assertSame('stub error', $response->error);
    }

    public function test_non_numeric_status_and_non_string_body_are_normalized(): void
    {
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => ''], 'body' => ['x']]);

        $response = (new WpHttpClient())->get(self::URL);

        $this->assertNull($response->status);
        $this->assertNull($response->body);
    }

    public function test_502_keeps_its_status(): void
    {
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 502], 'body' => '<html>']);

        $this->assertSame(502, (new WpHttpClient())->get(self::URL)->status);
    }

    public function test_throwables_are_turned_into_failures(): void
    {
        Functions\when('wp_remote_get')->alias(static function (): never {
            throw new \RuntimeException('boom');
        });

        $response = (new WpHttpClient())->get(self::URL);

        $this->assertNull($response->status);
        $this->assertSame('boom', $response->error);
    }

    public function test_non_https_urls_are_refused_without_a_request(): void
    {
        Functions\expect('wp_remote_get')->never();

        $response = (new WpHttpClient())->get('http://169.254.169.254/latest/meta-data');

        $this->assertNull($response->status);
        $this->assertNotNull($response->error);
    }
}
