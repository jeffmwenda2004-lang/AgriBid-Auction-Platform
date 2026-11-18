<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$livestockId = (int) ($_GET['id'] ?? 0);

if ($livestockId <= 0) {
    header('Location: livestock.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT l.*, u.name AS farmer_name
     FROM livestock l
     INNER JOIN users u ON u.id = l.farmer_id
     WHERE l.id = ? AND l.status = 'available' AND l.moderation_status = 'approved'
     LIMIT 1"
);
$stmt->execute([$livestockId]);
$livestock = $stmt->fetch();

if (!$livestock) {
    header('Location: livestock.php');
    exit;
}

$auctionStmt = $pdo->prepare(
    "SELECT id, starting_price, minimum_bid_increment, start_time, end_time, status
     FROM auctions
     WHERE livestock_id = ? AND status = 'active'
     ORDER BY start_time DESC
     LIMIT 1"
);
$auctionStmt->execute([$livestockId]);
$auction = $auctionStmt->fetch();

$buyerName = getUserName();
$image = !empty($livestock['image'])
    ? '../uploads/livestock/' . $livestock['image']
    : '../assets/images/GANADO DOBLE PROPOSITO.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($livestock['animal_type']) ?> Details | Digital Livestock Auction</title>
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
            color: #ffffff;
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
            color: #ffffff;
        }

        .sidebar .logout {
            margin-top: 30px;
            color: #ffcccc;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar {
            background: #ffffff;
            border-radius: 14px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .detail-card,
        .info-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .detail-image {
            width: 100%;
            height: 420px;
            object-fit: cover;
            background: #eef1f0;
        }

        .info-card {
            padding: 24px;
        }

        .animal-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .price-tag {
            font-size: 30px;
            font-weight: 800;
            color: #12372A;
            margin: 12px 0;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .meta-box {
            background: #f8faf8;
            border: 1px solid #edf0f2;
            border-radius: 12px;
            padding: 14px;
        }

        .meta-label {
            display: block;
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .meta-value {
            font-weight: 700;
            color: #263238;
        }

        @media (max-width: 991px) {
            .sidebar {
                width: 220px;
            }

            .main {
                margin-left: 220px;
            }
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
    <link href="../assets/css/buyer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/buyer-nav.php'; ?>

<div class="main">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div class="welcome">
            <h3>Livestock Details</h3>
            <p>Review the animal profile and auction status before placing a bid.</p>
        </div>

        <a href="livestock.php" class="btn btn-outline-success">
            <i class="bi bi-arrow-left"></i> Back to Browse
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="detail-card">
                <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($livestock['animal_type']) ?>" class="detail-image">
            </div>
        </div>

        <div class="col-lg-6">
            <div class="info-card">
                <div class="animal-title">
                    <?= htmlspecialchars($livestock['animal_type']) ?>
                    <?php if (!empty($livestock['breed'])): ?>
                        <small class="text-muted">- <?= htmlspecialchars($livestock['breed']) ?></small>
                    <?php endif; ?>
                </div>

                <div class="text-muted">
                    <i class="bi bi-person-badge"></i>
                    Farmer: <?= htmlspecialchars($livestock['farmer_name']) ?>
                </div>

                <?php if ($auction): ?>
                    <div class="price-tag">
                        KES <?= number_format((float) $auction['starting_price'], 2) ?>
                    </div>

                    <div class="alert alert-success mb-3">
                        <i class="bi bi-currency-dollar"></i>
                        Auction status: <?= htmlspecialchars(ucfirst($auction['status'])) ?>
                    </div>
                <?php else: ?>
                    <div class="price-tag">Not yet in active auction</div>
                <?php endif; ?>

                <div class="meta-grid">
                    <div class="meta-box">
                        <span class="meta-label">Gender</span>
                        <div class="meta-value"><?= htmlspecialchars(ucfirst($livestock['gender'])) ?></div>
                    </div>

                    <div class="meta-box">
                        <span class="meta-label">Age</span>
                        <div class="meta-value"><?= htmlspecialchars((string) ($livestock['age'] ?? 'N/A')) ?> months</div>
                    </div>

                    <div class="meta-box">
                        <span class="meta-label">Weight</span>
                        <div class="meta-value"><?= htmlspecialchars((string) ($livestock['weight'] ?? 'N/A')) ?> kg</div>
                    </div>

                    <div class="meta-box">
                        <span class="meta-label">Health</span>
                        <div class="meta-value"><?= htmlspecialchars($livestock['health_status'] ?? 'Not specified') ?></div>
                    </div>

                    <div class="meta-box">
                        <span class="meta-label">Location</span>
                        <div class="meta-value"><?= htmlspecialchars($livestock['location'] ?? 'Location unavailable') ?></div>
                    </div>

                    <div class="meta-box">
                        <span class="meta-label">Auction Window</span>
                        <div class="meta-value">
                            <?php if ($auction): ?>
                                <?= date('d M Y, H:i', strtotime($auction['start_time'])) ?>
                                <div class="small text-muted">to <?= date('d M Y, H:i', strtotime($auction['end_time'])) ?></div>
                            <?php else: ?>
                                Pending
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <h5 class="fw-bold">Description</h5>
                    <p class="mb-0 text-muted">
                        <?= nl2br(htmlspecialchars($livestock['description'] ?? 'No detailed description has been provided for this animal yet.')) ?>
                    </p>
                </div>

                <?php if ($auction): ?>
                    <div class="mt-4 d-grid gap-2">
                        <a href="auctions.php?id=<?= (int) $auction['id'] ?>" class="btn btn-success btn-lg">
                            <i class="bi bi-hammer"></i> View Auction
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
