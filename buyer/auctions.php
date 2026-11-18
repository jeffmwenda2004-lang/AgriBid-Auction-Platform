<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$buyerName = getUserName();

$flashError = $_SESSION['auction_error'] ?? '';
$flashSuccess = $_SESSION['auction_success'] ?? '';
unset($_SESSION['auction_error'], $_SESSION['auction_success']);

$auctionSql = "
    SELECT
        a.id,
        a.livestock_id,
        a.starting_price,
        a.minimum_bid_increment,
        a.start_time,
        a.end_time,
        a.status,
        l.animal_type,
        l.breed,
        l.weight,
        l.location,
        l.health_status,
        l.image,
        COALESCE(MAX(b.bid_amount), a.starting_price) AS current_bid
    FROM auctions a
    INNER JOIN livestock l ON l.id = a.livestock_id
    LEFT JOIN bids b ON b.auction_id = a.id
    WHERE a.status IN ('pending', 'active')
      AND l.moderation_status = 'approved'
      AND a.end_time > NOW()
    GROUP BY a.id, a.livestock_id, a.starting_price, a.minimum_bid_increment, a.start_time, a.end_time, a.status,
             l.animal_type, l.breed, l.weight, l.location, l.health_status, l.image
    ORDER BY a.end_time ASC
";

$auctions = $pdo->query($auctionSql)->fetchAll();
$databaseNow = new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());

$buyerBidMap = [];
if (!empty($auctions)) {
    $ids = array_map(static fn ($auction) => (int) $auction['id'], $auctions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $buyerBidStmt = $pdo->prepare(
        "SELECT auction_id, MAX(bid_amount) AS buyer_highest_bid
         FROM bids
         WHERE buyer_id = ?
           AND auction_id IN ($placeholders)
         GROUP BY auction_id"
    );

    $buyerBidStmt->execute([$buyerId, ...$ids]);

    while ($row = $buyerBidStmt->fetch()) {
        $buyerBidMap[(int) $row['auction_id']] = (float) $row['buyer_highest_bid'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Auctions | Digital Livestock Auction</title>
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

        .welcome h3 {
            margin: 0;
            font-weight: 700;
        }

        .welcome p {
            margin: 5px 0 0;
            color: #718096;
        }

        .auction-card {
            background: #fff;
            border-radius: 18px;
            border: 1px solid #edf0f2;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            height: 100%;
        }

        .auction-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            background: #eef1f0;
        }

        .auction-body {
            padding: 20px;
        }

        .auction-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 15px;
        }

        .meta-pill {
            background: #edf7f1;
            color: #12372A;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .current-bid {
            font-size: 28px;
            font-weight: 800;
            color: #12372A;
            margin-bottom: 8px;
        }

        .countdown-box {
            border: 1px solid #dfe9e2;
            background: #f7faf8;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 15px;
            font-weight: 600;
            color: #12372A;
        }

        .bid-form {
            border-top: 1px solid #eef2ef;
            padding-top: 16px;
            margin-top: 16px;
        }

        .bid-input {
            min-height: 46px;
        }

        .status-winning {
            color: #198754;
            font-weight: 700;
        }

        .status-outbid {
            color: #dc3545;
            font-weight: 700;
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
    <div class="topbar">
        <div class="welcome">
            <h3>Available Auctions, <?= htmlspecialchars($buyerName ?? 'Buyer') ?> 👋</h3>
            <p>Browse approved auctions. Bidding opens at each auction’s scheduled start time.</p>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle me-2"></i>
            <?= htmlspecialchars($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <?= htmlspecialchars($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($auctions)): ?>
        <div class="text-center py-5">
            <i class="bi bi-hammer fs-1 text-muted"></i>
            <h4 class="mt-3">No approved auctions are available</h4>
            <p class="text-muted mb-0">Farmer submissions appear here after administrator approval and remain visible until they end.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($auctions as $auction): ?>
                <?php
                $currentBid = (float) $auction['current_bid'];
                $minimumBid = $currentBid + (float) $auction['minimum_bid_increment'];
                $buyerHighest = $buyerBidMap[(int) $auction['id']] ?? 0.0;
                $auctionStart = new DateTimeImmutable((string) $auction['start_time']);
                $auctionEnd = new DateTimeImmutable((string) $auction['end_time']);
                $isScheduled = $auctionStart > $databaseNow;
                $statusLabel = null;

                if ($buyerHighest > 0) {
                    $statusLabel = $buyerHighest >= $currentBid ? 'winning' : 'outbid';
                }
                ?>

                <div class="col-lg-6" id="auction-<?= (int) $auction['id'] ?>">
                    <div class="auction-card">
                        <img
                            src="<?= !empty($auction['image']) ? '../uploads/livestock/' . htmlspecialchars($auction['image']) : '../assets/images/GANADO DOBLE PROPOSITO.png' ?>"
                            alt="<?= htmlspecialchars($auction['animal_type']) ?>"
                            class="auction-image"
                        >

                        <div class="auction-body">
                            <div class="auction-title">
                                <?= htmlspecialchars($auction['animal_type']) ?>
                                <?php if (!empty($auction['breed'])): ?>
                                    <small class="text-muted">- <?= htmlspecialchars($auction['breed']) ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="meta-row">
                                <span class="meta-pill"><?= htmlspecialchars(ucfirst($auction['health_status'] ?? 'Healthy')) ?></span>
                                <span class="meta-pill"><?= htmlspecialchars($auction['weight'] ?? 'N/A') ?> kg</span>
                                <span class="meta-pill"><?= htmlspecialchars($auction['location'] ?? 'Location unavailable') ?></span>
                            </div>

                            <div class="current-bid">
                                KES <?= number_format($currentBid, 2) ?>
                            </div>

                            <div class="text-muted small mb-2">
                                Starting price: KES <?= number_format((float) $auction['starting_price'], 2) ?>
                            </div>

                            <div class="text-muted small mb-2">
                                Minimum bid increment: KES <?= number_format((float) $auction['minimum_bid_increment'], 2) ?>
                            </div>

                            <?php if ($isScheduled): ?>
                                <span class="badge text-bg-info mb-2">Scheduled · starts <?= htmlspecialchars($auctionStart->format('d M Y, H:i')) ?></span>
                            <?php endif; ?>

                            <div class="countdown-box" data-start-time="<?= htmlspecialchars($auctionStart->format(DATE_ATOM)) ?>" data-end-time="<?= htmlspecialchars($auctionEnd->format(DATE_ATOM)) ?>">
                                <i class="bi bi-clock-history me-2"></i>
                                <span class="countdown-text">Loading countdown...</span>
                            </div>

                            <?php if ($statusLabel === 'winning'): ?>
                                <div class="status-winning mb-2">
                                    <i class="bi bi-trophy me-1"></i>
                                    You are currently winning this auction.
                                </div>
                            <?php elseif ($statusLabel === 'outbid'): ?>
                                <div class="status-outbid mb-2">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    You have been outbid.
                                </div>
                            <?php endif; ?>

                            <a href="auction-details.php?id=<?= (int) $auction['id'] ?>" class="btn btn-outline-success w-100 mb-2">
                                <i class="bi bi-info-circle me-1"></i>View Full Auction Details
                            </a>

                            <?php if ($isScheduled): ?>
                                <div class="alert alert-info py-2 mb-0">Bidding opens at the scheduled start time.</div>
                            <?php else: ?>
                            <form method="POST" action="../auction/place-bid.php" class="bid-form">
                                <input type="hidden" name="auction_id" value="<?= (int) $auction['id'] ?>">

                                <div class="row g-2 align-items-center">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-semibold">Your Bid</label>
                                        <input
                                            type="number"
                                            name="bid_amount"
                                            class="form-control bid-input"
                                            min="<?= number_format($minimumBid, 2, '.', '') ?>"
                                            step="0.01"
                                            placeholder="KES <?= number_format($minimumBid, 2) ?> or higher"
                                            required
                                        >
                                    </div>

                                    <div class="col-md-4 d-grid">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            Place Bid
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/auction.js"></script>
<script src="../assets/js/bidding.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
