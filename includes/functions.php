<?php
/**
 * Public PHP API, v1. Stable contract for the theme: additions never change existing keys.
 *
 * Guarantees: every function returns an array (or int), never a WP_Error, and never throws.
 * Strings are RAW data (comuna names, citation, labels): escape them when printing
 * (esc_html / esc_attr). Missing data is null, never a fabricated 0.
 *
 * Guard calls with function_exists('cat_datos_api_version') and check the version >= 1.
 */

declare(strict_types=1);

use CableATierra\Datos\Summary\Api;
use CableATierra\Datos\Summary\RegionSummary;
use CableATierra\Datos\Wp\Plugin;
use CableATierra\Datos\Wp\SystemClock;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('cat_datos_api_version')) {
    /** API major version. Bumped only on breaking changes. */
    function cat_datos_api_version(): int
    {
        return 1;
    }
}

if (!function_exists('cat_datos_get_region_summary')) {
    /**
     * Summary of one region ('nuble', 'biobio' or 'araucania'). An unknown region id returns the
     * `unavailable` shape. Serves cached or last-good data. On a cold cache the first read of a
     * PHP request may make one short (3 s) blocking request; that budget is shared by every API
     * call in the request, so any other dataset stays `unavailable` until cron warms it.
     *
     * @return array{
     *   region: string,
     *   label: string,
     *   state: 'ok'|'stale'|'very_stale'|'unavailable',
     *   firmsTotal: ?int,
     *   firmsMissing: int,
     *   levelCounts: array{CRITICO: int, ALTO: int, PREVENTIVO: int, NORMAL: int},
     *   unclassifiedCount: int,
     *   comunaCount: int,
     *   criticalComunas: list<array{comunaId: string, nombre: string, driverKey: ?string, computedAt: string}>,
     *   burned: array{available: bool, year: ?int, totalHa: ?float, comunasWithData: int, comunasTotal: int, citation: ?string},
     *   freshnessAt: ?string,
     *   freshnessAtLocal: ?string,
     *   ageSeconds: ?int,
     *   sources: array{risk: string, burned: string}
     * }
     *   - state: ok = oldest comuna data is at most 24 h old (inclusive), stale = up to 48 h
     *     (inclusive), very_stale = older, unavailable = no data at all.
     *   - firmsTotal: sum over comunas with a count; null when none has one. firmsMissing is how
     *     many comunas had no count.
     *   - levelCounts: always the four keys in that order, zeros included; unknown levels are
     *     only in unclassifiedCount.
     *   - burned.available is false (totalHa null, never 0) when no comuna has data; for partial
     *     coverage totalHa sums the available ones (comunasWithData of comunasTotal).
     *   - freshnessAt: oldest computedAt, UTC ("2026-10-25T14:05:00Z"); freshnessAtLocal:
     *     same instant in America/Santiago ("2026-10-25 11:05", 24 h); ageSeconds is >= 0.
     *   - sources.*: 'cache' | 'api' | 'last_good' | 'none'.
     */
    function cat_datos_get_region_summary(string $region_id): array
    {
        try {
            return Api::summary(Plugin::repository(), new SystemClock(), $region_id);
        } catch (\Throwable) {
            return RegionSummary::unavailable($region_id)->toArray();
        }
    }
}

if (!function_exists('cat_datos_get_comunas')) {
    /**
     * Comunas of one region, most severe first, then by name (accent-aware). Empty array when
     * the region is unknown or has no data.
     *
     * @return list<array{
     *   comunaId: string,
     *   nombre: string,
     *   alertLevel: 'CRITICO'|'ALTO'|'PREVENTIVO'|'NORMAL'|'UNKNOWN',
     *   alertDriver: ?string,
     *   score: ?float,
     *   firmsCount: ?int,
     *   computedAtLocal: ?string
     * }>
     *   alertDriver is one of FIRMS_TODAY, FIRMS_CLUSTER, FIRMS_FRP, FWI, SCORE or null; never
     *   print it raw. computedAtLocal is "Y-m-d H:i" in America/Santiago.
     */
    function cat_datos_get_comunas(string $region_id): array
    {
        try {
            return Api::comunas(Plugin::repository(), $region_id);
        } catch (\Throwable) {
            return [];
        }
    }
}
