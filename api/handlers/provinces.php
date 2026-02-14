<?php
/**
 * Provinces Handler — GET /api/provinces
 *
 * Query params:
 *   boundaries  bool   include polygon data (default false)
 *   limit       int    max 1000, default 100
 *   offset      int    default 0
 */

try {
    $db     = DatabaseConfig::getInstance()->getConnection();
    $withB  = wantsBoundaries();
    $limit  = intParam('limit', 100, 1000);
    $offset = intParam('offset', 0);

    $cols  = selectColumns($withB);
    $sql   = "SELECT {$cols} FROM wilayah_level_1_2
              WHERE CHAR_LENGTH(kode) = 2
              ORDER BY kode
              LIMIT :lim OFFSET :off";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[] = formatRegion($row, $withB);
    }

    // total count
    $total = (int) $db->query("SELECT COUNT(*) FROM wilayah_level_1_2 WHERE CHAR_LENGTH(kode) = 2")->fetchColumn();

    jsonSuccess($data, [
        'total'      => $total,
        'count'      => count($data),
        'limit'      => $limit,
        'offset'     => $offset,
        'boundaries' => $withB,
    ]);
} catch (Exception $e) {
    jsonError($e->getMessage(), 500);
}