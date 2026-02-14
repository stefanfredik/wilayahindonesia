<?php
/**
 * Boundaries Handler — GET /api/boundaries/{code}
 *
 * Returns the boundary polygon for a given region code.
 * Supports ALL levels: province, regency, district, village.
 *
 * Data source: wilayah_boundaries table (90k+ records)
 * Fallback: wilayah_level_1_2 for levels 1-2
 *
 * Optional query params:
 *   format    string  "geojson" to wrap output as a GeoJSON Feature (default: raw)
 *   children  bool    "1" to include child boundaries as a FeatureCollection
 */

$code = isset($_GET['_code']) ? $_GET['_code'] : '';

try {
    $db    = DatabaseConfig::getInstance()->getConnection();
    $clean = normalizeCode($code);

    if (!preg_match('/^[\d.]+$/', $clean)) {
        jsonError('Invalid region code format.', 400);
    }

    // Try wilayah_boundaries first (covers all levels: prov, kab, kec, kel/desa)
    $stmt = $db->prepare(
        "SELECT kode, nama, lat, lng, path
         FROM wilayah_boundaries
         WHERE kode = :code
         LIMIT 1"
    );
    $stmt->bindValue(':code', $clean);
    $stmt->execute();
    $row = $stmt->fetch();

    // Fallback to wilayah_level_1_2 for levels 1-2 if not found
    if (!$row) {
        $stmt2 = $db->prepare(
            "SELECT kode, nama, lat, lng, path
             FROM wilayah_level_1_2
             WHERE kode = :code
             LIMIT 1"
        );
        $stmt2->bindValue(':code', $clean);
        $stmt2->execute();
        $row = $stmt2->fetch();
    }

    if (!$row) {
        jsonError('Region not found.', 404);
    }

    // --- Children mode: return child boundaries as GeoJSON FeatureCollection ---
    $isChildren = isset($_GET['_children']) && $_GET['_children'] === '1';
    if ($isChildren) {
        $childLens = [2 => 5, 5 => 8, 8 => 13];
        $codeLen   = strlen($clean);
        if (!isset($childLens[$codeLen])) {
            jsonError('No child level for this region (village is the lowest level).', 400);
        }
        $childLen = $childLens[$codeLen];

        $cStmt = $db->prepare(
            "SELECT kode, nama, lat, lng, path
             FROM wilayah_boundaries
             WHERE LEFT(kode, :plen) = :parent
               AND CHAR_LENGTH(kode) = :clen
             ORDER BY kode"
        );
        $cStmt->bindValue(':plen', $codeLen, PDO::PARAM_INT);
        $cStmt->bindValue(':parent', $clean);
        $cStmt->bindValue(':clen', $childLen, PDO::PARAM_INT);
        $cStmt->execute();

        $features = [];
        foreach ($cStmt->fetchAll() as $child) {
            if (empty($child->path)) continue;
            $cCoords = json_decode($child->path, true);
            if (!$cCoords) continue;
            $geoCoords = convertToGeoJSON($cCoords);
            $isMulti   = is_array($cCoords[0][0][0] ?? null);

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'code' => formatCode($child->kode),
                    'name' => $child->nama,
                    'center' => [
                        'latitude'  => (float) $child->lat,
                        'longitude' => (float) $child->lng,
                    ],
                ],
                'geometry' => [
                    'type'        => $isMulti ? 'MultiPolygon' : 'Polygon',
                    'coordinates' => $geoCoords,
                ],
            ];
        }

        $collection = [
            'type' => 'FeatureCollection',
            'properties' => [
                'parent_code' => formatCode($row->kode),
                'parent_name' => $row->nama,
                'count'       => count($features),
            ],
            'features' => $features,
        ];

        echo json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    // --- Single boundary mode ---
    if (empty($row->path)) {
        jsonError('No boundary data available for this region.', 404);
    }

    $coords = json_decode($row->path, true);

    $format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'raw';

    if ($format === 'geojson') {
        // Return as GeoJSON Feature with Polygon/MultiPolygon
        // Convert [lat,lng] pairs to [lng,lat] for GeoJSON spec
        $geoCoords = convertToGeoJSON($coords);
        $isMulti   = is_array($coords[0][0][0] ?? null);

        $feature = [
            'type' => 'Feature',
            'properties' => [
                'code' => formatCode($row->kode),
                'name' => $row->nama,
            ],
            'geometry' => [
                'type'        => $isMulti ? 'MultiPolygon' : 'Polygon',
                'coordinates' => $geoCoords,
            ],
        ];

        echo json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    // Raw format
    jsonSuccess([
        'code'       => formatCode($row->kode),
        'name'       => $row->nama,
        'center'     => [
            'latitude'  => (float) $row->lat,
            'longitude' => (float) $row->lng,
        ],
        'boundaries' => $coords,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */

/**
 * Convert the database coordinate arrays from [lat,lng] to
 * GeoJSON-compliant [lng,lat] and ensure rings are closed.
 */
function convertToGeoJSON(array $coords): array
{
    // Detect Multi-polygon vs single polygon
    // Single polygon:  [ [ring], [ring], ... ]   where ring = [[lat,lng],...]
    // Multi polygon:   [ [[ring],[ring]], ... ]

    if (!isset($coords[0][0])) {
        return $coords; // malformed, return as-is
    }

    // Check depth: if coords[0][0][0] is an array, it's multi-polygon
    $isMulti = is_array($coords[0][0][0] ?? null);

    if ($isMulti) {
        $result = [];
        foreach ($coords as $polygon) {
            $rings = [];
            foreach ($polygon as $ring) {
                $rings[] = swapAndClose($ring);
            }
            $result[] = $rings;
        }
        return $result;
    }

    // Single polygon – each element is a ring
    // But could also be single ring directly
    if (is_array($coords[0][0] ?? null) && !is_array($coords[0][0][0] ?? null)) {
        // coords is a list of rings
        $rings = [];
        foreach ($coords as $ring) {
            $rings[] = swapAndClose($ring);
        }
        return $rings;
    }

    // Single ring
    return [swapAndClose($coords)];
}

function swapAndClose(array $ring): array
{
    $out = [];
    foreach ($ring as $pt) {
        if (is_array($pt) && count($pt) >= 2) {
            $out[] = [(float) $pt[1], (float) $pt[0]]; // [lng, lat]
        }
    }
    // Close ring if not closed
    if (!empty($out) && ($out[0] !== end($out))) {
        $out[] = $out[0];
    }
    return $out;
}
