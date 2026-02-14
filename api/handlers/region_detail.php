<?php
/**
 * Region Detail Handler — GET /api/{code}
 *
 * Returns full detail for a single region including boundaries (levels 1-2)
 * and child-region list.
 */

$code = isset($_GET['_code']) ? $_GET['_code'] : '';

try {
    $db    = DatabaseConfig::getInstance()->getConnection();
    $clean = normalizeCode($code);

    if (!preg_match('/^[\d.]+$/', $clean)) {
        jsonError('Invalid region code format.', 400);
    }

    $codeLen = strlen($clean);
    $level   = codeLevel($clean);

    if ($level >= 1 && $level <= 2) {
        // Levels 1-2: full data from wilayah_level_1_2 (includes boundaries)
        $stmt = $db->prepare(
            "SELECT kode, nama, ibukota, lat, lng, elv, tz, luas, penduduk, path, status
             FROM wilayah_level_1_2
             WHERE kode = :code
             LIMIT 1"
        );
        $stmt->bindValue(':code', $clean);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Region not found.', 404);
        }

        $region = formatRegion($row, true);
    } else {
        // Levels 3-4: basic data from wilayah + luas + penduduk
        $stmt = $db->prepare(
            "SELECT w.kode, w.nama, l.luas, p.total, b.lat, b.lng, b.path
             FROM wilayah w
             LEFT JOIN wilayah_luas l ON l.kode = w.kode
             LEFT JOIN wilayah_penduduk p ON p.kode = w.kode
             LEFT JOIN wilayah_boundaries b ON b.kode = w.kode
             WHERE w.kode = :code
             LIMIT 1"
        );
        $stmt->bindValue(':code', $clean);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            jsonError('Region not found.', 404);
        }

        $region = formatRegionBasic($row);
    }

    // Fetch direct children (next level)
    $childLens = [2 => 5, 5 => 8, 8 => 13]; // province→regency→district→village
    $children  = [];
    $withChildBoundaries = wantsBoundaries();

    if (isset($childLens[$codeLen])) {
        $childLen = $childLens[$codeLen];

        if ($childLen <= 5) {
            // Children are provinces or regencies — use wilayah_level_1_2
            $cStmt = $db->prepare(
                "SELECT kode, nama, ibukota, lat, lng, elv, tz, luas, penduduk, status
                 FROM wilayah_level_1_2
                 WHERE LEFT(kode, :plen) = :parent
                   AND CHAR_LENGTH(kode) = :clen
                 ORDER BY kode"
            );
        } else {
            // Children are districts or villages — use wilayah + JOINs + boundaries
            $pathCol = $withChildBoundaries ? ', b.path' : '';
            $cStmt = $db->prepare(
                "SELECT w.kode, w.nama, l.luas, p.total, b.lat, b.lng{$pathCol}
                 FROM wilayah w
                 LEFT JOIN wilayah_luas l ON l.kode = w.kode
                 LEFT JOIN wilayah_penduduk p ON p.kode = w.kode
                 LEFT JOIN wilayah_boundaries b ON b.kode = w.kode
                 WHERE LEFT(w.kode, :plen) = :parent
                   AND CHAR_LENGTH(w.kode) = :clen
                 ORDER BY w.kode"
            );
        }
        $cStmt->bindValue(':plen', $codeLen, PDO::PARAM_INT);
        $cStmt->bindValue(':parent', $clean);
        $cStmt->bindValue(':clen', $childLen, PDO::PARAM_INT);
        $cStmt->execute();

        foreach ($cStmt->fetchAll() as $child) {
            if ($childLen <= 5) {
                $children[] = formatRegion($child, false);
            } else {
                $children[] = formatRegionBasic($child);
            }
        }
    }

    $region['children'] = $children;

    jsonSuccess($region);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}