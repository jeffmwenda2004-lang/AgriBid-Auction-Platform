<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminName = getUserName();
$escape = 'escapeHtml';

$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total_bids,
        COALESCE(SUM(CASE WHEN status = 'winning' THEN 1 ELSE 0 END), 0) AS winning_bids,
        COALESCE(SUM(CASE WHEN status = 'outbid' THEN 1 ELSE 0 END), 0) AS outbid_bids
     FROM bids"
)->fetch();

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = is_string($search) ? trim($search) : '';
$status = is_string($status) ? $status : '';
$dateFrom = is_string($dateFrom) ? $dateFrom : '';
$dateTo = is_string($dateTo) ? $dateTo : '';
$search = substr($search, 0, 100);

$validStatuses = ['active', 'winning', 'outbid', 'cancelled'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}

$isValidDate = static function (string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (!$isValidDate($dateFrom)) {
    $dateFrom = '';
}
if (!$isValidDate($dateTo)) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(buyer.name LIKE ? OR buyer.email LIKE ? OR l.animal_type LIKE ? OR l.breed LIKE ? OR CAST(a.id AS CHAR) = ?)';
    $searchLike = '%' . $search . '%';
    array_push($params, $searchLike, $searchLike, $searchLike, $searchLike, $search);
}
if ($status !== '') {
    $conditions[] = 'b.status = ?';
    $params[] = $status;
}
if ($dateFrom !== '') {
    $conditions[] = 'b.bid_time >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = 'b.bid_time < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}

$whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM bids b
     INNER JOIN auctions a ON a.id = b.auction_id
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users buyer ON buyer.id = b.buyer_id
     $whereSql"
);
$countStmt->execute($params);
$filteredBidCount = (int) $countStmt->fetchColumn();

$perPage = 25;
$totalPages = max(1, (int) ceil($filteredBidCount / $perPage));
$pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $pageInput === false || $pageInput === null ? 1 : max(1, $pageInput);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$bidsStmt = $pdo->prepare(
    "SELECT
        b.id AS bid_id,
        b.auction_id,
        b.bid_amount,
        b.bid_time,
        b.status AS bid_status,
        a.starting_price,
        a.minimum_bid_increment,
        a.status AS auction_status,
        a.farmer_id AS auction_farmer_id,
        a.end_time,
        l.farmer_id AS livestock_farmer_id,
        l.animal_type,
        l.breed,
        buyer.id AS buyer_id,
        buyer.name AS buyer_name,
        buyer.email AS buyer_email,
        farmer.name AS farmer_name,
        COALESCE((
            SELECT MAX(previous.bid_amount)
            FROM bids previous
            WHERE previous.auction_id = a.id
              AND (
                  previous.bid_time < b.bid_time
                  OR (previous.bid_time = b.bid_time AND previous.id < b.id)
              )
        ), a.starting_price) AS previous_highest_bid,
        (
            SELECT leading.id
            FROM bids leading
            WHERE leading.auction_id = a.id
              AND leading.status <> 'cancelled'
            ORDER BY leading.bid_amount DESC, leading.bid_time DESC, leading.id DESC
            LIMIT 1
        ) AS leading_bid_id
     FROM bids b
     INNER JOIN auctions a ON a.id = b.auction_id
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users buyer ON buyer.id = b.buyer_id
     INNER JOIN users farmer ON farmer.id = a.farmer_id
     $whereSql
     ORDER BY b.bid_time DESC, b.id DESC
     LIMIT $perPage OFFSET $offset"
);
$bidsStmt->execute($params);
$bids = $bidsStmt->fetchAll();

$resultsStmt = $pdo->query(
    "SELECT
        a.id AS auction_id,
        a.status AS auction_status,
        a.end_time,
        a.winning_bid,
        l.animal_type,
        l.breed,
        winner.name AS winner_name
     FROM auctions a
     INNER JOIN livestock l ON l.id = a.livestock_id
     LEFT JOIN users winner ON winner.id = a.winner_id
     WHERE a.status = 'completed'
        OR (a.status IN ('pending', 'active') AND a.end_time <= NOW())
     ORDER BY a.end_time DESC, a.id DESC
     LIMIT 10"
);
$auctionResults = $resultsStmt->fetchAll();

$queryParams = [
    'search' => $search,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];
$queryParams = array_filter($queryParams, static fn ($value): bool => $value !== '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bid Monitoring | Digital Livestock Auction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7f9; color: #263238; }
        .sidebar {
            width: 250px; min-height: 100vh; background: #12372A;
            position: fixed; inset: 0 auto 0 0; padding: 25px 15px; z-index: 1000;
        }
        .brand { color: #fff; font-size: 20px; font-weight: 700; padding: 10px 15px 30px; }
        .brand i { margin-right: 8px; }
        .sidebar a {
            display: flex; align-items: center; gap: 12px; color: #dce8e2;
            text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 5px;
        }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.12); color: #fff; }
        .sidebar .logout { margin-top: 30px; color: #ffcccc; }
        .main { margin-left: 250px; padding: 30px; }
        .topbar, .stat-card, .filter-card, .content-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
        .topbar p { margin: 5px 0 0; color: #718096; }
        .stat-card { padding: 20px; height: 100%; }
        .stat-icon {
            display: flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; border-radius: 12px;
            background: #e6f1ec; color: #12372A; font-size: 20px;
        }
        .stat-number { margin-top: 12px; font-size: 27px; font-weight: 700; }
        .stat-label { color: #718096; font-size: 13px; }
        .filter-card, .content-card { padding: 22px; }
        .table td, .table th { vertical-align: middle; }
        .monitor-flag { max-width: 260px; white-space: normal; }
        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }
        @media (max-width: 767px) {
            .sidebar { position: relative; width: 100%; min-height: auto; }
            .main { margin-left: 0; padding: 20px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <h1>Bid Monitoring</h1>
        <p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Review bidding activity and auction results.</p>
    </header>

    <section class="row g-3 mb-4" aria-label="Bid statistics">
        <div class="col-md-4">
            <article class="stat-card">
                <div class="stat-icon"><i class="bi bi-hand-index-thumb"></i></div>
                <div class="stat-number"><?= number_format((int) $stats['total_bids']) ?></div>
                <div class="stat-label">Total Bids</div>
            </article>
        </div>
        <div class="col-md-4">
            <article class="stat-card">
                <div class="stat-icon"><i class="bi bi-trophy"></i></div>
                <div class="stat-number"><?= number_format((int) $stats['winning_bids']) ?></div>
                <div class="stat-label">Bids Marked Winning</div>
            </article>
        </div>
        <div class="col-md-4">
            <article class="stat-card">
                <div class="stat-icon"><i class="bi bi-arrow-down-up"></i></div>
                <div class="stat-number"><?= number_format((int) $stats['outbid_bids']) ?></div>
                <div class="stat-label">Outbid Records</div>
            </article>
        </div>
    </section>

    <section class="filter-card mb-4">
        <h2 class="h5 fw-bold mb-3">Search and Filters</h2>
        <form method="GET" action="bids.php" class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label for="search" class="form-label">Buyer, livestock, or auction ID</label>
                <input type="search" id="search" name="search" class="form-control" maxlength="100" value="<?= $escape($search) ?>" placeholder="Search names, animal, breed, auction ID">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="status" class="form-label">Bid status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach ($validStatuses as $bidStatus): ?>
                        <option value="<?= $escape($bidStatus) ?>" <?= $status === $bidStatus ? 'selected' : '' ?>><?= $escape(ucfirst($bidStatus)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="date_from" class="form-label">From</label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?= $escape($dateFrom) ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="date_to" class="form-label">To</label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?= $escape($dateTo) ?>">
            </div>
            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-grow-1"><i class="bi bi-search me-1"></i>Filter</button>
                <a href="bids.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </section>

    <section class="content-card mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Recent Bids</h2>
                <div class="text-muted small">
                    Showing <?= $filteredBidCount === 0 ? 0 : $offset + 1 ?>–<?= min($offset + count($bids), $filteredBidCount) ?>
                    of <?= number_format($filteredBidCount) ?> matching bids
                </div>
            </div>
            <span class="badge text-bg-warning">Monitoring flags are review indicators, not proof of misconduct.</span>
        </div>

        <?php if ($bids === []): ?>
            <p class="text-muted mb-0">No bids match the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Bid</th>
                        <th>Buyer</th>
                        <th>Livestock</th>
                        <th>Farmer</th>
                        <th>Auction</th>
                        <th>Bid Time</th>
                        <th>Status</th>
                        <th>Monitoring</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bids as $bid): ?>
                        <?php
                        $flags = [];
                        if (
                            (int) $bid['buyer_id'] === (int) $bid['auction_farmer_id']
                            || (int) $bid['buyer_id'] === (int) $bid['livestock_farmer_id']
                        ) {
                            $flags[] = 'Seller account bid on own auction';
                        }
                        $minimumValidBid = (float) $bid['previous_highest_bid']
                            + (float) $bid['minimum_bid_increment'];
                        if ((float) $bid['bid_amount'] < $minimumValidBid) {
                            $flags[] = 'Below required minimum bid';
                        }
                        $isLeadingBid = $bid['leading_bid_id'] !== null
                            && (int) $bid['leading_bid_id'] === (int) $bid['bid_id'];
                        if ($bid['bid_status'] === 'winning' && !$isLeadingBid) {
                            $flags[] = 'Marked winning but not the leading bid';
                        } elseif ($isLeadingBid && $bid['bid_status'] !== 'winning') {
                            $flags[] = 'Leading bid is not marked winning';
                        }
                        $statusClass = [
                            'active' => 'primary',
                            'winning' => 'success',
                            'outbid' => 'warning',
                            'cancelled' => 'secondary',
                        ][$bid['bid_status']] ?? 'secondary';
                        $bidTime = new DateTime((string) $bid['bid_time']);
                        ?>
                        <tr>
                            <td class="fw-semibold text-nowrap">KES <?= number_format((float) $bid['bid_amount'], 2) ?></td>
                            <td>
                                <div class="fw-semibold"><?= $escape($bid['buyer_name']) ?></div>
                                <small class="text-muted"><?= $escape($bid['buyer_email']) ?></small>
                            </td>
                            <td><?= $escape($bid['animal_type']) ?><?= !empty($bid['breed']) ? ' / ' . $escape($bid['breed']) : '' ?></td>
                            <td><?= $escape($bid['farmer_name']) ?></td>
                            <td><a href="auctions.php?id=<?= (int) $bid['auction_id'] ?>">#<?= (int) $bid['auction_id'] ?></a></td>
                            <td class="text-nowrap"><?= $escape($bidTime->format('d M Y, H:i')) ?></td>
                            <td><span class="badge text-bg-<?= $statusClass ?>"><?= $escape(ucfirst($bid['bid_status'])) ?></span></td>
                            <td class="monitor-flag">
                                <?php if ($flags === []): ?>
                                    <span class="text-muted">No flags</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger"><?= $escape(implode('; ', $flags)) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Bid history pages">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => max(1, $page - 1)]) ?>" aria-label="Previous page">Previous</a>
                        </li>
                        <?php
                        $firstPage = max(1, $page - 2);
                        $lastPage = min($totalPages, $page + 2);
                        for ($pageNumber = $firstPage; $pageNumber <= $lastPage; $pageNumber++):
                        ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => $pageNumber]) ?>"><?= $pageNumber ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => min($totalPages, $page + 1)]) ?>" aria-label="Next page">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Auction Results</h2>
                <p class="text-muted small mb-0">Ended auctions and any recorded winner results.</p>
            </div>
            <a href="auctions.php" class="btn btn-sm btn-outline-success">Manage auctions</a>
        </div>
        <?php if ($auctionResults === []): ?>
            <p class="text-muted mb-0">No ended auction results to display.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Auction</th><th>Livestock</th><th>Ended</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($auctionResults as $result): ?>
                        <?php $endedAt = new DateTime((string) $result['end_time']); ?>
                        <tr>
                            <td><a href="auctions.php?id=<?= (int) $result['auction_id'] ?>">#<?= (int) $result['auction_id'] ?></a></td>
                            <td><?= $escape($result['animal_type']) ?><?= !empty($result['breed']) ? ' / ' . $escape($result['breed']) : '' ?></td>
                            <td><?= $escape($endedAt->format('d M Y, H:i')) ?></td>
                            <td>
                                <?php if ($result['winner_name'] !== null && $result['winning_bid'] !== null): ?>
                                    <span class="fw-semibold"><?= $escape($result['winner_name']) ?></span>
                                    <span class="text-muted">— KES <?= number_format((float) $result['winning_bid'], 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">No winner recorded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
