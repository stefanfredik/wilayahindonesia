<?php
/**
 * Wilayah API — Comprehensive Unit & Integration Test
 *
 * Run inside Docker:
 *   docker compose exec app php api/test.php
 *
 * Test includes:
 *   Part 1 — Unit tests (helper functions)
 *   Part 2 — Database direct tests (tables, counts, integrity)
 *   Part 3 — API integration tests (all endpoints via HTTP)
 */

$BASE = rtrim(getenv('API_BASE_URL') ?: 'http://localhost:8080', '/') . '/api';

$pass  = 0;
$fail  = 0;
$total = 0;

/* ── Test Helpers ────────────────────────────────────────────── */

function section(string $title): void
{
    echo "\n\033[1;34m┌── {$title}\033[0m\n";
}

function check(string $label, bool $ok): void
{
    global $pass, $fail, $total;
    $total++;
    if ($ok) {
        $pass++;
        echo "  \033[32m✅  {$label}\033[0m\n";
    } else {
        $fail++;
        echo "  \033[31m❌  {$label}\033[0m\n";
    }
}

function api(string $path): array
{
    global $BASE;
    $url = $BASE . '/' . ltrim($path, '/');
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $code = (int) ($m[0] ?? 0);
    }
    $json = json_decode($body ?: '', true);
    return ['code' => $code, 'body' => $json, 'raw' => $body];
}

echo "\033[1;37m══════════════════════════════════════════════\033[0m\n";
echo "\033[1;37m  Wilayah API — Comprehensive Test Suite\033[0m\n";
echo "\033[1;37m══════════════════════════════════════════════\033[0m\n";


/* ================================================================
 *  PART 1 — Unit Tests (helper functions)
 * ================================================================ */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/functions.php';

// ── 1. normalizeCode ────────────────────────────────────────────

section('1. normalizeCode()');
check('2 digit stays: 51 → 51',                   normalizeCode('51') === '51');
check('4 digits → XX.XX: 5104 → 51.04',           normalizeCode('5104') === '51.04');
check('6 digits → XX.XX.XX: 510401 → 51.04.01',   normalizeCode('510401') === '51.04.01');
check('10 digits → XX.XX.XX.XXXX',                 normalizeCode('5104012003') === '51.04.01.2003');
check('Already dotted 5 char passthrough',          normalizeCode('51.04') === '51.04');
check('Already dotted 8 char passthrough',          normalizeCode('51.04.01') === '51.04.01');
check('Already dotted 13 char passthrough',         normalizeCode('51.04.01.2003') === '51.04.01.2003');
check('Trims whitespace',                           normalizeCode('  51  ') === '51');
check('Odd length returns as-is: 123',              normalizeCode('123') === '123');

// ── 2. formatCode ───────────────────────────────────────────────

section('2. formatCode()');
check('formatCode aliases normalizeCode',           formatCode('5104') === '51.04');
check('formatCode passthrough',                     formatCode('51.04') === '51.04');

// ── 3. codeLevel ────────────────────────────────────────────────

section('3. codeLevel()');
check('Level 1 (province): 51',                    codeLevel('51') === 1);
check('Level 2 (regency): 51.04',                  codeLevel('51.04') === 2);
check('Level 3 (district): 51.04.01',              codeLevel('51.04.01') === 3);
check('Level 4 (village): 51.04.01.2003',          codeLevel('51.04.01.2003') === 4);
check('Unknown length → 0',                        codeLevel('5') === 0);
check('Length 3 → 0',                              codeLevel('51.') === 0);

// ── 4. levelToCharLength ────────────────────────────────────────

section('4. levelToCharLength()');
check('Level 1 → 2',   levelToCharLength(1) === 2);
check('Level 2 → 5',   levelToCharLength(2) === 5);
check('Level 3 → 8',   levelToCharLength(3) === 8);
check('Level 4 → 13',  levelToCharLength(4) === 13);
check('Level 0 → 0',   levelToCharLength(0) === 0);
check('Level 5 → 0',   levelToCharLength(5) === 0);

// ── 5. selectColumns ────────────────────────────────────────────

section('5. selectColumns()');
check('Without path excludes path',  strpos(selectColumns(false), 'path') === false);
check('With path includes path',     strpos(selectColumns(true), 'path') !== false);
check('Always includes kode',        strpos(selectColumns(false), 'kode') !== false);
check('Always includes nama',        strpos(selectColumns(false), 'nama') !== false);


/* ================================================================
 *  PART 2 — Database Direct Tests
 * ================================================================ */

section('6. Database Connection');
try {
    $db = DatabaseConfig::getInstance()->getConnection();
    check('PDO connection established', true);
} catch (Exception $e) {
    check('PDO connection failed: ' . $e->getMessage(), false);
    echo "\n\033[31m⚠ Cannot continue without database.\033[0m\n";
    exit(1);
}

// ── 7. Table Existence ──────────────────────────────────────────

section('7. Tables Existence');
$tables = ['wilayah', 'wilayah_level_1_2', 'wilayah_boundaries', 'wilayah_luas', 'wilayah_penduduk', 'wilayah_pulau'];
foreach ($tables as $t) {
    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        check("Table '{$t}' exists ({$count} rows)", $count >= 0);
    } catch (Exception $e) {
        check("Table '{$t}' missing", false);
    }
}

// ── 8. Data Counts ──────────────────────────────────────────────

section('8. Data Counts (wilayah)');
$provCount = (int) $db->query("SELECT COUNT(*) FROM wilayah WHERE CHAR_LENGTH(kode)=2")->fetchColumn();
$kabCount  = (int) $db->query("SELECT COUNT(*) FROM wilayah WHERE CHAR_LENGTH(kode)=5")->fetchColumn();
$kecCount  = (int) $db->query("SELECT COUNT(*) FROM wilayah WHERE CHAR_LENGTH(kode)=8")->fetchColumn();
$desaCount = (int) $db->query("SELECT COUNT(*) FROM wilayah WHERE CHAR_LENGTH(kode)=13")->fetchColumn();
$totalWil  = (int) $db->query("SELECT COUNT(*) FROM wilayah")->fetchColumn();

check("Total records: {$totalWil}",        $totalWil >= 90000);
check("Provinces: {$provCount}",            $provCount >= 34);
check("Regencies: {$kabCount}",             $kabCount >= 500);
check("Districts: {$kecCount}",             $kecCount >= 7000);
check("Villages: {$desaCount}",             $desaCount >= 80000);

// ── 9. Boundary Data ────────────────────────────────────────────

section('9. Boundary Data (wilayah_boundaries)');
$bndTotal = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries")->fetchColumn();
$bndProv  = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE CHAR_LENGTH(kode)=2")->fetchColumn();
$bndKab   = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE CHAR_LENGTH(kode)=5")->fetchColumn();
$bndKec   = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE CHAR_LENGTH(kode)=8")->fetchColumn();
$bndDesa  = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE CHAR_LENGTH(kode)=13")->fetchColumn();

check("Total boundaries: {$bndTotal}",     $bndTotal >= 90000);
check("Province boundaries: {$bndProv}",    $bndProv >= 30);
check("Regency boundaries: {$bndKab}",      $bndKab >= 500);
check("District boundaries: {$bndKec}",     $bndKec >= 7000);
check("Village boundaries: {$bndDesa}",     $bndDesa >= 80000);

// Verify JSON decode
$sample = $db->query("SELECT kode, nama, path FROM wilayah_boundaries WHERE path IS NOT NULL AND path != '' LIMIT 1")->fetch();
if ($sample) {
    $decoded = json_decode($sample->path, true);
    check("Boundary JSON valid ({$sample->kode})", $decoded !== null && is_array($decoded));
} else {
    check('Boundary sample exists', false);
}

// ── 10. Data Integrity ──────────────────────────────────────────

section('10. Data Integrity');

$bali = $db->query("SELECT kode, nama FROM wilayah WHERE kode = '51'")->fetch();
check('Province Bali (51) exists', $bali && stripos($bali->nama, 'Bali') !== false);

$gianyar = $db->query("SELECT kode, nama FROM wilayah WHERE kode = '51.04'")->fetch();
check('Regency Gianyar (51.04) exists', $gianyar && stripos($gianyar->nama, 'Gianyar') !== false);

$sukawati = $db->query("SELECT kode, nama FROM wilayah WHERE kode = '51.04.01'")->fetch();
check('District Sukawati (51.04.01) exists', $sukawati !== false);

$childCount = (int) $db->query("SELECT COUNT(*) FROM wilayah WHERE CHAR_LENGTH(kode)=13 AND kode LIKE '51.04.01.%'")->fetchColumn();
check("Villages under Sukawati: {$childCount}", $childCount > 0);

// Cross-check level_1_2 vs wilayah
$l12Prov = (int) $db->query("SELECT COUNT(*) FROM wilayah_level_1_2 WHERE CHAR_LENGTH(kode)=2")->fetchColumn();
check("level_1_2 provinces ({$l12Prov}) consistent", $l12Prov > 0 && $l12Prov <= $provCount);


/* ================================================================
 *  PART 3 — API Integration Tests (HTTP)
 * ================================================================ */

// Check if API is reachable
$probe = @file_get_contents($BASE . '/', false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
if ($probe === false) {
    echo "\n\033[33m⚠ API not reachable at {$BASE}. Skipping HTTP tests.\033[0m\n";
    echo "   Ensure containers are running: docker compose up -d\n";
    goto results;
}

// ── 11. Health Check ────────────────────────────────────────────

section('11. Health Check');
$r = api('/');
check('GET / → 200',                       $r['code'] === 200);
check('status is true',                     ($r['body']['status'] ?? false) === true);
check('message contains "running"',         stripos($r['body']['message'] ?? '', 'running') !== false);
check('version is 2.0',                     ($r['body']['version'] ?? '') === '2.0');
check('endpoints list present',             is_array($r['body']['endpoints'] ?? null));

$r = api('/health');
check('GET /health → 200',                 $r['code'] === 200);

// ── 12. Provinces ───────────────────────────────────────────────

section('12. GET /provinces');
$r = api('/provinces');
check('Returns 200',                        $r['code'] === 200);
check('status is true',                     ($r['body']['status'] ?? false) === true);
check('data is array',                      is_array($r['body']['data'] ?? null));
check('meta.total > 0',                     ($r['body']['meta']['total'] ?? 0) > 0);

$prov = $r['body']['data'][0] ?? null;
check('Has code field',                     isset($prov['code']));
check('Has name field',                     isset($prov['name']));
check('Has level=1',                        ($prov['level'] ?? 0) === 1);
check('Has coordinates',                    isset($prov['coordinates']));
check('No boundaries by default',           !isset($prov['boundaries']));

// With boundaries
$r = api('/provinces?boundaries=1');
$prov = $r['body']['data'][0] ?? null;
check('boundaries=1 → includes boundaries', isset($prov['boundaries']));

// Pagination
$r = api('/provinces?limit=5&offset=0');
check('limit=5 → ≤5 results',              count($r['body']['data'] ?? []) <= 5);
check('meta.limit=5',                       ($r['body']['meta']['limit'] ?? 0) === 5);
check('meta.offset=0',                      ($r['body']['meta']['offset'] ?? -1) === 0);

$r2 = api('/provinces?limit=5&offset=5');
check('offset=5 → different first item',    ($r2['body']['data'][0]['code'] ?? '') !== ($r['body']['data'][0]['code'] ?? ''));

// ── 13. Regencies ───────────────────────────────────────────────

section('13. GET /regencies');
$r = api('/regencies');
check('Returns 200',                         $r['code'] === 200);
check('meta.total > 0',                      ($r['body']['meta']['total'] ?? 0) > 0);

// Filter by province
$r = api('/regencies?province=51');
check('province=51 → has data',             ($r['body']['meta']['total'] ?? 0) > 0);
$reg = $r['body']['data'][0] ?? null;
check('Regency level=2',                    ($reg['level'] ?? 0) === 2);
check('Code starts with 51.',               strpos($reg['code'] ?? '', '51.') === 0);

// With boundaries
$r = api('/regencies?province=51&boundaries=1');
$reg = $r['body']['data'][0] ?? null;
check('boundaries=1 → includes boundaries', isset($reg['boundaries']));

// ── 14. Districts ───────────────────────────────────────────────

section('14. GET /districts');
$r = api('/districts?regency=51.04');
check('regency=51.04 → 200',                $r['code'] === 200);
check('meta.total > 0',                      ($r['body']['meta']['total'] ?? 0) > 0);

$dist = $r['body']['data'][0] ?? null;
check('District level=3',                    ($dist['level'] ?? 0) === 3);
check('Has coordinates',                     isset($dist['coordinates']));
check('Has province_code',                   isset($dist['province_code']));
check('Has regency_code',                    isset($dist['regency_code']));

// Without dots
$r2 = api('/districts?regency=5104');
check('regency=5104 (no dots) → same count', ($r2['body']['meta']['total'] ?? 0) === ($r['body']['meta']['total'] ?? -1));

// By province
$r = api('/districts?province=51');
check('province=51 → returns districts',     ($r['body']['meta']['total'] ?? 0) > 0);

// ── 15. Villages ────────────────────────────────────────────────

section('15. GET /villages');
$r = api('/villages?district=51.04.01');
check('district=51.04.01 → 200',            $r['code'] === 200);
check('meta.total > 0',                      ($r['body']['meta']['total'] ?? 0) > 0);

$vil = $r['body']['data'][0] ?? null;
check('Village level=4',                     ($vil['level'] ?? 0) === 4);
check('Has coordinates',                     isset($vil['coordinates']));
check('Has district_code',                   isset($vil['district_code']));
check('Has regency_code',                    isset($vil['regency_code']));
check('Has province_code',                   isset($vil['province_code']));

// By regency
$r = api('/villages?regency=51.04&limit=10');
check('regency=51.04 → has data',           ($r['body']['meta']['total'] ?? 0) > 0);

// By province
$r = api('/villages?province=51&limit=10');
check('province=51 → has data',             ($r['body']['meta']['total'] ?? 0) > 0);

// Without dots
$r2 = api('/villages?district=510401');
check('district=510401 (no dots) works',    ($r2['body']['meta']['total'] ?? 0) > 0);

// ── 16. Regions (generic) ───────────────────────────────────────

section('16. GET /regions');
$r = api('/regions?level=1');
check('level=1 → provinces',                ($r['body']['meta']['total'] ?? 0) >= 34);

$r = api('/regions?level=2&parent=51');
check('level=2&parent=51 → regencies',      ($r['body']['meta']['total'] ?? 0) > 0);

$r = api('/regions?level=3&parent=51.04');
check('level=3&parent=51.04 → districts',   ($r['body']['meta']['total'] ?? 0) > 0);

$r = api('/regions?level=4&parent=51.04.01');
check('level=4&parent=51.04.01 → villages', ($r['body']['meta']['total'] ?? 0) > 0);

// ── 17. Search ──────────────────────────────────────────────────

section('17. GET /search');
$r = api('/search?q=guwang');
check('q=guwang → 200',                     $r['code'] === 200);
check('Found results',                       count($r['body']['data'] ?? []) > 0);
check('Name contains "guwang"',              stripos($r['body']['data'][0]['name'] ?? '', 'guwang') !== false);

// Level filter
$r = api('/search?q=sukawati&level=3');
check('level=3 filter works',               count($r['body']['data'] ?? []) > 0);
$allLevel3 = true;
foreach (($r['body']['data'] ?? []) as $item) {
    if (($item['level'] ?? 0) !== 3) { $allLevel3 = false; break; }
}
check('All results are level 3',             $allLevel3);

// Limit
$r = api('/search?q=bali&limit=3');
check('limit=3 → ≤3 results',               count($r['body']['data'] ?? []) <= 3);

// Missing q
$r = api('/search');
check('Missing q → 400',                    $r['code'] === 400);

// Single char query (accepted)
$r = api('/search?q=a');
check('q=a (single char) → 200',              $r['code'] === 200);

// ── 18. Region Detail ───────────────────────────────────────────

section('18. GET /{code} (Region Detail)');

// Province
$r = api('/51');
check('GET /51 → 200',                              $r['code'] === 200);
check('code=51',                                     ($r['body']['data']['code'] ?? '') === '51');
check('level=1',                                     ($r['body']['data']['level'] ?? 0) === 1);
check('Has children array',                          is_array($r['body']['data']['children'] ?? null));
check('Children are level 2',                        ($r['body']['data']['children'][0]['level'] ?? 0) === 2);
check('Has name',                                    !empty($r['body']['data']['name'] ?? ''));
check('Has coordinates',                             isset($r['body']['data']['coordinates']));

// Regency
$r = api('/51.04');
check('GET /51.04 → 200',                           $r['code'] === 200);
check('Children are level 3',                        ($r['body']['data']['children'][0]['level'] ?? 0) === 3);

// District
$r = api('/51.04.01');
check('GET /51.04.01 → 200',                        $r['code'] === 200);
check('level=3',                                     ($r['body']['data']['level'] ?? 0) === 3);
check('Children are level 4',                        ($r['body']['data']['children'][0]['level'] ?? 0) === 4);

// Village (leaf)
$r = api('/51.04.01.2003');
check('GET /51.04.01.2003 → 200',                   $r['code'] === 200);
check('level=4',                                     ($r['body']['data']['level'] ?? 0) === 4);
check('Children is empty (leaf)',                    count($r['body']['data']['children'] ?? ['x']) === 0);
check('Has coordinates',                             isset($r['body']['data']['coordinates']));

// boundaries=1 on detail
$r = api('/51.04.01?boundaries=1');
$child = $r['body']['data']['children'][0] ?? [];
check('boundaries=1 → children have path',          isset($child['boundaries']));

// No dots input
$r = api('/5104');
check('GET /5104 (no dots) → 200',                  $r['code'] === 200);
check('Resolves to code 51.04',                      ($r['body']['data']['code'] ?? '') === '51.04');

// Not found
$r = api('/99');
check('GET /99 → 404',                              $r['code'] === 404);
check('status=false',                                ($r['body']['status'] ?? true) === false);

// ── 19. Boundaries (raw) ────────────────────────────────────────

section('19. GET /boundaries/{code} (raw)');

$r = api('/boundaries/51');
check('Province /boundaries/51 → 200',              $r['code'] === 200);
check('Has center',                                  isset($r['body']['data']['center']));
check('Has boundaries array',                        is_array($r['body']['data']['boundaries'] ?? null));

$r = api('/boundaries/51.04');
check('Regency /boundaries/51.04 → 200',            $r['code'] === 200);
check('Has boundary polygon',                        !empty($r['body']['data']['boundaries']));

$r = api('/boundaries/51.04.01');
check('District /boundaries/51.04.01 → 200',        $r['code'] === 200);
check('Has boundary polygon',                        !empty($r['body']['data']['boundaries']));

$r = api('/boundaries/51.04.01.2003');
check('Village /boundaries/51.04.01.2003 → 200',    $r['code'] === 200);
check('Has boundary polygon',                        !empty($r['body']['data']['boundaries']));

$r = api('/boundaries/99.99');
check('Not found → 404',                            $r['code'] === 404);

// ── 20. Boundaries (GeoJSON) ────────────────────────────────────

section('20. GET /boundaries/{code}?format=geojson');

$r = api('/boundaries/51.04?format=geojson');
check('type=Feature',                                ($r['body']['type'] ?? '') === 'Feature');
check('properties.code exists',                      isset($r['body']['properties']['code']));
check('properties.name exists',                      isset($r['body']['properties']['name']));
check('geometry.type exists',                        isset($r['body']['geometry']['type']));
check('geometry.type is Polygon/Multi',              in_array($r['body']['geometry']['type'] ?? '', ['Polygon', 'MultiPolygon']));
check('geometry.coordinates exists',                 !empty($r['body']['geometry']['coordinates']));

// Verify [lng, lat] order per GeoJSON spec
$coords = $r['body']['geometry']['coordinates'][0][0] ?? null;
if (is_array($coords) && count($coords) >= 2) {
    $lng = $coords[0];
    $lat = $coords[1];
    check('GeoJSON [lng,lat]: lng in 90-141',        $lng >= 90 && $lng <= 141);
    check('GeoJSON [lng,lat]: lat in -11 to 6',      $lat >= -11 && $lat <= 6);
} else {
    check('GeoJSON coordinate format valid', false);
    check('GeoJSON coordinate range valid', false);
}

// Village GeoJSON
$r = api('/boundaries/51.04.01.2003?format=geojson');
check('Village GeoJSON type=Feature',                ($r['body']['type'] ?? '') === 'Feature');

// ── 21. Boundary Children ───────────────────────────────────────

section('21. GET /boundaries/{code}/children (FeatureCollection)');

// Province → kabupaten
$r = api('/boundaries/51/children');
check('GET /boundaries/51/children → 200',           $r['code'] === 200);
check('type=FeatureCollection',                      ($r['body']['type'] ?? '') === 'FeatureCollection');
check('Has features array',                          is_array($r['body']['features'] ?? null));
check('features count > 0',                          count($r['body']['features'] ?? []) > 0);
check('parent_code is 51',                           ($r['body']['properties']['parent_code'] ?? '') === '51');

$f = $r['body']['features'][0] ?? null;
check('Feature type=Feature',                        ($f['type'] ?? '') === 'Feature');
check('Feature has properties.code',                 isset($f['properties']['code']));
check('Feature has geometry',                        isset($f['geometry']));

// Regency → kecamatan
$r = api('/boundaries/51.04/children');
check('/boundaries/51.04/children → FC',             ($r['body']['type'] ?? '') === 'FeatureCollection');
$kecFeatures = count($r['body']['features'] ?? []);
check("Kecamatan features: {$kecFeatures}",          $kecFeatures > 0);

// District → desa
$r = api('/boundaries/51.04.01/children');
check('/boundaries/51.04.01/children → FC',          ($r['body']['type'] ?? '') === 'FeatureCollection');
$desaFeatures = count($r['body']['features'] ?? []);
check("Desa features: {$desaFeatures}",              $desaFeatures > 0);

// Village children → error (lowest level)
$r = api('/boundaries/51.04.01.2003/children');
check('Village → error (lowest level)',               ($r['body']['status'] ?? true) === false);

// ── 22. Error Handling ──────────────────────────────────────────

section('22. Error Handling');

$r = api('/nonexistent-endpoint');
check('Unknown endpoint → 404',                     $r['code'] === 404);
check('Error has status=false',                      ($r['body']['status'] ?? true) === false);
check('Error has message',                           !empty($r['body']['message'] ?? ''));

// ── 23. Pagination Edge Cases ───────────────────────────────────

section('23. Pagination Edge Cases');

$r = api('/provinces?limit=0');
check('limit=0 → 200 (treated as 0)',               $r['code'] === 200);

$r = api('/provinces?limit=9999');
check('limit=9999 → capped ≤1000',                  ($r['body']['meta']['limit'] ?? 9999) <= 1000);

$r = api('/provinces?offset=999');
check('High offset → empty data',                   count($r['body']['data'] ?? []) === 0);
check('meta.total still reported',                   ($r['body']['meta']['total'] ?? 0) > 0);

$r = api('/districts?regency=51.04&limit=3');
check('Districts limit=3 → ≤3 results',             count($r['body']['data'] ?? []) <= 3);
check('meta.limit=3',                               ($r['body']['meta']['limit'] ?? 0) === 3);

// ── 24. Input Flexibility (dots vs no dots) ─────────────────────

section('24. Input Flexibility');

$r1 = api('/districts?regency=51.04');
$r2 = api('/districts?regency=5104');
$t1 = $r1['body']['meta']['total'] ?? 0;
$t2 = $r2['body']['meta']['total'] ?? 0;
check("regency 51.04 ({$t1}) = 5104 ({$t2})",       $t1 === $t2 && $t1 > 0);

$r1 = api('/villages?district=51.04.01');
$r2 = api('/villages?district=510401');
$t1 = $r1['body']['meta']['total'] ?? 0;
$t2 = $r2['body']['meta']['total'] ?? 0;
check("district 51.04.01 ({$t1}) = 510401 ({$t2})", $t1 === $t2 && $t1 > 0);

// ── 25. Response Structure Consistency ──────────────────────────

section('25. Response Structure Consistency');

$listEndpoints = [
    '/provinces',
    '/regencies?province=51',
    '/districts?regency=51.04',
    '/villages?district=51.04.01',
    '/regions?level=1',
    '/search?q=bali',
];

foreach ($listEndpoints as $ep) {
    $r = api($ep);
    $hasAll = isset($r['body']['status'], $r['body']['data'], $r['body']['meta']);
    check("{$ep} → status+data+meta", $hasAll);
}

// Detail has status+data with children
$r = api('/51');
check('Detail /51 → status+data',                   isset($r['body']['status'], $r['body']['data']));
check('Detail /51 → data.children',                 isset($r['body']['data']['children']));

// Boundary has status+data
$r = api('/boundaries/51');
check('Boundary /51 → status+data',                 isset($r['body']['status'], $r['body']['data']));

// GeoJSON has type+properties+geometry (no status wrapper)
$r = api('/boundaries/51?format=geojson');
check('GeoJSON → no status wrapper',                !isset($r['body']['status']));
check('GeoJSON → type+properties+geometry',          isset($r['body']['type'], $r['body']['properties'], $r['body']['geometry']));

// FeatureCollection has type+features
$r = api('/boundaries/51/children');
check('FC → type+properties+features',              isset($r['body']['type'], $r['body']['properties'], $r['body']['features']));


/* ================================================================
 *  RESULTS
 * ================================================================ */

results:

$failed = $fail > 0;
$color  = $failed ? "\033[31m" : "\033[32m";

echo "\n{$color}══════════════════════════════════════════════\033[0m\n";
echo "{$color}  Results: {$pass} passed, {$fail} failed (of {$total} tests)\033[0m\n";
echo "{$color}══════════════════════════════════════════════\033[0m\n\n";

exit($failed ? 1 : 0);
