<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$farmerName = getUserName();

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_livestock,
        COALESCE(SUM(status = 'available'), 0) AS available_livestock
    FROM livestock
    WHERE farmer_id = ?
");
$stmt->execute([$farmerId]);
$livestockStats = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE farmer_id = ?
      AND status = 'active'
      AND end_time > NOW()
");
$stmt->execute([$farmerId]);
$activeAuctions = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    WHERE a.farmer_id = ?
");
$stmt->execute([$farmerId]);
$receivedBids = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE farmer_id = ?
      AND status = 'completed'
      AND winner_id IS NOT NULL
");
$stmt->execute([$farmerId]);
$completedSales = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT id, animal_type, breed, gender, weight, location, image, status, created_at
    FROM livestock
    WHERE farmer_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$farmerId]);
$recentLivestock = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.starting_price,
        a.end_time,
        a.status,
        l.animal_type,
        l.breed,
        (
            SELECT MAX(b.bid_amount)
            FROM bids b
            WHERE b.auction_id = a.id
        ) AS highest_bid
    FROM auctions a
    INNER JOIN livestock l ON l.id = a.livestock_id
    WHERE a.farmer_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
");
$stmt->execute([$farmerId]);
$recentAuctions = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Dashboard | Digital Livestock Auction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7f9;
            color: #263238;
        }

        .sidebar {
            width: 250px;
            min-height: 100vh;
            background: #12372A;
            position: fixed;
            left: 0;
            top: 0;
            padding: 25px 15px;
            z-index: 1000;
        }

        .brand {
            color: #fff;
            font-size: 20px;
            font-weight: 700;
            padding: 10px 15px 30px;
        }

        .brand i {
            margin-right: 8px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #dce8e2;
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: 0.2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }

        .sidebar .logout {
            margin-top: 30px;
            color: #ffcccc;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar,
        .stat-card,
        .section-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .topbar {
            padding: 20px 25px;
            margin-bottom: 25px;
        }

        .topbar h3 {
            margin: 0;
            font-weight: 700;
        }

        .topbar p {
            margin: 5px 0 0;
            color: #718096;
        }

        .stat-card {
            padding: 22px;
            height: 100%;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e6f1ec;
            color: #12372A;
            font-size: 21px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin-top: 15px;
        }

        .stat-label,
        .text-muted-small {
            color: #718096;
            font-size: 13px;
        }

        .section-card {
            padding: 22px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
        }

        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border: 1px solid #edf0f2;
            border-radius: 10px;
            text-decoration: none;
            color: #263238;
            transition: 0.2s;
            height: 100%;
        }

        .quick-action:hover {
            background: #f7faf8;
            border-color: #cbded4;
        }

        .quick-action i {
            font-size: 20px;
            color: #12372A;
        }

        .livestock-image {
            width: 58px;
            height: 58px;
            object-fit: cover;
            border-radius: 9px;
            background: #eef1f0;
        }

        .table td {
            vertical-align: middle;
        }

        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }

        @media (max-width: 767px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }
        }
    </style>
    <link href="../assets/css/farmer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/farmer-nav.php'; ?>

<main class="main">
    <header class="topbar">
        <h3>Welcome back, <?= htmlspecialchars($farmerName ?? 'Farmer', ENT_QUOTES, 'UTF-8') ?> 👋</h3>
        <p>Manage your livestock listings and track auction activity.</p>
    </header>

    <section class="row g-4 mb-4" aria-label="Farm statistics">
        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                <div class="stat-number"><?= (int) ($livestockStats['total_livestock'] ?? 0) ?></div>
                <div class="stat-label">Total Livestock</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
                <div class="stat-number"><?= (int) ($livestockStats['available_livestock'] ?? 0) ?></div>
                <div class="stat-label">Available Listings</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-hammer"></i></div>
                <div class="stat-number"><?= $activeAuctions ?></div>
                <div class="stat-label">Active Auctions</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-hand-index-thumb"></i></div>
                <div class="stat-number"><?= $receivedBids ?></div>
                <div class="stat-label">Bids Received</div>
            </div>
        </div>
    </section>

    <section class="section-card mb-4">
        <h2 class="section-title mb-3">Quick Actions</h2>
        <div class="row g-3">
            <div class="col-md-4">
                <a href="add-livestock.php" class="quick-action">
                    <i class="bi bi-plus-circle"></i>
                    <div>
                        <strong>Add Livestock</strong>
                        <small class="d-block text-muted">Create a livestock listing</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="create-auction.php" class="quick-action">
                    <i class="bi bi-hammer"></i>
                    <div>
                        <strong>Create Auction</strong>
                        <small class="d-block text-muted">Put available livestock up for auction</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="bids.php" class="quick-action">
                    <i class="bi bi-list-check"></i>
                    <div>
                        <strong>Review Bids</strong>
                        <small class="d-block text-muted">See bids placed on your auctions</small>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <section class="section-card mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="section-title mb-0">Recent Livestock</h2>
            <a href="livestock.php" class="btn btn-outline-success btn-sm">View All</a>
        </div>

        <?php if (empty($recentLivestock)): ?>
            <div class="text-center py-4">
                <i class="bi bi-box-seam fs-1 text-muted"></i>
                <h5 class="mt-2">No livestock listings yet</h5>
                <p class="text-muted">Add livestock to start offering it for auction.</p>
                <a href="add-livestock.php" class="btn btn-success">Add Livestock</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Livestock</th>
                            <th>Gender</th>
                            <th>Weight</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLivestock as $animal): ?>
                            <?php
                            $image = !empty($animal['image'])
                                ? '../uploads/livestock/' . $animal['image']
                                : '../assets/images/GANADO DOBLE PROPOSITO.png';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img
                                            src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars($animal['animal_type'], ENT_QUOTES, 'UTF-8') ?>"
                                            class="livestock-image"
                                        >
                                        <div>
                                            <strong><?= htmlspecialchars($animal['animal_type'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if (!empty($animal['breed'])): ?>
                                                <div class="text-muted-small"><?= htmlspecialchars($animal['breed'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($animal['gender']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $animal['weight'] !== null ? htmlspecialchars($animal['weight'], ENT_QUOTES, 'UTF-8') . ' kg' : '—' ?></td>
                                <td><?= htmlspecialchars($animal['location'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge <?= $animal['status'] === 'available' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= htmlspecialchars(ucfirst($animal['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit-livestock.php?id=<?= (int) $animal['id'] ?>" class="btn btn-outline-success btn-sm">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="section-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="section-title mb-0">Recent Auctions</h2>
            <a href="auctions.php" class="btn btn-outline-success btn-sm">View All</a>
        </div>

        <?php if (empty($recentAuctions)): ?>
            <div class="text-center py-4">
                <i class="bi bi-hammer fs-1 text-muted"></i>
                <h5 class="mt-2">No auctions created yet</h5>
                <p class="text-muted">Start an auction for one of your available livestock listings.</p>
                <a href="create-auction.php" class="btn btn-success">Create Auction</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Livestock</th>
                            <th>Starting Price</th>
                            <th>Highest Bid</th>
                            <th>End Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAuctions as $auction): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($auction['animal_type'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($auction['breed'])): ?>
                                        <div class="text-muted-small"><?= htmlspecialchars($auction['breed'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>KES <?= number_format((float) $auction['starting_price'], 2) ?></td>
                                <td>
                                    KES <?= number_format(
                                        $auction['highest_bid'] !== null
                                            ? (float) $auction['highest_bid']
                                            : (float) $auction['starting_price'],
                                        2
                                    ) ?>
                                </td>
                                <td><?= date('d M Y, H:i', strtotime($auction['end_time'])) ?></td>
                                <td>
                                    <span class="badge <?= $auction['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= htmlspecialchars(ucfirst($auction['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
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