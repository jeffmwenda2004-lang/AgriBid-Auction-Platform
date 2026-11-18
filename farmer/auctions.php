<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$farmerName = getUserName();

$csrfToken = getCsrfToken();

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_auctions,
        COALESCE(SUM(status = 'active' AND end_time > NOW()), 0) AS active_auctions,
        COALESCE(SUM(status = 'pending' AND end_time > NOW()), 0) AS pending_auctions,
        COALESCE(SUM(status = 'completed' OR end_time <= NOW()), 0) AS ended_auctions
    FROM auctions
    WHERE farmer_id = ?
");
$summaryStmt->execute([$farmerId]);
$auctionStats = $summaryStmt->fetch();

$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$validStatuses = ['active', 'pending', 'completed', 'cancelled', 'ended'];
if (!is_string($statusFilter) || !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$conditions = ['a.farmer_id = ?'];
$params = [$farmerId];

if ($search !== '') {
    $conditions[] = '(l.animal_type LIKE ? OR l.breed LIKE ? OR l.location LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if ($statusFilter !== '') {
    if ($statusFilter === 'ended') {
        $conditions[] = "(a.status = 'completed' OR (a.status = 'active' AND a.end_time <= NOW()))";
    } elseif ($statusFilter === 'active') {
        $conditions[] = "a.status = 'active' AND a.end_time > NOW()";
    } elseif ($statusFilter === 'pending') {
        $conditions[] = "a.status = 'pending' AND a.end_time > NOW()";
    } else {
        $conditions[] = 'a.status = ?';
        $params[] = $statusFilter;
    }
}

$auctionStmt = $pdo->prepare("
    SELECT
        a.id,
        a.livestock_id,
        a.starting_price,
        a.minimum_bid_increment,
        a.start_time,
        a.end_time,
        a.status,
        a.winner_id,
        a.winning_bid,
        l.animal_type,
        l.breed,
        l.gender,
        l.weight,
        l.location,
        l.image,
        COUNT(b.id) AS bid_count,
        MAX(b.bid_amount) AS highest_bid,
        winner.name AS winner_name
    FROM auctions a
    INNER JOIN livestock l ON l.id = a.livestock_id
    LEFT JOIN bids b ON b.auction_id = a.id
    LEFT JOIN users winner ON winner.id = a.winner_id
    WHERE " . implode(' AND ', $conditions) . "
    GROUP BY
        a.id, a.livestock_id, a.starting_price, a.minimum_bid_increment,
        a.start_time, a.end_time, a.status, a.winner_id, a.winning_bid,
        l.animal_type, l.breed, l.gender, l.weight, l.location, l.image,
        winner.name
    ORDER BY
        CASE
            WHEN a.status = 'active' AND a.end_time > NOW() THEN 0
            WHEN a.status = 'pending' AND a.end_time > NOW() THEN 1
            ELSE 2
        END,
        a.end_time ASC,
        a.created_at DESC
");
$auctionStmt->execute($params);
$auctions = $auctionStmt->fetchAll();

$successMessage = $_SESSION['farmer_auction_success'] ?? '';
$errorMessage = $_SESSION['farmer_auction_error'] ?? '';
unset($_SESSION['farmer_auction_success'], $_SESSION['farmer_auction_error']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Auctions | Digital Livestock Auction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7f9; color: #263238; }
        .sidebar {
            width: 250px; min-height: 100vh; background: #12372A;
            position: fixed; left: 0; top: 0; padding: 25px 15px; z-index: 1000;
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
        .topbar, .stat-card, .filter-card, .auction-card {
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
        .stat-label, .text-muted-small { color: #718096; font-size: 13px; }
        .filter-card { padding: 20px; margin-bottom: 24px; }
        .auction-card { overflow: hidden; height: 100%; border: 1px solid #edf0f2; }
        .auction-image {
            width: 100%; height: 210px; object-fit: cover; background: #eef1f0;
        }
        .auction-body { padding: 18px; }
        .animal-title { font-size: 19px; font-weight: 700; }
        .price-value { color: #12372A; font-size: 20px; font-weight: 700; }
        .detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin: 14px 0;
        }
        .detail-item { padding: 9px; border-radius: 8px; background: #f7faf8; }
        .detail-label {
            display: block; color: #718096; font-size: 11px; text-transform: uppercase;
        }
        .detail-value { font-size: 13px; font-weight: 600; }
        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }
        @media (max-width: 767px) {
            .sidebar { position: relative; width: 100%; min-height: auto; }
            .main { margin-left: 0; padding: 20px; }
        }
    </style>
    <link href="../assets/css/farmer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/farmer-nav.php'; ?>

<main class="main">
    <header class="topbar d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1>My Auctions</h1>
            <p>Welcome, <?= htmlspecialchars($farmerName ?? 'Farmer', ENT_QUOTES, 'UTF-8') ?>. Track bids and auction progress.</p>
        </div>
        <a href="create-auction.php" class="btn btn-success">
            <i class="bi bi-plus-circle me-1"></i> Create Auction
        </a>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="row g-4 mb-4" aria-label="Auction statistics">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-collection"></i></div>
                <div class="stat-number"><?= (int) ($auctionStats['total_auctions'] ?? 0) ?></div>
                <div class="stat-label">Total Auctions</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
                <div class="stat-number"><?= (int) ($auctionStats['active_auctions'] ?? 0) ?></div>
                <div class="stat-label">Active Auctions</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                <div class="stat-number"><?= (int) ($auctionStats['pending_auctions'] ?? 0) ?></div>
                <div class="stat-label">Pending Auctions</div>
            </div>
        </div>
    </section>

    <section class="filter-card">
        <form method="GET" action="auctions.php" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label fw-semibold">Search</label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    class="form-control"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Animal type, breed, or location"
                >
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label fw-semibold">Auction Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="ended" <?= $statusFilter === 'ended' ? 'selected' : '' ?>>Ended</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-outline-success">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
            </div>
        </form>
    </section>

    <section aria-labelledby="auction-list-heading">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 id="auction-list-heading" class="h4 mb-0">Auction Listings</h2>
            <span class="badge bg-success rounded-pill"><?= count($auctions) ?> auction(s)</span>
        </div>

        <?php if (empty($auctions)): ?>
            <div class="text-center bg-white rounded-4 shadow-sm py-5 px-3">
                <i class="bi bi-hammer fs-1 text-muted"></i>
                <h4 class="mt-3">
                    <?= $search !== '' || $statusFilter !== '' ? 'No auctions match your filters' : 'No auctions yet' ?>
                </h4>
                <p class="text-muted">Create an auction from one of your available livestock listings.</p>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="auctions.php" class="btn btn-outline-success me-2">Clear Filters</a>
                <?php endif; ?>
                <a href="create-auction.php" class="btn btn-success">Create Auction</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($auctions as $auction): ?>
                    <?php
                    $displayStatus = $auction['status'];
                    if ($displayStatus === 'active' && strtotime($auction['end_time']) <= time()) {
                        $displayStatus = 'ended';
                    }
                    $displayStatusLabel = $displayStatus === 'pending' ? 'Scheduled' : ucfirst($displayStatus);
                    $statusClass = match ($displayStatus) {
                        'active' => 'bg-success',
                        'pending' => 'bg-warning text-dark',
                        'completed' => 'bg-primary',
                        'cancelled' => 'bg-secondary',
                        default => 'bg-secondary',
                    };
                    $currentBid = $auction['highest_bid'] !== null
                        ? (float) $auction['highest_bid']
                        : (float) $auction['starting_price'];
                    $image = !empty($auction['image'])
                        ? '../uploads/livestock/' . $auction['image']
                        : '../assets/images/GANADO DOBLE PROPOSITO.png';
                    ?>
                    <div class="col-md-6 col-xl-4" id="auction-<?= (int) $auction['id'] ?>">
                        <article class="auction-card">
                            <img
                                src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($auction['animal_type'], ENT_QUOTES, 'UTF-8') ?>"
                                class="auction-image"
                            >
                            <div class="auction-body">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="animal-title">
                                        <?= htmlspecialchars($auction['animal_type'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($auction['breed'])): ?>
                                            <div class="text-muted fw-normal fs-6"><?= htmlspecialchars($auction['breed'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($displayStatusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Starting Price</span>
                                        <span class="detail-value">KES <?= number_format((float) $auction['starting_price'], 2) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Current Bid</span>
                                        <span class="detail-value">KES <?= number_format($currentBid, 2) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Bid Increment</span>
                                        <span class="detail-value">KES <?= number_format((float) $auction['minimum_bid_increment'], 2) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Bids</span>
                                        <span class="detail-value"><?= (int) $auction['bid_count'] ?></span>
                                    </div>
                                </div>

                                <div class="text-muted-small mb-2">
                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($auction['location'] ?? 'Location unavailable', ENT_QUOTES, 'UTF-8') ?>
                                    <span class="mx-1">·</span>
                                    <?= $auction['weight'] !== null ? htmlspecialchars($auction['weight'], ENT_QUOTES, 'UTF-8') . ' kg' : 'Weight unavailable' ?>
                                </div>
                                <div class="text-muted-small mb-3">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= $displayStatus === 'ended' || $displayStatus === 'completed' ? 'Ended' : 'Ends' ?>:
                                    <?= date('d M Y, H:i', strtotime($auction['end_time'])) ?>
                                </div>

                                <?php if ($displayStatus === 'completed' && !empty($auction['winner_name'])): ?>
                                    <div class="alert alert-primary py-2 mb-3">
                                        Winner: <?= htmlspecialchars($auction['winner_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($auction['winning_bid'] !== null): ?>
                                            — KES <?= number_format((float) $auction['winning_bid'], 2) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex gap-2">
                                    <a href="bids.php?auction_id=<?= (int) $auction['id'] ?>" class="btn btn-outline-success flex-fill">
                                        <i class="bi bi-list-ul me-1"></i> View Bids
                                    </a>
                                    <?php if ($displayStatus === 'active' || $displayStatus === 'pending'): ?>
                                        <a href="create-auction.php?livestock_id=<?= (int) $auction['livestock_id'] ?>" class="btn btn-outline-secondary flex-fill">
                                            Manage
                                        </a>
                                    <?php elseif ($displayStatus === 'ended' && $auction['status'] === 'active'): ?>
                                        <form method="POST" action="../auction/close-auction.php" class="flex-fill">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="auction_id" value="<?= (int) $auction['id'] ?>">
                                            <button type="submit" class="btn btn-success w-100">Close auction</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>