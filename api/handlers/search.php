<?php
/**
 * Search Handler — GET /api/search
 *
 * Query params:
 *   q           string  (required) name or code keyword
 *   level       int     1-4 filter
 *   boundaries  bool    include polygon data (default false)
 *   limit       int     max 100, default 20
 */

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '') {
    jsonError('Search query parameter "q" is required.');
}

try {
    $db     = DatabaseConfig::getInstance()->getConnection();
    $limit  = intParam('limit', 20, 100);
    $level  = isset($_GET['level']) ? (int) $_GET['level'] : null;

    $cols   = 'w.kode, w.nama';
    $where  = ['w.nama LIKE :q'];
    $params = [':q' => '%' . $q . '%'];

    if ($level !== null) {
        $charLen = levelToCharLength($level);
        if ($charLen === 0) {
            jsonError('Invalid level. Use 1-4.');
        }
        $where[] = "CHAR_LENGTH(w.kode) = {$charLen}";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $sql  = "SELECT w.kode, w.nama, l.luas, p.total
             FROM wilayah w
             LEFT JOIN wilayah_luas l ON l.kode = w.kode
             LEFT JOIN wilayah_penduduk p ON p.kode = w.kode
             {$whereClause}
             ORDER BY CHAR_LENGTH(w.kode), w.nama
             LIMIT :lim";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[] = formatRegionBasic($row);
    }

    jsonSuccess($data, [
        'query'  => $q,
        'total'  => count($data),
        'limit'  => $limit,
        'level'  => $level,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}