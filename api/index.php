<?php
/**
 * Wilayah API — RESTful API for Indonesian Administrative Regions
 *
 * Data source: Kepmendagri No 300.2.2-2138 Tahun 2025
 *
 * @author  cahya dsn
 * @version 2.0
 */

// ── bootstrap ──────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/functions.php';

// ── headers ────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('X-Powered-By: Wilayah-API/2.0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// Only GET is supported
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed. Use GET.', 405);
}

// ── resolve endpoint ───────────────────────────────────────────
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Dynamically detect base path so the API works in any sub-directory
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);          // e.g. "/api"
$endpoint  = trim(substr($requestUri, strlen($scriptDir)), '/');
$endpoint  = strtok($endpoint, '?');                    // strip query string

// ── routing ────────────────────────────────────────────────────
switch ($endpoint) {
    case '':
    case 'health':
        echo json_encode([
            'status'    => true,
            'message'   => 'Wilayah API is running',
            'version'   => '2.0',
            'endpoints' => [
                'GET /api/provinces'              => 'List all provinces',
                'GET /api/regencies?province=XX'  => 'List regencies/cities',
                'GET /api/districts?regency=XXXXX'=> 'List districts',
                'GET /api/villages?district=XXXXXXXX' => 'List villages',
                'GET /api/regions?level=N&parent=CODE' => 'Generic region list',
                'GET /api/search?q=keyword'       => 'Search by name',
                'GET /api/reverse?lat=X&lng=Y'    => 'Reverse geocoding: full address from coordinate',
                'GET /api/{code}'                 => 'Detail by region code',
                'GET /api/{code}?boundaries=1'    => 'Detail with boundary polygons',
                'GET /api/boundaries/{code}'      => 'Boundary polygon for a region (all levels)',
                'GET /api/boundaries/{code}?format=geojson' => 'Boundary as GeoJSON Feature',
                'GET /api/boundaries/{code}/children' => 'Child boundaries as GeoJSON FeatureCollection',
            ],
            'docs'      => 'See api/README.md for full documentation',
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;

    case 'provinces':
        require __DIR__ . '/handlers/provinces.php';
        break;

    case 'regencies':
        require __DIR__ . '/handlers/regencies.php';
        break;

    case 'districts':
        require __DIR__ . '/handlers/districts.php';
        break;

    case 'villages':
        require __DIR__ . '/handlers/villages.php';
        break;

    case 'regions':
        require __DIR__ . '/handlers/regions.php';
        break;

    case 'search':
        require __DIR__ . '/handlers/search.php';
        break;

    case 'reverse':
        require __DIR__ . '/handlers/reverse.php';
        break;

    default:
        // /boundaries/{code}/children — child boundaries as GeoJSON FeatureCollection
        if (preg_match('#^boundaries/([\d\.]+)/children$#', $endpoint, $m)) {
            $_GET['_code'] = $m[1];
            $_GET['_children'] = '1';
            require __DIR__ . '/handlers/boundaries.php';
            break;
        }

        // /boundaries/{code}
        if (preg_match('#^boundaries/([\d\.]+)$#', $endpoint, $m)) {
            $_GET['_code'] = $m[1];
            require __DIR__ . '/handlers/boundaries.php';
            break;
        }

        // /{code} — region detail
        if (preg_match('/^[\d\.]+$/', $endpoint)) {
            $_GET['_code'] = $endpoint;
            require __DIR__ . '/handlers/region_detail.php';
            break;
        }

        jsonError('Endpoint not found', 404);
        break;
}