<?php
/**
 * Reverse Geocoding Handler — GET /api/reverse
 *
 * Returns the full administrative address (village → district → regency → province)
 * for a given coordinate point.
 *
 * Performance strategy (tiered):
 *   1. ST_Contains()  — MySQL native spatial query via SPATIAL INDEX (fastest, ~5–15ms)
 *   2. Bounding-box   — composite index (lat,lng) + PHP ray-casting (fallback, ~40–80ms)
 *   3. Nearest-centroid — last resort for ocean/gap coordinates
 *
 * Query params:
 *   lat    float  (required) Latitude   e.g. -6.2088
 *   lng    float  (required) Longitude  e.g. 106.8456
 */

// ── Validate input ────────────────────────────────────────────────────────────

$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float) $_GET['lng'] : null;

if ($lat === null || $lng === null) {
    jsonError('Parameters "lat" and "lng" are required. Example: /api/reverse?lat=-6.2088&lng=106.8456', 400);
}

// Rough bounding box of Indonesian territory
if ($lat < -11.5 || $lat > 6.5 || $lng < 94.0 || $lng > 141.5) {
    jsonError('Coordinates are outside Indonesian territory (lat: -11.5 to 6.5, lng: 94.0 to 141.5).', 400);
}

// ── Main logic ────────────────────────────────────────────────────────────────

try {
    $db = DatabaseConfig::getInstance()->getConnection();

    // Check whether the spatial column + index is ready
    $hasSpatial = hasSpatialIndex($db);

    if ($hasSpatial) {
        $village = findViaSpatial($db, $lat, $lng);
    } else {
        $village = null;
    }

    // Fallback: bounding-box + PHP ray-casting
    if (!$village) {
        $village = findViaBoundingBox($db, $lat, $lng, 0.5);
    }

    // Fallback 2: wider bounding-box (large remote villages in Papua / Kalimantan)
    if (!$village) {
        $village = findViaBoundingBox($db, $lat, $lng, 1.5);
    }

    if (!$village) {
        jsonError('No region found for the given coordinates. The point may be in the ocean or a data gap.', 404);
    }

    $villageCode  = $village->kode;
    $districtCode = substr($villageCode, 0, 8);
    $regencyCode  = substr($villageCode, 0, 5);
    $provinceCode = substr($villageCode, 0, 2);

    // ── Single query for all parent names ────────────────────────────────────
    $parentStmt = $db->prepare(
        "SELECT kode, nama, 'level12' AS src FROM wilayah_level_1_2
          WHERE kode = :prov OR kode = :reg
         UNION ALL
         SELECT kode, nama, 'wilayah' AS src FROM wilayah
          WHERE kode = :dist
         LIMIT 3"
    );
    $parentStmt->bindValue(':prov', $provinceCode);
    $parentStmt->bindValue(':reg',  $regencyCode);
    $parentStmt->bindValue(':dist', $districtCode);
    $parentStmt->execute();

    $lookup = [];
    foreach ($parentStmt->fetchAll() as $r) {
        $lookup[$r->kode] = $r->nama;
    }

    // ── Build response ────────────────────────────────────────────────────────
    $provinceName = $lookup[$provinceCode] ?? null;
    $regencyName  = $lookup[$regencyCode]  ?? null;
    $districtName = $lookup[$districtCode] ?? null;
    $villageName  = $village->nama;

    $fullAddress = implode(', ', array_filter([
        $villageName,
        $districtName,
        $regencyName,
        $provinceName,
    ]));

    jsonSuccess([
        'coordinates' => [
            'latitude'  => $lat,
            'longitude' => $lng,
        ],
        'province' => [
            'code' => formatCode($provinceCode),
            'name' => $provinceName,
        ],
        'regency' => [
            'code' => formatCode($regencyCode),
            'name' => $regencyName,
        ],
        'district' => [
            'code' => formatCode($districtCode),
            'name' => $districtName,
        ],
        'village' => [
            'code' => formatCode($villageCode),
            'name' => $villageName,
        ],
        'full_address' => $fullAddress,
        'match_type'   => $village->_match_type ?? 'polygon',
    ]);

} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}

// ═══════════════════════════════════════════════════════════════════════════
// Strategy 1 — ST_Contains via SPATIAL INDEX (fastest)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Check once per request whether the geom column + spatial index exist.
 * Result is cached in a static variable to avoid repeated INFORMATION_SCHEMA hits.
 */
function hasSpatialIndex(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $count = (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'wilayah_boundaries'
           AND INDEX_NAME   = 'idx_geom'"
    )->fetchColumn();

    $cache = ($count > 0);
    return $cache;
}

/**
 * Use MySQL's native ST_Contains() with the SPATIAL INDEX for sub-20ms lookups.
 *
 * The POINT is expressed as POINT(lng lat) with SRID 0 (Cartesian) to match
 * how geometries were stored during the migrate_geometry.php migration.
 */
function findViaSpatial(PDO $db, float $lat, float $lng): ?object
{
    // WKT point: x=lng, y=lat (SRID 0 Cartesian, matches migration script)
    $pointWkt = sprintf('POINT(%s %s)', $lng, $lat);

    $stmt = $db->prepare(
        "SELECT kode, nama, lat, lng
         FROM wilayah_boundaries
         WHERE CHAR_LENGTH(kode) = 13
           AND ST_Contains(geom, ST_GeomFromText(:pt, 0))
         ORDER BY (POW(lat - :lat, 2) + POW(lng - :lng, 2))
         LIMIT 1"
    );
    $stmt->bindValue(':pt',  $pointWkt);
    $stmt->bindValue(':lat', $lat);
    $stmt->bindValue(':lng', $lng);
    $stmt->execute();

    $row = $stmt->fetch();
    if (!$row) return null;

    $row->_match_type = 'spatial';
    return $row;
}

// ═══════════════════════════════════════════════════════════════════════════
// Strategy 2 — Bounding-box + PHP Ray-casting (fallback)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Bounding-box pre-filter using composite (lat,lng) index, then PHP ray-casting
 * for precision. Also returns the nearest-centroid result when no polygon matches.
 */
function findViaBoundingBox(PDO $db, float $lat, float $lng, float $delta): ?object
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

    // Exact polygon match via ray-casting
    foreach ($candidates as $row) {
        $coords = json_decode($row->path, true);
        if (!is_array($coords)) continue;

        if (pointInPolygonAny($lat, $lng, $coords)) {
            $row->_match_type = 'polygon';
            return $row;
        }
    }

    // Nearest-centroid fallback (only on the tighter pass to avoid false results)
    if ($delta <= 0.5) {
        $nearest = $candidates[0]; // already ordered by distance
        $nearest->_match_type = 'nearest_centroid';
        return $nearest;
    }

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// Geometry helpers (used by Strategy 2 fallback only)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Check whether a point lies inside any polygon/multipolygon.
 *
 * DB path format:
 *   Single polygon : [ ring, ring, ... ]        ring = [[lat,lng], ...]
 *   Multi  polygon : [ [ring,...], [ring,...] ]
 *
 * Depth heuristic:
 *   coords[0][0][0] is number → single polygon
 *   coords[0][0][0] is array  → multi  polygon
 */
function pointInPolygonAny(float $lat, float $lng, array $coords): bool
{
    if (empty($coords) || !isset($coords[0])) return false;

    $isMulti = is_array($coords[0][0][0] ?? null);

    if ($isMulti) {
        foreach ($coords as $polygon) {
            if (!empty($polygon[0]) && pointInRing($lat, $lng, $polygon[0])) {
                return true;
            }
        }
        return false;
    }

    if (!isset($coords[0][0])) return false;
    return pointInRing($lat, $lng, $coords[0]);
}

/**
 * Ray Casting algorithm — point (lat, lng) vs polygon ring [[lat,lng], ...].
 */
function pointInRing(float $lat, float $lng, array $ring): bool
{
    $n = count($ring);
    if ($n < 3) return false;

    $inside = false;
    $j      = $n - 1;

    for ($i = 0; $i < $n; $i++) {
        $iLat = (float) ($ring[$i][0] ?? 0);
        $iLng = (float) ($ring[$i][1] ?? 0);
        $jLat = (float) ($ring[$j][0] ?? 0);
        $jLng = (float) ($ring[$j][1] ?? 0);

        if (($iLat > $lat) !== ($jLat > $lat)) {
            $xIntersect = ($jLng - $iLng) * ($lat - $iLat) / ($jLat - $iLat) + $iLng;
            if ($lng < $xIntersect) {
                $inside = !$inside;
            }
        }
        $j = $i;
    }

    return $inside;
}
