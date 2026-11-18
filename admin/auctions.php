<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminName = getUserName();
$escape = 'escapeHtml';

$csrfToken = getCsrfToken();

$flashSuccess = $_SESSION['admin_auction_success'] ?? '';
$flashError = $_SESSION['admin_auction_error'] ?? '';
unset($_SESSION['admin_auction_success'], $_SESSION['admin_auction_error']);

$requestUri = 'auctions.php';
$rawId = $_POST['auction_id'] ?? '';
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $auctionId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $validActions = ['approve', 'reject', 'suspend', 'cancel', 'reinstate'];

    if (!isValidCsrfToken($postedToken)) {
        $_SESSION['admin_auction_error'] = 'Your form session expired. Please reload and try again.';
    } elseif (!$auctionId || !is_string($action) || !in_array($action, $validActions, true)) {
        $_SESSION['admin_auction_error'] = 'Choose a valid auction and action.';
    } else {
        try {
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare('SELECT id, status, end_time FROM auctions WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$auctionId]);
            $lockedAuction = $lockStmt->fetch();

            if (!$lockedAuction) {
                throw new DomainException('Auction not found.');
            }

            $transition = null;
            $successMessage = '';
            if ($action === 'approve' && $lockedAuction['status'] === 'pending') {
                $databaseNow = new DateTime((string) $pdo->query('SELECT NOW()')->fetchColumn());
                if (new DateTime((string) $lockedAuction['end_time']) <= $databaseNow) {
                    throw new DomainException('An expired auction cannot be approved.');
                }
                $transition = 'active';
                $successMessage = 'Auction approved and made available according to its scheduled start time.';
            } elseif ($action === 'reject' && $lockedAuction['status'] === 'pending') {
                $transition = 'cancelled';
                $successMessage = 'Pending auction rejected.';
            } elseif ($action === 'suspend' && in_array($lockedAuction['status'], ['pending', 'active'], true)) {
                $transition = 'suspended';
                $successMessage = 'Auction suspended. Bidding is disabled.';
            } elseif ($action === 'cancel' && in_array($lockedAuction['status'], ['pending', 'active'], true)) {
                $transition = 'cancelled';
                $successMessage = 'Auction cancelled.';
            } elseif ($action === 'reinstate' && $lockedAuction['status'] === 'suspended') {
                $databaseNow = new DateTime((string) $pdo->query('SELECT NOW()')->fetchColumn());
                if (new DateTime((string) $lockedAuction['end_time']) <= $databaseNow) {
                    throw new DomainException('An expired auction cannot be reinstated.');
                }
                $transition = 'active';
                $successMessage = 'Auction reinstated.';
            }

            if ($transition === null) {
                throw new DomainException('That action is not available for the auction’s current status.');
            }

            $updateStmt = $pdo->prepare('UPDATE auctions SET status = ? WHERE id = ?');
            $updateStmt->execute([$transition, $auctionId]);
            $pdo->commit();
            $_SESSION['admin_auction_success'] = $successMessage;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['admin_auction_error'] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin auction action failed: ' . $exception->getMessage());
            $_SESSION['admin_auction_error'] = 'Unable to update the auction right now. Please try again.';
        }
    }

    $redirectQuery = [];
    foreach (['search', 'status', 'date_from', 'date_to', 'page', 'view'] as $key) {
        $value = $_POST[$key] ?? '';
        if (is_scalar($value) && (string) $value !== '') {
            $redirectQuery[$key] = (string) $value;
        }
    }
    header('Location: ' . $requestUri . ($redirectQuery === [] ? '' : '?' . http_build_query($redirectQuery)));
    exit;
}

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$viewInput = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT);
$viewAuctionId = $viewInput === false || $viewInput === null ? 0 : max(0, $viewInput);
$search = is_string($search) ? substr(trim($search), 0, 100) : '';
$statusFilter = is_string($statusFilter) ? $statusFilter : '';
$dateFrom = is_string($dateFrom) ? $dateFrom : '';
$dateTo = is_string($dateTo) ? $dateTo : '';

$validStatuses = ['pending', 'active', 'completed', 'cancelled', 'suspended', 'ended'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
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

$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total_auctions,
        COALESCE(SUM(status = 'pending'), 0) AS pending_auctions,
        COALESCE(SUM(status = 'active' AND start_time <= NOW() AND end_time > NOW()), 0) AS active_auctions,
        COALESCE(SUM(status = 'completed' OR (status = 'active' AND end_time <= NOW())), 0) AS ended_auctions,
        COALESCE(SUM(status = 'suspended'), 0) AS suspended_auctions
     FROM auctions"
)->fetch();

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(l.animal_type LIKE ? OR l.breed LIKE ? OR farmer.name LIKE ? OR farmer.email LIKE ? OR CAST(a.id AS CHAR) = ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $search);
}
if ($statusFilter === 'ended') {
    $conditions[] = "(a.status = 'completed' OR (a.status = 'active' AND a.end_time <= NOW()))";
} elseif ($statusFilter === 'active') {
    $conditions[] = "a.status = 'active' AND a.start_time <= NOW() AND a.end_time > NOW()";
} elseif ($statusFilter !== '') {
    $conditions[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($dateFrom !== '') {
    $conditions[] = 'a.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}

$whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM auctions a
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users farmer ON farmer.id = a.farmer_id
     $whereSql"
);
$countStmt->execute($params);
$filteredCount = (int) $countStmt->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int) ceil($filteredCount / $perPage));
$pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $pageInput === false || $pageInput === null ? 1 : max(1, $pageInput);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$auctionStmt = $pdo->prepare(
    "SELECT
        a.id,
        a.livestock_id,
        a.starting_price,
        a.minimum_bid_increment,
        a.start_time,
        a.end_time,
        a.status,
        a.winner_id,
        a.winning_bid,
        a.created_at,
        l.animal_type,
        l.breed,
        l.image,
        farmer.name AS farmer_name,
        COUNT(b.id) AS bid_count,
        MAX(CASE WHEN b.status <> 'cancelled' THEN b.bid_amount END) AS highest_bid,
        winner.name AS winner_name
     FROM auctions a
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users farmer ON farmer.id = a.farmer_id
     LEFT JOIN bids b ON b.auction_id = a.id
     LEFT JOIN users winner ON winner.id = a.winner_id
     $whereSql
     GROUP BY
        a.id, a.livestock_id, a.starting_price, a.minimum_bid_increment,
        a.start_time, a.end_time, a.status, a.winner_id, a.winning_bid, a.created_at,
        l.animal_type, l.breed, l.image, farmer.name, winner.name
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT $perPage OFFSET $offset"
);
$auctionStmt->execute($params);
$auctions = $auctionStmt->fetchAll();

$selectedAuction = null;
$detailBids = [];
if ($viewAuctionId > 0) {
    $detailStmt = $pdo->prepare(
        "SELECT
            a.id, a.livestock_id, a.starting_price, a.minimum_bid_increment,
            a.start_time, a.end_time, a.status, a.winner_id, a.winning_bid, a.created_at,
            l.animal_type, l.breed, l.gender, l.age, l.weight, l.health_status,
            l.description, l.location, l.image,
            farmer.name AS farmer_name, farmer.email AS farmer_email, farmer.phone AS farmer_phone,
            winner.name AS winner_name,
            (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id AND status <> 'cancelled') AS highest_bid
         FROM auctions a
         INNER JOIN livestock l ON l.id = a.livestock_id
         INNER JOIN users farmer ON farmer.id = a.farmer_id
         LEFT JOIN users winner ON winner.id = a.winner_id
         WHERE a.id = ?
         LIMIT 1"
    );
    $detailStmt->execute([$viewAuctionId]);
    $selectedAuction = $detailStmt->fetch();

    if ($selectedAuction) {
        $detailBidsStmt = $pdo->prepare(
            "SELECT b.bid_amount, b.bid_time, b.status, buyer.name AS buyer_name
             FROM bids b
             INNER JOIN users buyer ON buyer.id = b.buyer_id
             WHERE b.auction_id = ?
             ORDER BY b.bid_time DESC, b.id DESC
             LIMIT 100"
        );
        $detailBidsStmt->execute([$viewAuctionId]);
        $detailBids = $detailBidsStmt->fetchAll();
    } else {
        $flashError = 'The selected auction could not be found.';
    }
}

$queryParams = array_filter([
    'search' => $search,
    'status' => $statusFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn ($value): bool => $value !== '');

$statusClass = [
    'pending' => 'warning',
    'active' => 'success',
    'completed' => 'primary',
    'cancelled' => 'secondary',
    'suspended' => 'danger',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auction Management | Digital Livestock Auction</title>
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
        .stat-card { height: 100%; padding: 18px; }
        .stat-card i { color: #12372A; font-size: 21px; }
        .stat-number { margin-top: 8px; color: #12372A; font-size: 26px; font-weight: 700; }
        .stat-label { color: #718096; font-size: 13px; }
        .filter-card, .content-card { padding: 22px; }
        .animal-image { width: 60px; height: 54px; object-fit: cover; border-radius: 8px; background: #eef1f0; }
        .table td, .table th { vertical-align: middle; }
        .detail-image { width: 100%; max-height: 400px; object-fit: cover; border-radius: 10px; background: #eef1f0; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .meta-box { border: 1px solid #edf0f2; background: #f8faf8; border-radius: 10px; padding: 12px; }
        .meta-label { display: block; color: #718096; font-size: 12px; text-transform: uppercase; }
        @media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
        @media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
    <header class="topbar">
        <h1>Auction Management</h1>
        <p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Review listings, monitor bidding, and track results.</p>
    </header>

    <?php if ($flashSuccess !== ''): ?><div class="alert alert-success" role="alert"><?= $escape($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($flashError) ?></div><?php endif; ?>

    <section class="row g-3 mb-4" aria-label="Auction summary">
        <?php
        $summaryCards = [
            ['All Auctions', 'total_auctions', 'bi-collection'],
            ['Scheduled', 'pending_auctions', 'bi-hourglass-split'],
            ['Open for Bidding', 'active_auctions', 'bi-hammer'],
            ['Ended', 'ended_auctions', 'bi-check2-circle'],
            ['Suspended', 'suspended_auctions', 'bi-pause-circle'],
        ];
        foreach ($summaryCards as [$label, $key, $icon]):
        ?>
            <div class="col-sm-6 col-xl">
                <article class="stat-card">
                    <i class="bi <?= $escape($icon) ?>"></i>
                    <div class="stat-number"><?= number_format((int) $stats[$key]) ?></div>
                    <div class="stat-label"><?= $escape($label) ?></div>
                </article>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="filter-card mb-4">
        <h2 class="h5 fw-bold mb-3">Search and Filter</h2>
        <form method="GET" action="auctions.php" class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label for="search" class="form-label">Livestock, breed, farmer, or auction ID</label>
                <input id="search" name="search" type="search" class="form-control" maxlength="100" value="<?= $escape($search) ?>" placeholder="Search auctions">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach ($validStatuses as $auctionStatus): ?>
                        <option value="<?= $escape($auctionStatus) ?>" <?= $statusFilter === $auctionStatus ? 'selected' : '' ?>><?= $escape(ucfirst($auctionStatus)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="date_from" class="form-label">Created from</label>
                <input id="date_from" name="date_from" type="date" class="form-control" value="<?= $escape($dateFrom) ?>">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="date_to" class="form-label">Created to</label>
                <input id="date_to" name="date_to" type="date" class="form-control" value="<?= $escape($dateTo) ?>">
            </div>
            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
                <a href="auctions.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </section>

    <section class="content-card mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">All Auctions</h2>
                <div class="text-muted small">
                    Showing <?= $filteredCount === 0 ? 0 : $offset + 1 ?>–<?= min($offset + count($auctions), $filteredCount) ?>
                    of <?= number_format($filteredCount) ?> auctions
                </div>
            </div>
        </div>
        <?php if ($auctions === []): ?>
            <p class="text-muted mb-0">No auctions match the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Livestock</th><th>Farmer</th><th>Starting Price</th><th>Highest Bid</th>
                        <th>Bids</th><th>Closes</th><th>Status</th><th>Result</th><th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auctions as $auction): ?>
                        <?php
                        $displayStatus = $auction['status'] === 'active' && strtotime($auction['end_time']) <= time()
                            ? 'ended'
                            : $auction['status'];
                        $badgeClass = $statusClass[$displayStatus] ?? 'secondary';
                        $imageName = !empty($auction['image']) ? basename((string) $auction['image']) : '';
                        $imageUrl = $imageName !== ''
                            ? '../uploads/livestock/' . rawurlencode($imageName)
                            : '../assets/images/GANADO DOBLE PROPOSITO.png';
                        $closesAt = new DateTime((string) $auction['end_time']);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= $escape($imageUrl) ?>" alt="" class="animal-image">
                                    <div>
                                        <div class="fw-semibold"><?= $escape($auction['animal_type']) ?></div>
                                        <small class="text-muted"><?= $escape($auction['breed'] ?: 'Breed not specified') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= $escape($auction['farmer_name']) ?></td>
                            <td class="text-nowrap">KES <?= number_format((float) $auction['starting_price'], 2) ?></td>
                            <td class="text-nowrap">
                                <?= $auction['highest_bid'] !== null ? 'KES ' . number_format((float) $auction['highest_bid'], 2) : '—' ?>
                            </td>
                            <td><?= number_format((int) $auction['bid_count']) ?></td>
                            <td class="text-nowrap"><?= $escape($closesAt->format('d M Y, H:i')) ?></td>
                            <td><span class="badge text-bg-<?= $badgeClass ?>"><?= $escape(ucfirst($displayStatus)) ?></span></td>
                            <td>
                                <?php if ($auction['winner_name'] !== null && $auction['winning_bid'] !== null): ?>
                                    <span class="fw-semibold"><?= $escape($auction['winner_name']) ?></span>
                                    <small class="d-block text-muted">KES <?= number_format((float) $auction['winning_bid'], 2) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?<?= http_build_query($queryParams + ['page' => $page, 'view' => (int) $auction['id']]) ?>">Details</a>
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Manage</button>
                                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 220px">
                                        <?php
                                        $availableActions = match ($auction['status']) {
                                            'pending' => [
                                                ['approve', 'Approve', 'success'],
                                                ['reject', 'Reject', 'danger'],
                                                ['suspend', 'Suspend', 'warning'],
                                            ],
                                            'active' => [
                                                ['suspend', 'Suspend', 'warning'],
                                                ['cancel', 'Cancel', 'danger'],
                                            ],
                                            'suspended' => [
                                                ['reinstate', 'Reinstate', 'success'],
                                                ['cancel', 'Cancel', 'danger'],
                                            ],
                                            default => [],
                                        };
                                        ?>
                                        <?php if ($availableActions === []): ?>
                                            <span class="text-muted small">No actions available.</span>
                                        <?php else: ?>
                                            <?php foreach ($availableActions as [$actionName, $actionLabel, $buttonClass]): ?>
                                                <form method="POST" class="mb-2">
                                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="auction_id" value="<?= (int) $auction['id'] ?>">
                                                    <input type="hidden" name="action" value="<?= $escape($actionName) ?>">
                                                    <?php foreach ($queryParams as $key => $value): ?>
                                                        <input type="hidden" name="<?= $escape($key) ?>" value="<?= $escape($value) ?>">
                                                    <?php endforeach; ?>
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <input type="hidden" name="view" value="<?= $viewAuctionId ?>">
                                                    <button type="submit" class="btn btn-sm btn-<?= $buttonClass ?> w-100"><?= $escape($actionLabel) ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Auction list pages">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => max(1, $page - 1)]) ?>">Previous</a>
                        </li>
                        <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
                            <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => $pageNumber]) ?>"><?= $pageNumber ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query($queryParams + ['page' => min($totalPages, $page + 1)]) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($selectedAuction !== null): ?>
        <?php
        $detailImageName = !empty($selectedAuction['image']) ? basename((string) $selectedAuction['image']) : '';
        $detailImageUrl = $detailImageName !== ''
            ? '../uploads/livestock/' . rawurlencode($detailImageName)
            : '../assets/images/GANADO DOBLE PROPOSITO.png';
        $detailStart = new DateTime((string) $selectedAuction['start_time']);
        $detailEnd = new DateTime((string) $selectedAuction['end_time']);
        ?>
        <section class="content-card mb-4" id="auction-details">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1">Auction #<?= (int) $selectedAuction['id'] ?> Details</h2>
                    <p class="text-muted mb-0">Livestock, farmer, pricing, and bid activity.</p>
                </div>
                <a href="auctions.php<?= $queryParams === [] ? '' : '?' . http_build_query($queryParams + ['page' => $page]) ?>" class="btn btn-outline-secondary btn-sm">Close</a>
            </div>
            <div class="row g-4">
                <div class="col-lg-5">
                    <img src="<?= $escape($detailImageUrl) ?>" alt="<?= $escape($selectedAuction['animal_type']) ?>" class="detail-image">
                    <h3 class="h4 fw-bold mt-3">
                        <?= $escape($selectedAuction['animal_type']) ?>
                        <?= !empty($selectedAuction['breed']) ? ' - ' . $escape($selectedAuction['breed']) : '' ?>
                    </h3>
                    <p class="text-muted"><?= nl2br($escape($selectedAuction['description'] ?: 'No description provided.')) ?></p>
                    <div class="meta-grid">
                        <div class="meta-box"><span class="meta-label">Gender</span><?= $escape(ucfirst($selectedAuction['gender'])) ?></div>
                        <div class="meta-box"><span class="meta-label">Age</span><?= $selectedAuction['age'] !== null ? $escape($selectedAuction['age']) . ' months' : 'Not specified' ?></div>
                        <div class="meta-box"><span class="meta-label">Weight</span><?= $selectedAuction['weight'] !== null ? $escape($selectedAuction['weight']) . ' kg' : 'Not specified' ?></div>
                        <div class="meta-box"><span class="meta-label">Health</span><?= $escape($selectedAuction['health_status'] ?: 'Not specified') ?></div>
                        <div class="meta-box"><span class="meta-label">Location</span><?= $escape($selectedAuction['location'] ?: 'Not specified') ?></div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <h3 class="h5 fw-bold">Farmer Information</h3>
                    <p class="mb-4">
                        <strong><?= $escape($selectedAuction['farmer_name']) ?></strong><br>
                        <?= $escape($selectedAuction['farmer_email']) ?><br>
                        <?= $escape($selectedAuction['farmer_phone']) ?>
                    </p>
                    <h3 class="h5 fw-bold">Auction and Bidding</h3>
                    <div class="meta-grid mb-4">
                        <div class="meta-box"><span class="meta-label">Status</span><?= $escape(ucfirst($selectedAuction['status'])) ?></div>
                        <div class="meta-box"><span class="meta-label">Starting Price</span>KES <?= number_format((float) $selectedAuction['starting_price'], 2) ?></div>
                        <div class="meta-box"><span class="meta-label">Highest Bid</span><?= $selectedAuction['highest_bid'] !== null ? 'KES ' . number_format((float) $selectedAuction['highest_bid'], 2) : 'No bids' ?></div>
                        <div class="meta-box"><span class="meta-label">Bid Increment</span>KES <?= number_format((float) $selectedAuction['minimum_bid_increment'], 2) ?></div>
                        <div class="meta-box"><span class="meta-label">Starts</span><?= $escape($detailStart->format('d M Y, H:i')) ?></div>
                        <div class="meta-box"><span class="meta-label">Closes</span><?= $escape($detailEnd->format('d M Y, H:i')) ?></div>
                    </div>
                    <?php if ($selectedAuction['winner_name'] !== null && $selectedAuction['winning_bid'] !== null): ?>
                        <div class="alert alert-success">
                            <strong>Recorded result:</strong> <?= $escape($selectedAuction['winner_name']) ?>
                            — KES <?= number_format((float) $selectedAuction['winning_bid'], 2) ?>
                        </div>
                    <?php endif; ?>
                    <h3 class="h5 fw-bold">Bid History</h3>
                    <?php if ($detailBids === []): ?>
                        <p class="text-muted">No bids have been placed.</p>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 350px">
                            <table class="table table-sm table-hover">
                                <thead class="table-light"><tr><th>Buyer</th><th>Amount</th><th>Time</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($detailBids as $detailBid): ?>
                                    <?php $bidDate = new DateTime((string) $detailBid['bid_time']); ?>
                                    <tr>
                                        <td><?= $escape($detailBid['buyer_name']) ?></td>
                                        <td>KES <?= number_format((float) $detailBid['bid_amount'], 2) ?></td>
                                        <td><?= $escape($bidDate->format('d M Y, H:i')) ?></td>
                                        <td><?= $escape(ucfirst($detailBid['status'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($detailBids) === 100): ?><small class="text-muted">Showing the latest 100 bids.</small><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
