<?php
/**
 * Shared helper functions for Wilayah API
 *
 * DB code format (with dots):
 *   Province:    XX            (2 chars)
 *   Regency:     XX.XX         (5 chars)
 *   District:    XX.XX.XX      (8 chars)
 *   Village:     XX.XX.XX.XXXX (13 chars)
 */

/**
 * Normalize user input to the dotted code format used in the database.
 * Accepts both dotted (11.01) and plain digit (1101) input.
 */
function normalizeCode(string $input): string
{
    $input = trim($input);

    // Already has dots — validate length and return
    if (strpos($input, '.') !== false) {
        $len = strlen($input);
        if (in_array($len, [2, 5, 8, 13]) && preg_match('/^[\d.]+$/', $input)) {
            return $input;
        }
        // Strip dots and re-format below
        $input = str_replace('.', '', $input);
    }

    // Pure digits — add dots based on digit count
    switch (strlen($input)) {
        case 2:  return $input;
        case 4:  return substr($input, 0, 2) . '.' . substr($input, 2, 2);
        case 6:  return substr($input, 0, 2) . '.' . substr($input, 2, 2) . '.' . substr($input, 4, 2);
        case 10: return substr($input, 0, 2) . '.' . substr($input, 2, 2) . '.' . substr($input, 4, 2) . '.' . substr($input, 6, 4);
        default: return $input;
    }
}

/**
 * Format a database code for API output. Alias of normalizeCode.
 */
function formatCode(string $code): string
{
    return normalizeCode($code);
}

/**
 * Determine the administrative level from code length (including dots).
 *   2 chars → 1 (province), 5 → 2 (regency), 8 → 3 (district), 13 → 4 (village)
 */
function codeLevel(string $code): int
{
    switch (strlen($code)) {
        case 2:  return 1;
        case 5:  return 2;
        case 8:  return 3;
        case 13: return 4;
        default: return 0;
    }
}

/**
 * Map level number to SQL CHAR_LENGTH.
 */
function levelToCharLength(int $level): int
{
    return [1 => 2, 2 => 5, 3 => 8, 4 => 13][$level] ?? 0;
}

/**
 * Build a standard region object from a database row.
 *
 * @param object $row           PDO row (FETCH_OBJ)
 * @param bool   $withBoundary  Include path/boundary polygon data
 */
function formatRegion(object $row, bool $withBoundary = false): array
{
    $raw  = $row->kode;
    $len  = strlen($raw);
    $data = [
        'code'  => formatCode($raw),
        'name'  => $row->nama,
        'level' => codeLevel($raw),
    ];

    if (isset($row->ibukota))   $data['capital']    = $row->ibukota;

    $data['coordinates'] = [
        'latitude'  => isset($row->lat) ? (float) $row->lat : null,
        'longitude' => isset($row->lng) ? (float) $row->lng : null,
    ];

    if (isset($row->elv))       $data['elevation']   = $row->elv !== null ? (float) $row->elv : null;
    if (isset($row->tz))        $data['timezone']    = $row->tz !== null ? (float) $row->tz : null;
    if (isset($row->luas))      $data['area']        = $row->luas !== null ? (float) $row->luas : null;
    if (isset($row->penduduk))  $data['population']  = $row->penduduk !== null ? (int) $row->penduduk : null;
    if (isset($row->status))    $data['status']      = $row->status;

    // Parent references (raw code already has dots)
    if ($len >= 5)  $data['province_code'] = substr($raw, 0, 2);
    if ($len >= 8)  $data['regency_code']  = substr($raw, 0, 5);
    if ($len >= 13) $data['district_code'] = substr($raw, 0, 8);

    if ($withBoundary && isset($row->path) && !empty($row->path)) {
        $decoded = json_decode($row->path, true);
        $data['boundaries'] = $decoded !== null ? $decoded : $row->path;
    } elseif ($withBoundary) {
        $data['boundaries'] = null;
    }

    return $data;
}

/**
 * Send a JSON success response and exit.
 */
function jsonSuccess(array $data, array $meta = []): void
{
    $payload = ['status' => true, 'data' => $data];
    if (!empty($meta)) {
        $payload['meta'] = $meta;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send a JSON error response and exit.
 */
function jsonError(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode([
        'status'  => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Read an optional boolean query parameter (?boundaries=1 / true / yes).
 */
function wantsBoundaries(): bool
{
    if (!isset($_GET['boundaries'])) {
        return false;
    }
    $v = strtolower(trim($_GET['boundaries']));
    return in_array($v, ['1', 'true', 'yes'], true);
}

/**
 * Get a sanitised integer query param with bounds.
 */
function intParam(string $name, int $default, int $max = PHP_INT_MAX): int
{
    $val = isset($_GET[$name]) ? (int) $_GET[$name] : $default;
    return min(max($val, 0), $max);
}

/**
 * Columns to SELECT from wilayah_level_1_2 — includes `path` only when needed.
 */
function selectColumns(bool $withPath = false): string
{
    $cols = 'kode, nama, ibukota, lat, lng, elv, tz, luas, penduduk, status';
    if ($withPath) {
        $cols .= ', path';
    }
    return $cols;
}

/**
 * Build a basic region array from the `wilayah` table joined with luas/penduduk.
 * Used for levels 3-4 (districts, villages) which aren't in wilayah_level_1_2.
 * Now supports coordinates and boundaries from wilayah_boundaries table.
 */
function formatRegionBasic(object $row): array
{
    $raw  = $row->kode;
    $len  = strlen($raw);
    $data = [
        'code'  => $raw,
        'name'  => $row->nama,
        'level' => codeLevel($raw),
    ];

    $data['coordinates'] = [
        'latitude'  => isset($row->lat) && $row->lat !== null ? (float) $row->lat : null,
        'longitude' => isset($row->lng) && $row->lng !== null ? (float) $row->lng : null,
    ];
    $data['area']        = isset($row->luas) && $row->luas !== null ? (float) $row->luas : null;
    $data['population']  = isset($row->total) && $row->total !== null ? (int) $row->total : null;

    if ($len >= 5)  $data['province_code'] = substr($raw, 0, 2);
    if ($len >= 8)  $data['regency_code']  = substr($raw, 0, 5);
    if ($len >= 13) $data['district_code'] = substr($raw, 0, 8);

    if (isset($row->path) && !empty($row->path)) {
        $decoded = json_decode($row->path, true);
        $data['boundaries'] = $decoded !== null ? $decoded : $row->path;
    }

    return $data;
}
