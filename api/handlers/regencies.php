<?php
/**
 * Regencies Handler — GET /api/regencies
 *
 * Query params:
 *   province    string  2-digit province code
 *   boundaries  bool    include polygon data (default false)
 *   limit       int     max 1000, default 100
 *   offset      int     default 0
 */

try {
    $db     = DatabaseConfig::getInstance()->getConnection();
    $withB  = wantsBoundaries();
    $limit  = intParam('limit', 100, 1000);
    $offset = intParam('offset', 0);
    $prov   = isset($_GET['province']) ? trim($_GET['province']) : null;

    $cols   = selectColumns($withB);
    $where  = 'CHAR_LENGTH(kode) = 5';
    $params = [];

    if ($prov !== null) {
        if (!preg_match('/^\d{2}$/', $prov)) {
            jsonError('Invalid province code. Must be 2 digits.');
        }
        $where .= ' AND LEFT(kode, 2) = :prov';
        $params[':prov'] = $prov;
    }

    $sql  = "SELECT {$cols} FROM wilayah_level_1_2 WHERE {$where} ORDER BY kode LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[] = formatRegion($row, $withB);
    }

    // total count
    $cntSql  = "SELECT COUNT(*) FROM wilayah_level_1_2 WHERE {$where}";
    $cntStmt = $db->prepare($cntSql);
    foreach ($params as $k => $v) $cntStmt->bindValue($k, $v);
    $cntStmt->execute();
    $total = (int) $cntStmt->fetchColumn();

    jsonSuccess($data, [
        'total'      => $total,
        'count'      => count($data),
        'limit'      => $limit,
        'offset'     => $offset,
        'province'   => $prov,
        'boundaries' => $withB,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}