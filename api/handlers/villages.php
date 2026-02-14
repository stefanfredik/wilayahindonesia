<?php
/**
 * Villages Handler — GET /api/villages
 *
 * Query params:
 *   district    string  District code (XX.XX.XX or 6 digits)
 *   regency     string  Regency code (XX.XX or 4 digits)
 *   province    string  Province code (2 digits)
 *   limit       int     max 1000, default 100
 *   offset      int     default 0
 *
 * Note: Village data comes from `wilayah` table (wilayah_level_1_2 only has levels 1-2).
 */

try {
    $db     = DatabaseConfig::getInstance()->getConnection();
    $limit  = intParam('limit', 100, 1000);
    $offset = intParam('offset', 0);
    $dist   = isset($_GET['district']) ? trim($_GET['district']) : null;
    $reg    = isset($_GET['regency'])  ? trim($_GET['regency'])  : null;
    $prov   = isset($_GET['province']) ? trim($_GET['province']) : null;

    $where  = 'CHAR_LENGTH(w.kode) = 13';
    $params = [];

    if ($dist !== null) {
        $clean = normalizeCode($dist);
        if (strlen($clean) !== 8) {
            jsonError('Invalid district code. Use format XX.XX.XX or 6 digits.', 400);
        }
        $where .= ' AND LEFT(w.kode, 8) = :dist';
        $params[':dist'] = $clean;
    } elseif ($reg !== null) {
        $clean = normalizeCode($reg);
        if (strlen($clean) !== 5) {
            jsonError('Invalid regency code. Use format XX.XX or 4 digits.', 400);
        }
        $where .= ' AND LEFT(w.kode, 5) = :reg';
        $params[':reg'] = $clean;
    } elseif ($prov !== null) {
        if (!preg_match('/^\d{2}$/', $prov)) {
            jsonError('Invalid province code. Must be 2 digits.', 400);
        }
        $where .= ' AND LEFT(w.kode, 2) = :prov';
        $params[':prov'] = $prov;
    }

    $sql  = "SELECT w.kode, w.nama, l.luas, p.total, b.lat, b.lng
             FROM wilayah w
             LEFT JOIN wilayah_luas l ON l.kode = w.kode
             LEFT JOIN wilayah_penduduk p ON p.kode = w.kode
             LEFT JOIN wilayah_boundaries b ON b.kode = w.kode
             WHERE {$where}
             ORDER BY w.kode
             LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[] = formatRegionBasic($row);
    }

    $cntSql  = "SELECT COUNT(*) FROM wilayah w WHERE {$where}";
    $cntStmt = $db->prepare($cntSql);
    foreach ($params as $k => $v) $cntStmt->bindValue($k, $v);
    $cntStmt->execute();
    $total = (int) $cntStmt->fetchColumn();

    jsonSuccess($data, [
        'total'    => $total,
        'count'    => count($data),
        'limit'    => $limit,
        'offset'   => $offset,
        'district' => $dist,
        'regency'  => $reg,
        'province' => $prov,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}