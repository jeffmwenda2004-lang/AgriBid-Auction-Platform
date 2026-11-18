<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$buyerName = getUserName();

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.bid_amount,
        b.bid_time,
        a.id AS auction_id,
        a.starting_price,
        a.minimum_bid_increment,
        a.end_time,
        a.status AS auction_status,
        a.winner_id,
        COALESCE((
            SELECT MAX(all_bids.bid_amount)
            FROM bids all_bids
            WHERE all_bids.auction_id = a.id
        ), a.starting_price) AS current_bid,
        COALESCE((
            SELECT MAX(own_bids.bid_amount)
            FROM bids own_bids
            WHERE own_bids.auction_id = a.id
              AND own_bids.buyer_id = ?
        ), 0) AS buyer_highest_bid,
        l.animal_type,
        l.breed,
        l.weight,
        l.location,
        l.image
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    INNER JOIN livestock l ON l.id = a.livestock_id
    WHERE b.buyer_id = ?
    ORDER BY b.bid_time DESC, b.id DESC
");
$stmt->execute([$buyerId, $buyerId]);
$bids = $stmt->fetchAll();

$activeAuctionIds = [];
$wonAuctionIds = [];
foreach ($bids as $bid) {
    if (
        $bid['auction_status'] === 'active'
        && strtotime($bid['end_time']) > time()
    ) {
        $activeAuctionIds[(int) $bid['auction_id']] = true;
    }

    if (
        $bid['auction_status'] === 'completed'
        && (int) $bid['winner_id'] === (int) $buyerId
    ) {
        $wonAuctionIds[(int) $bid['auction_id']] = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bids | Digital Livestock Auction</title>
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
        .topbar, .content-card, .stat-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h3 { margin: 0; font-weight: 700; }
        .topbar p { margin: 5px 0 0; color: #718096; }
        .stat-card { padding: 20px; height: 100%; }
        .stat-number { color: #12372A; font-size: 28px; font-weight: 700; }
        .stat-label, .text-muted-small { color: #718096; font-size: 13px; }
        .content-card { padding: 22px; }
        .bid-livestock { display: flex; align-items: center; gap: 12px; min-width: 210px; }
        .bid-image { width: 58px; height: 58px; object-fit: cover; border-radius: 10px; background: #eef1f0; }
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
    <div class="topbar">
        <h3>My Bids, <?= htmlspecialchars($buyerName ?? 'Buyer') ?> 👋</h3>
        <p>Review your bidding activity and the current status of each auction.</p>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-number"><?= count($bids) ?></div>
                <div class="stat-label">Total bids placed</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-number"><?= count($activeAuctionIds) ?></div>
                <div class="stat-label">Auctions still active</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-number"><?= count($wonAuctionIds) ?></div>
                <div class="stat-label">Auctions won</div>
            </div>
        </div>
    </div>

    <section class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Bid History</h4>
            <a href="auctions.php" class="btn btn-success btn-sm">
                <i class="bi bi-hammer me-1"></i> Browse Auctions
            </a>
        </div>

        <?php if (empty($bids)): ?>
            <div class="text-center py-5">
                <i class="bi bi-hand-index-thumb fs-1 text-muted"></i>
                <h5 class="mt-3">You haven't placed any bids yet</h5>
                <p class="text-muted">Browse active auctions to find livestock to bid on.</p>
                <a href="auctions.php" class="btn btn-success">View Active Auctions</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Livestock</th>
                            <th>Your Bid</th>
                            <th>Current Bid</th>
                            <th>Weight / Location</th>
                            <th>Bid Time</th>
                            <th>Auction Ends</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                        <?php foreach ($bids as $bid): ?>
                            <?php
                            $auctionEnded = strtotime($bid['end_time']) <= time()
                                || $bid['auction_status'] === 'completed';
                            $isWinner = $bid['auction_status'] === 'completed'
                                && (int) $bid['winner_id'] === (int) $buyerId;
                            $isLeading = !$auctionEnded
                                && (float) $bid['buyer_highest_bid'] >= (float) $bid['current_bid'];

                            if ($isWinner) {
                                $statusText = 'Won';
                                $statusClass = 'bg-success';
                            } elseif ($auctionEnded) {
                                $statusText = 'Ended';
                                $statusClass = 'bg-secondary';
                            } elseif ($isLeading) {
                                $statusText = 'Winning';
                                $statusClass = 'bg-success';
                            } else {
                                $statusText = 'Outbid';
                                $statusClass = 'bg-danger';
                            }

                            $image = !empty($bid['image'])
                                ? '../uploads/livestock/' . $bid['image']
                                : '../assets/images/GANADO DOBLE PROPOSITO.png';
                            ?>
                            <tr>
                                <td>
                                    <div class="bid-livestock">
                                        <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars($bid['animal_type']) ?>" class="bid-image">
                                        <div>
                                            <strong><?= htmlspecialchars($bid['animal_type']) ?></strong>
                                            <?php if (!empty($bid['breed'])): ?>
                                                <div class="text-muted-small"><?= htmlspecialchars($bid['breed']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="fw-semibold">KES <?= number_format((float) $bid['bid_amount'], 2) ?></td>
                                <td>
                                    <div class="fw-semibold">KES <?= number_format((float) $bid['current_bid'], 2) ?></div>
                                    <div class="text-muted-small">Min. increment: KES <?= number_format((float) $bid['minimum_bid_increment'], 2) ?></div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($bid['weight'] !== null ? $bid['weight'] . ' kg' : 'Weight unavailable') ?></div>
                                    <div class="text-muted-small"><?= htmlspecialchars($bid['location'] ?? 'Location unavailable') ?></div>
                                </td>
                                <td><?= date('d M Y, H:i', strtotime($bid['bid_time'])) ?></td>
                                <td><?= date('d M Y, H:i', strtotime($bid['end_time'])) ?></td>
                                <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                <td>
                                    <?php if ($isWinner): ?>
                                        <a href="../auction/winner.php?auction_id=<?= (int) $bid['auction_id'] ?>" class="btn btn-success btn-sm">View win</a>
                                    <?php elseif (!$auctionEnded && $bid['auction_status'] === 'active'): ?>
                                        <a href="auctions.php#auction-<?= (int) $bid['auction_id'] ?>" class="btn btn-outline-success btn-sm">Place Bid</a>
                                    <?php else: ?>
                                        <span class="text-muted-small">Closed</span>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>