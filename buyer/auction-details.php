<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$auctionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$auctionId || $auctionId < 1) {
    header('Location: auctions.php');
    exit;
}

$auctionStmt = $pdo->prepare(
    "SELECT
        a.id,
        a.farmer_id AS auction_farmer_id,
        a.starting_price,
        a.minimum_bid_increment,
        a.start_time,
        a.end_time,
        a.status,
        a.winner_id,
        a.winning_bid,
        l.farmer_id AS livestock_farmer_id,
        l.animal_type,
        l.breed,
        l.gender,
        l.age,
        l.weight,
        l.health_status,
        l.description,
        l.location,
        l.image,
        u.name AS farmer_name
     FROM auctions a
     INNER JOIN livestock l ON l.id = a.livestock_id
     INNER JOIN users u ON u.id = l.farmer_id
         WHERE a.id = ?
             AND l.moderation_status = 'approved'
     LIMIT 1"
);
$auctionStmt->execute([$auctionId]);
$auction = $auctionStmt->fetch();

if (!$auction) {
    $_SESSION['auction_error'] = 'Auction not found.';
    header('Location: auctions.php');
    exit;
}

$databaseNow = new DateTime((string) $pdo->query('SELECT NOW()')->fetchColumn());
$startTime = new DateTime($auction['start_time']);
$endTime = new DateTime($auction['end_time']);
$isAuctionOwner = $buyerId !== null && (
    (int) $auction['auction_farmer_id'] === $buyerId
    || (int) $auction['livestock_farmer_id'] === $buyerId
);
$canBid = !$isAuctionOwner
    && $auction['status'] === 'active'
    && $startTime <= $databaseNow
    && $endTime > $databaseNow;

if ($auction['status'] === 'completed') {
    $statusLabel = 'Completed';
    $statusClass = 'success';
} elseif ($auction['status'] === 'cancelled') {
    $statusLabel = 'Cancelled';
    $statusClass = 'secondary';
} elseif ($auction['status'] === 'suspended') {
    $statusLabel = 'Suspended';
    $statusClass = 'danger';
} elseif ($endTime <= $databaseNow) {
    $statusLabel = 'Ended';
    $statusClass = 'secondary';
} elseif ($startTime > $databaseNow) {
    $statusLabel = 'Scheduled';
    $statusClass = 'info';
} else {
    $statusLabel = 'Active';
    $statusClass = 'success';
}

$currentBidStmt = $pdo->prepare(
    'SELECT MAX(bid_amount) FROM bids WHERE auction_id = ?'
);
$currentBidStmt->execute([$auctionId]);
$highestBid = $currentBidStmt->fetchColumn();
$currentBid = $highestBid === null
    ? (float) $auction['starting_price']
    : (float) $highestBid;
$minimumBid = $currentBid + (float) $auction['minimum_bid_increment'];

$historyStmt = $pdo->prepare(
    'SELECT bid_amount, bid_time
     FROM bids
     WHERE auction_id = ?
     ORDER BY bid_time DESC, id DESC'
);
$historyStmt->execute([$auctionId]);
$bidHistory = $historyStmt->fetchAll();

$flashError = $_SESSION['auction_error'] ?? '';
$flashSuccess = $_SESSION['auction_success'] ?? '';
unset($_SESSION['auction_error'], $_SESSION['auction_success']);

$imageName = !empty($auction['image']) ? basename((string) $auction['image']) : '';
$imageUrl = $imageName !== ''
    ? '../uploads/livestock/' . rawurlencode($imageName)
    : '../assets/images/GANADO DOBLE PROPOSITO.png';
$countdownStart = $startTime->getTimestamp() * 1000;
$countdownEnd = $endTime->getTimestamp() * 1000;
$winnerIsBuyer = $auction['winner_id'] !== null
    && $buyerId !== null
    && (int) $auction['winner_id'] === $buyerId;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($auction['animal_type']) ?> Auction Details | Digital Livestock Auction</title>
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
        .topbar, .content-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .content-card { padding: 24px; height: 100%; }
        .auction-image {
            width: 100%; max-height: 480px; min-height: 280px; object-fit: cover;
            border-radius: 12px; background: #eef1f0;
        }
        .animal-title { font-size: 30px; font-weight: 700; }
        .current-bid { color: #12372A; font-size: 30px; font-weight: 800; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .meta-box { background: #f8faf8; border: 1px solid #edf0f2; border-radius: 10px; padding: 13px; }
        .meta-label {
            display: block; margin-bottom: 5px; color: #718096; font-size: 12px;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .meta-value { font-weight: 700; overflow-wrap: anywhere; }
        .countdown-box { background: #f7faf8; border: 1px solid #dfe9e2; border-radius: 10px; padding: 12px; }
        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }
        @media (max-width: 767px) {
            .sidebar { position: relative; width: 100%; min-height: auto; }
            .main { margin-left: 0; padding: 20px; }
        }
    </style>
    <link href="../assets/css/buyer.css" rel="stylesheet">
</head>
<body>
<?php require_once __DIR__ . '/../includes/buyer-nav.php'; ?>

<main class="main">
    <div class="topbar d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h3 class="mb-1">Auction Details</h3>
            <p class="text-muted mb-0">Review the livestock and auction before placing a bid.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="auctions.php" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i>All Auctions</a>
            <a href="my-bids.php" class="btn btn-outline-secondary"><i class="bi bi-hand-index-thumb me-1"></i>My Bids</a>
        </div>
    </div>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="content-card">
                <img class="auction-image mb-4" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($auction['animal_type']) ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h1 class="animal-title mb-1">
                            <?= htmlspecialchars($auction['animal_type']) ?>
                            <?php if (!empty($auction['breed'])): ?>
                                <small class="text-muted fs-5">- <?= htmlspecialchars($auction['breed']) ?></small>
                            <?php endif; ?>
                        </h1>
                        <div class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($auction['location'] ?: 'Location unavailable') ?></div>
                    </div>
                    <span class="badge text-bg-<?= $statusClass ?> fs-6"><?= htmlspecialchars($statusLabel) ?></span>
                </div>

                <hr>
                <h5 class="fw-bold">Livestock Information</h5>
                <div class="meta-grid">
                    <div class="meta-box"><span class="meta-label">Type</span><div class="meta-value"><?= htmlspecialchars($auction['animal_type']) ?></div></div>
                    <div class="meta-box"><span class="meta-label">Breed</span><div class="meta-value"><?= htmlspecialchars($auction['breed'] ?: 'Not specified') ?></div></div>
                    <div class="meta-box"><span class="meta-label">Gender</span><div class="meta-value"><?= htmlspecialchars(ucfirst($auction['gender'])) ?></div></div>
                    <div class="meta-box"><span class="meta-label">Age</span><div class="meta-value"><?= $auction['age'] !== null ? htmlspecialchars((string) $auction['age']) . ' months' : 'Not specified' ?></div></div>
                    <div class="meta-box"><span class="meta-label">Weight</span><div class="meta-value"><?= $auction['weight'] !== null ? htmlspecialchars((string) $auction['weight']) . ' kg' : 'Not specified' ?></div></div>
                    <div class="meta-box"><span class="meta-label">Health</span><div class="meta-value"><?= htmlspecialchars($auction['health_status'] ?: 'Not specified') ?></div></div>
                </div>

                <div class="mt-4">
                    <h5 class="fw-bold">Description</h5>
                    <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($auction['description'] ?: 'No description has been provided.')) ?></p>
                </div>

                <div class="mt-4 p-3 rounded bg-light">
                    <h5 class="fw-bold"><i class="bi bi-person-badge me-2"></i>Farmer Information</h5>
                    <div><?= htmlspecialchars($auction['farmer_name']) ?></div>
                    <small class="text-muted">Direct contact details are not displayed to protect seller privacy.</small>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="content-card mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="fw-bold mb-0">Auction Information</h4>
                    <span class="text-muted">#<?= (int) $auction['id'] ?></span>
                </div>
                <div class="mt-3">
                    <span class="meta-label">Current Highest Bid</span>
                    <div class="current-bid">KES <?= number_format($currentBid, 2) ?></div>
                </div>
                <div class="meta-grid mt-3">
                    <div class="meta-box"><span class="meta-label">Starting Price</span><div class="meta-value">KES <?= number_format((float) $auction['starting_price'], 2) ?></div></div>
                    <div class="meta-box"><span class="meta-label">Minimum Increment</span><div class="meta-value">KES <?= number_format((float) $auction['minimum_bid_increment'], 2) ?></div></div>
                    <div class="meta-box"><span class="meta-label">Starts</span><div class="meta-value"><?= htmlspecialchars($startTime->format('d M Y, H:i')) ?></div></div>
                    <div class="meta-box"><span class="meta-label">Closes</span><div class="meta-value"><?= htmlspecialchars($endTime->format('d M Y, H:i')) ?></div></div>
                </div>

                <?php if ($statusLabel === 'Active'): ?>
                    <div class="countdown-box mt-3" data-start-time="<?= $countdownStart ?>" data-end-time="<?= $countdownEnd ?>">
                        <i class="bi bi-clock-history me-2"></i><span class="countdown-text">Calculating time remaining...</span>
                    </div>
                <?php elseif ($statusLabel === 'Scheduled'): ?>
                    <div class="countdown-box mt-3" data-start-time="<?= $countdownStart ?>" data-end-time="<?= $countdownEnd ?>">
                        <i class="bi bi-clock-history me-2"></i><span class="countdown-text">Calculating start time...</span>
                    </div>
                <?php endif; ?>

                <?php if ($isAuctionOwner): ?>
                    <div class="alert alert-warning mt-3 mb-0">You cannot place bids on your own auction.</div>
                <?php elseif ($canBid): ?>
                    <form method="POST" action="../auction/place-bid.php" class="mt-4 bid-form">
                        <input type="hidden" name="auction_id" value="<?= (int) $auction['id'] ?>">
                        <label for="bid_amount" class="form-label fw-semibold">Your Bid (KES)</label>
                        <input
                            type="number"
                            id="bid_amount"
                            name="bid_amount"
                            class="form-control form-control-lg"
                            min="<?= number_format($minimumBid, 2, '.', '') ?>"
                            step="0.01"
                            placeholder="At least <?= number_format($minimumBid, 2) ?>"
                            required
                        >
                        <div class="form-text">Your bid must be at least KES <?= number_format($minimumBid, 2) ?>.</div>
                        <button type="submit" class="btn btn-success btn-lg w-100 mt-3">
                            <i class="bi bi-hammer me-1"></i>Place Bid
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($statusLabel === 'Completed' && $auction['winner_id'] !== null && $auction['winning_bid'] !== null): ?>
                    <div class="alert alert-success mt-3 mb-0">
                        <h5 class="alert-heading"><i class="bi bi-trophy me-1"></i>Auction Result</h5>
                        <?php if ($winnerIsBuyer): ?>
                            You won this auction.
                        <?php else: ?>
                            A winning bid was recorded. Buyer details are private.
                        <?php endif; ?>
                        <div class="fw-bold mt-1">Winning bid: KES <?= number_format((float) $auction['winning_bid'], 2) ?></div>
                    </div>
                <?php elseif ($statusLabel === 'Ended'): ?>
                    <div class="alert alert-secondary mt-3 mb-0">This auction has ended. No completed winner result is recorded.</div>
                <?php endif; ?>
            </section>

            <section class="content-card">
                <h4 class="fw-bold mb-3">Bid History</h4>
                <?php if (empty($bidHistory)): ?>
                    <p class="text-muted mb-0">No bids have been placed yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Amount</th><th>Time</th></tr></thead>
                            <tbody>
                            <?php foreach ($bidHistory as $bid): ?>
                                <?php $bidTime = new DateTime((string) $bid['bid_time']); ?>
                                <tr>
                                    <td class="fw-semibold">KES <?= number_format((float) $bid['bid_amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($bidTime->format('d M Y, H:i')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">Bidder identities are not shown.</small>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<script src="../assets/js/auction.js"></script>
<script src="../assets/js/bidding.js" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
