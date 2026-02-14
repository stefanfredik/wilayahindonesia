<?php
/**
 * Regions Handler — GET /api/regions
 *
 * Generic endpoint supporting any level.
 *
 * Query params:
 *   level       int     1-4 (province to village)
 *   parent      string  parent region code
 *   boundaries  bool    include polygon data (default false)
 *   limit       int     max 1000, default 100
 *   offset      int     default 0
 */

try {
    $db     = DatabaseConfig::getInstance()->getConnection();
    $limit  = intParam('limit', 100, 1000);
    $offset = intParam('offset', 0);
    $level  = isset($_GET['level'])  ? (int) $_GET['level'] : null;
    $parent = isset($_GET['parent']) ? trim($_GET['parent']) : null;

    $where  = [];
    $params = [];

    if ($level !== null) {
        $charLen = levelToCharLength($level);
        if ($charLen === 0) {
            jsonError('Invalid level. Use 1-4 for provinces, regencies, districts, villages.');
        }
        $where[] = "CHAR_LENGTH(w.kode) = {$charLen}";
    }

    if ($parent !== null) {
        if (!preg_match('/^[\d\.]+$/', $parent)) {
            jsonError('Invalid parent code format.');
        }
        $clean = normalizeCode($parent);
        $pLen  = strlen($clean);
        $where[] = "LEFT(w.kode, {$pLen}) = :parent AND CHAR_LENGTH(w.kode) > {$pLen}";
        $params[':parent'] = $clean;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql  = "SELECT w.kode, w.nama, l.luas, p.total
             FROM wilayah w
             LEFT JOIN wilayah_luas l ON l.kode = w.kode
             LEFT JOIN wilayah_penduduk p ON p.kode = w.kode
             {$whereClause}
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

    $cntSql  = "SELECT COUNT(*) FROM wilayah w {$whereClause}";
    $cntStmt = $db->prepare($cntSql);
    foreach ($params as $k => $v) $cntStmt->bindValue($k, $v);
    $cntStmt->execute();
    $total = (int) $cntStmt->fetchColumn();

    jsonSuccess($data, [
        'total'      => $total,
        'count'      => count($data),
        'limit'      => $limit,
        'offset'     => $offset,
        'level'      => $level,
        'parent'     => $parent,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}