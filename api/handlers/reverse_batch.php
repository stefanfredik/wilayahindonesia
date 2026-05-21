<?php
/**
 * Batch Reverse Geocoding Handler — POST /api/reverse/batch
 *
 * Resolves up to 500 coordinate points in a single request.
 * Dramatically faster than calling /api/reverse individually
 * because all ST_Contains lookups share one DB connection and
 * parent-name lookups are batched into a single IN() query.
 *
 * Request body (JSON):
 *   {
 *     "points": [
 *       { "id": "p1", "lat": -6.1754, "lng": 106.8272 },
 *       { "id": "p2", "lat": -7.2575, "lng": 112.7521 },
 *       ...
 *     ]
 *   }
 *
 * Optional field "id" — any string/number you pass in; echoed back so you
 * can correlate results to your input.  Defaults to the array index.
 *
 * Response:
 *   {
 *     "status": true,
 *     "meta": { "total": 300, "resolved": 298, "duration_ms": 210 },
 *     "data": [
 *       {
 *         "id": "p1",
 *         "coordinates": { "latitude": -6.1754, "longitude": 106.8272 },
 *         "province":  { "code": "31", "name": "DKI Jakarta" },
 *         "regency":   { "code": "31.71", "name": "Kota Administrasi Jakarta Pusat" },
 *         "district":  { "code": "31.71.01", "name": "Gambir" },
 *         "village":   { "code": "31.71.01.1001", "name": "Gambir" },
 *         "full_address": "Gambir, Gambir, Kota Administrasi Jakarta Pusat, DKI Jakarta",
 *         "match_type": "spatial"
 *       },
 *       ...
 *     ]
 *   }
 */

// ── Accept POST only ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Batch endpoint requires POST with JSON body.', 405);
}

$body = file_get_contents('php://input');
$input = json_decode($body, true);

if (!isset($input['points']) || !is_array($input['points'])) {
    jsonError('Request body must be JSON with a "points" array.', 400);
}

$MAX_POINTS = 500;
$points = array_slice($input['points'], 0, $MAX_POINTS);

if (empty($points)) {
    jsonError('"points" array is empty.', 400);
}

$startTime = microtime(true);

// ── Validate & normalise input ───────────────────────────────────────────────
$validPoints = [];   // [index => [id, lat, lng]]
$results     = [];   // final output array (same order as input)

foreach ($points as $i => $pt) {
    $id  = isset($pt['id'])  ? $pt['id']  : $i;
    $lat = isset($pt['lat']) ? (float) $pt['lat'] : null;
    $lng = isset($pt['lng']) ? (float) $pt['lng'] : null;

    $baseResult = [
        'id'          => $id,
        'coordinates' => ['latitude' => $lat, 'longitude' => $lng],
        'province'    => null,
        'regency'     => null,
        'district'    => null,
        'village'     => null,
        'full_address'=> null,
        'match_type'  => 'error',
        'error'       => null,
    ];

    if ($lat === null || $lng === null) {
        $baseResult['error'] = 'Missing lat or lng.';
        $results[$i] = $baseResult;
        continue;
    }

    if ($lat < -11.5 || $lat > 6.5 || $lng < 94.0 || $lng > 141.5) {
        $baseResult['error'] = 'Coordinates outside Indonesian territory.';
        $results[$i] = $baseResult;
        continue;
    }

    $validPoints[$i] = ['id' => $id, 'lat' => $lat, 'lng' => $lng];
    $results[$i]     = $baseResult;
}

// ── Resolve valid points ─────────────────────────────────────────────────────
try {
    $db = DatabaseConfig::getInstance()->getConnection();

    $hasSpatial = hasSpatialIndexBatch($db);

    foreach ($validPoints as $i => $pt) {
        $village = null;

        if ($hasSpatial) {
            $village = spatialLookup($db, $pt['lat'], $pt['lng']);
        }

        if (!$village) {
            $village = bboxLookup($db, $pt['lat'], $pt['lng'], 0.5);
        }

        if (!$village) {
            $village = bboxLookup($db, $pt['lat'], $pt['lng'], 1.5);
        }

        if ($village) {
            $results[$i]['_village']    = $village->kode;
            $results[$i]['match_type']  = $village->_match_type;
            $results[$i]['village']     = [
                'code' => formatCode($village->kode),
                'name' => $village->nama,
            ];
        } else {
            $results[$i]['error'] = 'No region found (ocean or data gap).';
        }
    }

    // ── Batch-fetch all parent names in ONE query ────────────────────────────
    $villageCodes = [];
    foreach ($results as $r) {
        if (!empty($r['_village'])) $villageCodes[] = $r['_village'];
    }

    $parentMap = batchFetchParents($db, $villageCodes);

    // ── Assemble final results ────────────────────────────────────────────────
    $resolved = 0;
    foreach ($results as &$r) {
        if (empty($r['_village'])) continue;

        $vc  = $r['_village'];
        $dc  = substr($vc, 0, 8);
        $rc  = substr($vc, 0, 5);
        $pc  = substr($vc, 0, 2);

        $pn = $parentMap[$pc] ?? null;
        $rn = $parentMap[$rc] ?? null;
        $dn = $parentMap[$dc] ?? null;
        $vn = $r['village']['name'];

        $r['province'] = ['code' => formatCode($pc), 'name' => $pn];
        $r['regency']  = ['code' => formatCode($rc), 'name' => $rn];
        $r['district'] = ['code' => formatCode($dc), 'name' => $dn];
        $r['full_address'] = implode(', ', array_filter([$vn, $dn, $rn, $pn]));

        unset($r['_village']);
        $resolved++;
    }
    unset($r);

    $duration = round((microtime(true) - $startTime) * 1000);

    $payload = [
        'status' => true,
        'meta'   => [
            'total'       => count($points),
            'resolved'    => $resolved,
            'failed'      => count($points) - $resolved,
            'duration_ms' => $duration,
            'avg_ms'      => count($points) > 0 ? round($duration / count($points), 1) : 0,
        ],
        'data' => array_values($results),
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════

function hasSpatialIndexBatch(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $n = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'wilayah_boundaries'
           AND INDEX_NAME   = 'idx_geom'"
    )->fetchColumn();
    $cache = ($n > 0);
    return $cache;
}

function spatialLookup(PDO $db, float $lat, float $lng): ?object
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $db->prepare(
            "SELECT kode, nama, lat, lng
             FROM wilayah_boundaries
             WHERE CHAR_LENGTH(kode) = 13
               AND ST_Contains(geom, ST_GeomFromText(CONCAT('POINT(', :lng, ' ', :lat, ')'), 0))
             ORDER BY (POW(lat - :lat2, 2) + POW(lng - :lng2, 2))
             LIMIT 1"
        );
    }
    $stmt->bindValue(':lat',  $lat);
    $stmt->bindValue(':lng',  $lng);
    $stmt->bindValue(':lat2', $lat);
    $stmt->bindValue(':lng2', $lng);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) return null;
    $row->_match_type = 'spatial';
    return $row;
}

function bboxLookup(PDO $db, float $lat, float $lng, float $delta): ?object
{
    $stmt = $db->prepare(
        "SELECT kode, nama, lat, lng, path
         FROM wilayah_boundaries
         WHERE CHAR_LENGTH(kode) = 13
           AND lat  BETWEEN :latMin AND :latMax
           AND lng  BETWEEN :lngMin AND :lngMax
           AND path IS NOT NULL AND path != ''
         ORDER BY (POW(lat - :lat, 2) + POW(lng - :lng, 2))
         LIMIT 50"
    );
    $stmt->bindValue(':latMin', $lat - $delta);
    $stmt->bindValue(':latMax', $lat + $delta);
    $stmt->bindValue(':lngMin', $lng - $delta);
    $stmt->bindValue(':lngMax', $lng + $delta);
    $stmt->bindValue(':lat',    $lat);
    $stmt->bindValue(':lng',    $lng);
    $stmt->execute();
    $candidates = $stmt->fetchAll();
    if (empty($candidates)) return null;

    foreach ($candidates as $row) {
        $coords = json_decode($row->path, true);
        if (!is_array($coords)) continue;
        if (pointInPolygonBatch($lat, $lng, $coords)) {
            $row->_match_type = 'polygon';
            return $row;
        }
    }

    if ($delta <= 0.5) {
        $candidates[0]->_match_type = 'nearest_centroid';
        return $candidates[0];
    }

    return null;
}

/**
 * Batch-fetch province, regency, and district names for many village codes
 * in a SINGLE SQL query using IN() — far more efficient than N individual queries.
 *
 * Returns [code => name] map.
 */
function batchFetchParents(PDO $db, array $villageCodes): array
{
    if (empty($villageCodes)) return [];

    // Collect all unique parent codes needed
    $needed = [];
    foreach ($villageCodes as $vc) {
        $needed[substr($vc, 0, 2)]  = true; // province
        $needed[substr($vc, 0, 5)]  = true; // regency
        $needed[substr($vc, 0, 8)]  = true; // district
    }
    $codes = array_keys($needed);

    // Province + regency from wilayah_level_1_2 (len 2 or 5)
    $level12 = array_filter($codes, fn($c) => in_array(strlen($c), [2, 5]));
    // District from wilayah (len 8)
    $level3  = array_filter($codes, fn($c) => strlen($c) === 8);

    $map = [];

    if (!empty($level12)) {
        $ph   = implode(',', array_fill(0, count($level12), '?'));
        $stmt = $db->prepare("SELECT kode, nama FROM wilayah_level_1_2 WHERE kode IN ({$ph})");
        $stmt->execute(array_values($level12));
        foreach ($stmt->fetchAll() as $r) $map[$r->kode] = $r->nama;
    }

    if (!empty($level3)) {
        $ph   = implode(',', array_fill(0, count($level3), '?'));
        $stmt = $db->prepare("SELECT kode, nama FROM wilayah WHERE kode IN ({$ph})");
        $stmt->execute(array_values($level3));
        foreach ($stmt->fetchAll() as $r) $map[$r->kode] = $r->nama;
    }

    return $map;
}

function pointInPolygonBatch(float $lat, float $lng, array $coords): bool
{
    if (empty($coords) || !isset($coords[0])) return false;
    $isMulti = is_array($coords[0][0][0] ?? null);
    if ($isMulti) {
        foreach ($coords as $polygon) {
            if (!empty($polygon[0]) && raycast($lat, $lng, $polygon[0])) return true;
        }
        return false;
    }
    if (!isset($coords[0][0])) return false;
    return raycast($lat, $lng, $coords[0]);
}

function raycast(float $lat, float $lng, array $ring): bool
{
    $n = count($ring);
    if ($n < 3) return false;
    $inside = false;
    $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $iLat = (float)($ring[$i][0] ?? 0);
        $iLng = (float)($ring[$i][1] ?? 0);
        $jLat = (float)($ring[$j][0] ?? 0);
        $jLng = (float)($ring[$j][1] ?? 0);
        if (($iLat > $lat) !== ($jLat > $lat)) {
            $x = ($jLng - $iLng) * ($lat - $iLat) / ($jLat - $iLat) + $iLng;
            if ($lng < $x) $inside = !$inside;
        }
        $j = $i;
    }
    return $inside;
}
