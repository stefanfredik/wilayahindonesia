<?php
/**
 * Migration: Populate wilayah_boundaries.geom from JSON path
 *
 * Converts the stored [lat,lng] JSON coordinate arrays into native MySQL
 * GEOMETRY (POLYGON / MULTIPOLYGON) objects stored in a new `geom` column.
 *
 * A SPATIAL INDEX on that column lets MySQL do native ST_Contains() lookups
 * which are dramatically faster than PHP ray-casting on 90k+ rows.
 *
 * Usage (inside wilayah-app container):
 *   php /var/www/html/db/migrate_geometry.php
 *
 * Or from host:
 *   docker exec wilayah-app php /var/www/html/db/migrate_geometry.php
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../api/config/database.php';

$db = DatabaseConfig::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Wilayah Geometry Migration ===\n";
echo "MySQL: " . $db->query('SELECT VERSION()')->fetchColumn() . "\n\n";

// ── Step 1: Add geom column (nullable first, spatial index needs NOT NULL) ───
echo "[1/4] Adding geom column...\n";
$cols = $db->query("SHOW COLUMNS FROM wilayah_boundaries LIKE 'geom'")->fetchAll();
if (empty($cols)) {
    $db->exec("ALTER TABLE wilayah_boundaries ADD COLUMN geom GEOMETRY NULL SRID 0");
    echo "      Column added.\n";
} else {
    echo "      Column already exists, skipping.\n";
}

// ── Step 2: Drop old single-column indexes, add composite ───────────────────
echo "[2/4] Updating indexes...\n";
$indexes = $db->query(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wilayah_boundaries'
       AND INDEX_NAME IN ('idx_lat','idx_lng','idx_lat_lng')"
)->fetchAll(PDO::FETCH_COLUMN);

$alterParts = [];
if (in_array('idx_lat', $indexes))     $alterParts[] = "DROP INDEX idx_lat";
if (in_array('idx_lng', $indexes))     $alterParts[] = "DROP INDEX idx_lng";
if (!in_array('idx_lat_lng', $indexes)) $alterParts[] = "ADD INDEX idx_lat_lng (lat, lng)";

if (!empty($alterParts)) {
    $db->exec("ALTER TABLE wilayah_boundaries " . implode(', ', $alterParts));
    echo "      Indexes updated.\n";
} else {
    echo "      Indexes already optimal, skipping.\n";
}

// ── Step 3: Populate geom from path (batch processing) ──────────────────────
echo "[3/4] Populating geometry column from path JSON...\n";

$total   = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE path IS NOT NULL AND path != ''")->fetchColumn();
$batch   = 500;
$offset  = 0;
$done    = 0;
$errors  = 0;

$selectStmt = $db->prepare(
    "SELECT kode, lat, lng, path
     FROM wilayah_boundaries
     WHERE path IS NOT NULL AND path != ''
     LIMIT :limit OFFSET :offset"
);

$updateStmt = $db->prepare(
    "UPDATE wilayah_boundaries
     SET geom = ST_GeomFromText(:wkt, 0)
     WHERE kode = :kode"
);

$fallbackStmt = $db->prepare(
    "UPDATE wilayah_boundaries
     SET geom = ST_GeomFromText(:wkt, 0)
     WHERE kode = :kode"
);

$startTime = microtime(true);

while ($offset < $total) {
    $selectStmt->bindValue(':limit',  $batch,  PDO::PARAM_INT);
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->execute();
    $rows = $selectStmt->fetchAll(PDO::FETCH_OBJ);

    if (empty($rows)) break;

    $db->beginTransaction();
    foreach ($rows as $row) {
        $wkt = null;

        $coords = json_decode($row->path, true);
        if (is_array($coords)) {
            $wkt = coordsToWKT($coords);
        }

        if (!$wkt) {
            // Fallback: use centroid point (won't match ST_Contains, but avoids NULL)
            $wkt = sprintf('POINT(%s %s)', (float)$row->lng, (float)$row->lat);
            $errors++;
        }

        try {
            $updateStmt->bindValue(':wkt',  $wkt);
            $updateStmt->bindValue(':kode', $row->kode);
            $updateStmt->execute();
        } catch (Exception $e) {
            // WKT might be invalid (self-intersecting ring) — use point fallback
            $ptWkt = sprintf('POINT(%s %s)', (float)$row->lng, (float)$row->lat);
            $fallbackStmt->bindValue(':wkt',  $ptWkt);
            $fallbackStmt->bindValue(':kode', $row->kode);
            $fallbackStmt->execute();
            $errors++;
        }

        $done++;
    }
    $db->commit();

    $offset += $batch;
    $pct     = round($done / $total * 100, 1);
    $elapsed = round(microtime(true) - $startTime, 1);
    $rate    = $done > 0 ? round($done / $elapsed) : 0;
    $eta     = $rate > 0 ? round(($total - $done) / $rate) : '?';

    echo sprintf(
        "      %6d / %d  (%5.1f%%)  %s rows/s  ETA: %ss\n",
        $done, $total, $pct, $rate, $eta
    );
}

$totalTime = round(microtime(true) - $startTime, 1);
echo "\n      Done: {$done} rows in {$totalTime}s. Fallback-to-point: {$errors}\n\n";

// ── Step 4: Add SPATIAL INDEX ────────────────────────────────────────────────
echo "[4/4] Adding SPATIAL INDEX...\n";

// Spatial index requires NOT NULL — set any remaining NULLs to empty geometry
$nullCount = (int) $db->query("SELECT COUNT(*) FROM wilayah_boundaries WHERE geom IS NULL")->fetchColumn();
if ($nullCount > 0) {
    echo "      Setting {$nullCount} NULL rows to fallback POINT(0 0)...\n";
    $db->exec("UPDATE wilayah_boundaries SET geom = ST_GeomFromText('POINT(0 0)', 0) WHERE geom IS NULL");
}

// Make column NOT NULL now that all rows are populated
$db->exec("ALTER TABLE wilayah_boundaries MODIFY COLUMN geom GEOMETRY NOT NULL SRID 0");

// Check if spatial index already exists
$spIdx = $db->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'wilayah_boundaries'
       AND INDEX_NAME = 'idx_geom'"
)->fetchColumn();

if ($spIdx == 0) {
    echo "      Building spatial index (this may take 30–60s)...\n";
    $t = microtime(true);
    $db->exec("ALTER TABLE wilayah_boundaries ADD SPATIAL INDEX idx_geom (geom)");
    echo sprintf("      Spatial index built in %.1fs\n", microtime(true) - $t);
} else {
    echo "      Spatial index already exists, skipping.\n";
}

echo "\n=== Migration complete! ===\n";
echo "Summary:\n";
echo "  - Composite index idx_lat_lng (lat, lng)   ✓\n";
echo "  - GEOMETRY column populated ({$done} rows)  ✓\n";
echo "  - SPATIAL INDEX idx_geom                   ✓\n";
echo "\nRestart or reload the API server to use the new optimized handler.\n";

// ═══════════════════════════════════════════════════════════════════════════
// Conversion helpers
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Convert DB coordinate array to WKT POLYGON / MULTIPOLYGON.
 *
 * DB format  (path column):
 *   Single polygon : [ ring, ring, ... ]       ring = [[lat,lng], ...]
 *   Multi  polygon : [ [ring,...], [ring,...] ]
 *
 * WKT format (SRID 0, x=lng y=lat):
 *   POLYGON((lng lat, lng lat, ...))
 *   MULTIPOLYGON(((lng lat, ...)), ((lng lat, ...)))
 */
function coordsToWKT(array $coords): ?string
{
    if (empty($coords) || !isset($coords[0])) return null;

    // Depth check: single vs multi
    // single → coords[0][0][0] is number
    // multi  → coords[0][0][0] is array
    $isMulti = is_array($coords[0][0][0] ?? null);

    if ($isMulti) {
        $polygons = [];
        foreach ($coords as $polygon) {
            if (empty($polygon[0])) continue;
            $ring = ringToWKT($polygon[0]);
            if ($ring !== null) {
                $polygons[] = "(({$ring}))";
            }
        }
        if (empty($polygons)) return null;
        return 'MULTIPOLYGON(' . implode(',', $polygons) . ')';
    }

    // Single polygon — outer ring is coords[0]
    if (!isset($coords[0][0])) return null;
    $ring = ringToWKT($coords[0]);
    if ($ring === null) return null;
    return "POLYGON(({$ring}))";
}

/**
 * Convert a ring array [[lat,lng], ...] to WKT coordinate list "lng lat, ...".
 * Ensures ring is closed (last point = first point).
 * Returns null if the ring has fewer than 3 distinct points.
 */
function ringToWKT(array $ring): ?string
{
    if (count($ring) < 3) return null;

    $points = [];
    foreach ($ring as $pt) {
        if (!is_array($pt) || count($pt) < 2) continue;
        $lat = (float) $pt[0];
        $lng = (float) $pt[1];
        $points[] = "{$lng} {$lat}";
    }

    if (count($points) < 3) return null;

    // Close ring if needed
    if ($points[0] !== $points[count($points) - 1]) {
        $points[] = $points[0];
    }

    return implode(',', $points);
}
