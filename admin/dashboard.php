<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminName = getUserName();

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'farmer') AS total_farmers,
        (SELECT COUNT(*) FROM users WHERE role = 'buyer') AS total_buyers,
        (SELECT COUNT(*) FROM livestock) AS total_livestock,
        (
            SELECT COUNT(*)
            FROM auctions
            WHERE status = 'active'
              AND start_time <= NOW()
              AND end_time > NOW()
        ) AS active_auctions,
        (
            SELECT COUNT(*)
            FROM auctions
            WHERE status = 'completed'
               OR (status = 'active' AND end_time <= NOW())
        ) AS completed_auctions,
        (SELECT COUNT(*) FROM bids) AS total_bids"
)->fetch();

$registrationStmt = $pdo->query(
    "SELECT id, name, email, role, status, created_at
     FROM users
     WHERE role IN ('farmer', 'buyer')
     ORDER BY created_at DESC, id DESC
     LIMIT 8"
);
$recentRegistrations = $registrationStmt->fetchAll();

$auctionStmt = $pdo->query(
    "SELECT
        a.id,
        a.starting_price,
        a.start_time,
        a.end_time,
        a.status,
        a.created_at,
        l.animal_type,
        l.breed,
        u.name AS farmer_name
     FROM auctions a
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users u ON u.id = a.farmer_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 8"
);
$recentAuctions = $auctionStmt->fetchAll();

function formatAuctionStatus(array $auction): array
{
    if ($auction['status'] === 'completed') {
        return ['Completed', 'success'];
    }

    if ($auction['status'] === 'cancelled') {
        return ['Cancelled', 'secondary'];
    }

    if ($auction['status'] === 'suspended') {
        return ['Suspended', 'danger'];
    }

    if ($auction['status'] === 'pending') {
        return ['Pending', 'warning'];
    }

    $now = time();
    if (strtotime($auction['end_time']) <= $now) {
        return ['Ended', 'secondary'];
    }

    if (strtotime($auction['start_time']) > $now) {
        return ['Scheduled', 'info'];
    }

    if ($auction['status'] === 'active') {
        return ['Active', 'success'];
    }

    return [ucfirst((string) $auction['status']), 'secondary'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Digital Livestock Auction</title>
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
        .topbar, .stat-card, .content-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h1 { font-size: 25px; font-weight: 700; margin: 0; }
        .topbar p { color: #718096; margin: 5px 0 0; }
        .stat-card { height: 100%; padding: 20px; }
        .stat-heading { color: #718096; font-size: 14px; }
        .stat-number { color: #12372A; font-size: 30px; font-weight: 800; margin-top: 7px; }
        .stat-icon {
            display: grid; width: 44px; height: 44px; place-items: center;
            border-radius: 12px; background: #edf7f1; color: #12372A; font-size: 20px;
        }
        .content-card { padding: 22px; height: 100%; }
        .content-card h2 { font-size: 19px; font-weight: 700; }
        .quick-action {
            display: flex; align-items: center; gap: 12px; height: 100%;
            padding: 16px; border: 1px solid #e8eeea; border-radius: 12px;
            color: #263238; text-decoration: none; transition: .2s;
        }
        .quick-action:hover { border-color: #12372A; background: #f7faf8; color: #12372A; }
        .quick-action i { color: #12372A; font-size: 21px; }
        .quick-action span { font-weight: 600; }
        .table td, .table th { vertical-align: middle; }
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
        <h1>Admin Dashboard</h1>
        <p>Welcome, <?= htmlspecialchars($adminName ?? 'Administrator') ?>. Here's the latest platform activity.</p>
    </header>

    <section class="row g-3 mb-4" aria-label="Platform statistics">
        <?php
        $statCards = [
            ['Total Users', 'total_users', 'bi-people'],
            ['Total Farmers', 'total_farmers', 'bi-person-workspace'],
            ['Total Buyers', 'total_buyers', 'bi-person-check'],
            ['Total Livestock', 'total_livestock', 'bi-box-seam'],
            ['Active Auctions', 'active_auctions', 'bi-hammer'],
            ['Completed Auctions', 'completed_auctions', 'bi-check2-circle'],
            ['Total Bids', 'total_bids', 'bi-hand-index-thumb'],
        ];
        foreach ($statCards as [$label, $key, $icon]):
        ?>
            <div class="col-sm-6 col-xl-3">
                <article class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-heading"><?= htmlspecialchars($label) ?></div>
                            <div class="stat-number"><?= number_format((int) $stats[$key]) ?></div>
                        </div>
                        <div class="stat-icon"><i class="bi <?= htmlspecialchars($icon) ?>"></i></div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="content-card mb-4">
        <h2 class="mb-3">Quick Actions</h2>
        <div class="row g-3">
            <?php
            $actions = [
                ['Manage Users', 'users.php', 'bi-people'],
                ['Manage Livestock', 'livestock.php', 'bi-box-seam'],
                ['Manage Auctions', 'auctions.php', 'bi-hammer'],
                ['View Bids', 'bids.php', 'bi-hand-index-thumb'],
                ['Generate Reports', 'reports.php', 'bi-bar-chart'],
            ];
            foreach ($actions as [$label, $url, $icon]):
            ?>
                <div class="col-sm-6 col-lg">
                    <a class="quick-action" href="<?= htmlspecialchars($url) ?>">
                        <i class="bi <?= htmlspecialchars($icon) ?>"></i>
                        <span><?= htmlspecialchars($label) ?></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-xl-6">
            <section class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">Recent Registrations</h2>
                    <a href="users.php" class="btn btn-sm btn-outline-success">Manage users</a>
                </div>
                <?php if (empty($recentRegistrations)): ?>
                    <p class="text-muted mb-0">No farmer or buyer accounts have registered yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Name</th><th>Role</th><th>Registered</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentRegistrations as $user): ?>
                                <?php $registrationDate = new DateTime((string) $user['created_at']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($user['name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars(ucfirst($user['role'])) ?></td>
                                    <td><?= htmlspecialchars($registrationDate->format('d M Y')) ?></td>
                                    <td><span class="badge text-bg-<?= $user['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($user['status'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">Recent Auctions</h2>
                    <a href="auctions.php" class="btn btn-sm btn-outline-success">Manage auctions</a>
                </div>
                <?php if (empty($recentAuctions)): ?>
                    <p class="text-muted mb-0">No auctions have been created yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Livestock</th><th>Farmer</th><th>Starting Price</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentAuctions as $auction): ?>
                                <?php [$statusLabel, $statusColor] = formatAuctionStatus($auction); ?>
                                <?php $auctionCreated = new DateTime((string) $auction['created_at']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($auction['animal_type']) ?><?= !empty($auction['breed']) ? ' - ' . htmlspecialchars($auction['breed']) : '' ?></div>
                                        <small class="text-muted">Created <?= htmlspecialchars($auctionCreated->format('d M Y')) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($auction['farmer_name']) ?></td>
                                    <td class="text-nowrap">KES <?= number_format((float) $auction['starting_price'], 2) ?></td>
                                    <td><span class="badge text-bg-<?= $statusColor ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
