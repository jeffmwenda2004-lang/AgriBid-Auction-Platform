<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$farmerName = getUserName();
$escape = 'escapeHtml';

$totalBidsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    WHERE a.farmer_id = ?
");
$totalBidsStmt->execute([$farmerId]);
$totalBids = (int) $totalBidsStmt->fetchColumn();

$activeAuctionsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE farmer_id = ?
      AND status = 'active'
      AND start_time <= NOW()
      AND end_time > NOW()
");
$activeAuctionsStmt->execute([$farmerId]);
$activeAuctions = (int) $activeAuctionsStmt->fetchColumn();

$animalTypesStmt = $pdo->prepare("
    SELECT DISTINCT l.animal_type
    FROM auctions a
    INNER JOIN livestock l ON l.id = a.livestock_id
    WHERE a.farmer_id = ?
    ORDER BY l.animal_type
");
$animalTypesStmt->execute([$farmerId]);
$animalTypes = $animalTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$animalType = $_GET['animal_type'] ?? '';
$auctionStatus = $_GET['auction_status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

if (!is_string($animalType) || !in_array($animalType, $animalTypes, true)) {
    $animalType = '';
}

$validAuctionStatuses = ['active', 'pending', 'completed', 'cancelled', 'ended'];
if (!is_string($auctionStatus) || !in_array($auctionStatus, $validAuctionStatuses, true)) {
    $auctionStatus = '';
}

$isValidDate = static function ($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
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

$conditions = ['a.farmer_id = ?'];
$params = [$farmerId];

if ($animalType !== '') {
    $conditions[] = 'l.animal_type = ?';
    $params[] = $animalType;
}

if ($auctionStatus === 'ended') {
    $conditions[] = "(a.status = 'completed' OR (a.status = 'active' AND a.end_time <= NOW()))";
} elseif ($auctionStatus === 'active') {
    $conditions[] = "a.status = 'active' AND a.start_time <= NOW() AND a.end_time > NOW()";
} elseif ($auctionStatus === 'pending') {
    $conditions[] = "a.status = 'pending' AND a.start_time > NOW() AND a.end_time > NOW()";
} elseif ($auctionStatus !== '') {
    $conditions[] = 'a.status = ?';
    $params[] = $auctionStatus;
}

if ($dateFrom !== '') {
    $conditions[] = 'b.bid_time >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = 'b.bid_time < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}

$bidsStmt = $pdo->prepare("
    SELECT
        b.id AS bid_id,
        b.bid_amount,
        b.bid_time,
        b.status AS stored_bid_status,
        a.id AS auction_id,
        a.status AS auction_status,
        a.start_time,
        a.end_time,
        a.starting_price,
        a.minimum_bid_increment,
        a.winner_id,
        a.winning_bid,
        l.id AS livestock_id,
        l.animal_type,
        l.breed,
        l.image,
        buyer.id AS buyer_id,
        buyer.name AS buyer_name,
        buyer.email AS buyer_email,
        buyer.phone AS buyer_phone,
        (
            SELECT MAX(all_bids.bid_amount)
            FROM bids all_bids
            WHERE all_bids.auction_id = a.id
        ) AS highest_bid,
        (
            SELECT leader.id
            FROM bids leader
            WHERE leader.auction_id = a.id
            ORDER BY leader.bid_amount DESC, leader.bid_time DESC, leader.id DESC
            LIMIT 1
        ) AS leading_bid_id
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    INNER JOIN livestock l ON l.id = a.livestock_id
    INNER JOIN users buyer ON buyer.id = b.buyer_id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY b.bid_time DESC, b.id DESC
    LIMIT 200
");
$bidsStmt->execute($params);
$bids = $bidsStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bids Received | Digital Livestock Auction</title>
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
        .stat-label, .text-muted-small { color: #718096; font-size: 13px; }
        .filter-card, .content-card { padding: 22px; }
        .livestock-image {
            width: 54px; height: 54px; border-radius: 9px;
            object-fit: cover; background: #eef1f0;
        }
        .table td { vertical-align: middle; }
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
    <header class="topbar">
        <h1>Bids Received</h1>
        <p>Welcome, <?= $escape($farmerName ?? 'Farmer') ?>. Review bidding activity across your auctions.</p>
    </header>

    <section class="row g-4 mb-4" aria-label="Bid statistics">
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-hand-index-thumb"></i></div>
                <div class="stat-number"><?= $totalBids ?></div>
                <div class="stat-label">Total Bids Received</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
                <div class="stat-number"><?= $activeAuctions ?></div>
                <div class="stat-label">Auctions Accepting Bids</div>
            </div>
        </div>
    </section>

    <section class="filter-card mb-4">
        <form method="GET" action="bids.php" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label for="animal_type" class="form-label fw-semibold">Animal Type</label>
                <select id="animal_type" name="animal_type" class="form-select">
                    <option value="">All animal types</option>
                    <?php foreach ($animalTypes as $type): ?>
                        <option value="<?= $escape($type) ?>" <?= $animalType === $type ? 'selected' : '' ?>>
                            <?= $escape($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label for="auction_status" class="form-label fw-semibold">Auction Status</label>
                <select id="auction_status" name="auction_status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="active" <?= $auctionStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $auctionStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="ended" <?= $auctionStatus === 'ended' ? 'selected' : '' ?>>Ended</option>
                    <option value="completed" <?= $auctionStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $auctionStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label for="date_from" class="form-label fw-semibold">Bid Date From</label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?= $escape($dateFrom) ?>">
            </div>
            <div class="col-lg-2 col-md-6">
                <label for="date_to" class="form-label fw-semibold">Bid Date To</label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?= $escape($dateTo) ?>">
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-fill">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="bids.php" class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </section>

    <section class="content-card" aria-labelledby="recent-bids-heading">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 id="recent-bids-heading" class="h4 mb-0">Recent Bids</h2>
            <span class="badge bg-success rounded-pill"><?= count($bids) ?> shown<?= count($bids) === 200 ? ' (latest 200)' : '' ?></span>
        </div>

        <?php if (empty($bids)): ?>
            <div class="text-center py-5">
                <i class="bi bi-hand-index-thumb fs-1 text-muted"></i>
                <h4 class="mt-3">No bids found</h4>
                <p class="text-muted mb-0">No bids match the selected filters for your auctions.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Buyer</th>
                            <th>Livestock</th>
                            <th>Bid Amount</th>
                            <th>Auction Highest</th>
                            <th>Bid Date</th>
                            <th>Auction Status</th>
                            <th>Bid Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bids as $bid): ?>
                            <?php
                            $ended = strtotime($bid['end_time']) <= time();
                            if ($bid['stored_bid_status'] === 'cancelled') {
                                $bidStatus = 'Cancelled';
                                $bidStatusClass = 'bg-secondary';
                            } elseif ($bid['auction_status'] === 'completed') {
                                $isWinner = (int) $bid['winner_id'] === (int) $bid['buyer_id'];
                                $bidStatus = $isWinner ? 'Winning bid' : 'Auction lost';
                                $bidStatusClass = $isWinner ? 'bg-success' : 'bg-secondary';
                            } elseif ($bid['auction_status'] === 'cancelled') {
                                $bidStatus = 'Auction cancelled';
                                $bidStatusClass = 'bg-secondary';
                            } elseif ($ended) {
                                $bidStatus = 'Auction ended';
                                $bidStatusClass = 'bg-secondary';
                            } elseif ((int) $bid['leading_bid_id'] === (int) $bid['bid_id']) {
                                $bidStatus = 'Winning';
                                $bidStatusClass = 'bg-success';
                            } else {
                                $bidStatus = 'Outbid';
                                $bidStatusClass = 'bg-danger';
                            }

                            $displayAuctionStatus = $bid['auction_status'];
                            if ($displayAuctionStatus === 'active' && $ended) {
                                $displayAuctionStatus = 'ended';
                            }
                            $auctionStatusClass = match ($displayAuctionStatus) {
                                'active' => 'text-bg-success',
                                'pending' => 'text-bg-warning',
                                'completed' => 'text-bg-primary',
                                'cancelled' => 'text-bg-secondary',
                                default => 'text-bg-secondary',
                            };
                            $livestockImage = !empty($bid['image'])
                                ? '../uploads/livestock/' . $bid['image']
                                : '../assets/images/GANADO DOBLE PROPOSITO.png';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= $escape($bid['buyer_name']) ?></strong>
                                    <div class="text-muted-small"><?= $escape($bid['buyer_email']) ?></div>
                                    <?php if (!empty($bid['buyer_phone'])): ?>
                                        <div class="text-muted-small"><?= $escape($bid['buyer_phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2" style="min-width: 190px">
                                        <img src="<?= $escape($livestockImage) ?>" alt="<?= $escape($bid['animal_type']) ?>" class="livestock-image">
                                        <div>
                                            <strong><?= $escape($bid['animal_type']) ?></strong>
                                            <?php if (!empty($bid['breed'])): ?>
                                                <div class="text-muted-small"><?= $escape($bid['breed']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="fw-semibold">KES <?= number_format((float) $bid['bid_amount'], 2) ?></td>
                                <td class="fw-semibold">KES <?= number_format(
                                    $bid['highest_bid'] !== null
                                        ? (float) $bid['highest_bid']
                                        : (float) $bid['starting_price'],
                                    2
                                ) ?></td>
                                <td><?= date('d M Y, H:i', strtotime($bid['bid_time'])) ?></td>
                                <td><span class="badge <?= $auctionStatusClass ?>"><?= $escape(ucfirst($displayAuctionStatus)) ?></span></td>
                                <td><span class="badge <?= $bidStatusClass ?>"><?= $escape($bidStatus) ?></span></td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <a href="auctions.php#auction-<?= (int) $bid['auction_id'] ?>" class="btn btn-outline-success btn-sm">Auction</a>
                                        <a href="livestock.php#livestock-<?= (int) $bid['livestock_id'] ?>" class="btn btn-outline-secondary btn-sm">Livestock</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>