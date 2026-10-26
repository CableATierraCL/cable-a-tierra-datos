<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Port\HttpClient;
use CableATierra\Datos\Port\HttpResponse;

/**
 * wp_remote_get adapter. Only https URLs are requested (the repository builds them from a fixed
 * base); never throws.
 */
final class WpHttpClient implements HttpClient
{
    public function get(string $url, float $timeout): HttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            return new HttpResponse(null, null, 'refused: not an https URL');
        }

        try {
            $result = wp_remote_get($url, [
                'timeout' => $timeout,
                'redirection' => 2,
                'reject_unsafe_urls' => true,
                'limit_response_size' => 2_000_000,
                'user-agent' => 'CableATierraDatos/' . CAT_DATOS_VERSION . ' (+https://cableatierrachile.com)',
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($result)) {
                $message = is_object($result) && method_exists($result, 'get_error_message')
                    ? (string) $result->get_error_message()
                    : 'transport error';

                return new HttpResponse(null, null, $message);
            }

            $status = wp_remote_retrieve_response_code($result);
            $body = wp_remote_retrieve_body($result);

            return new HttpResponse(is_int($status) ? $status : null, is_string($body) ? $body : null);
        } catch (\Throwable $e) {
            return new HttpResponse(null, null, $e->getMessage());
        }
    }
}
