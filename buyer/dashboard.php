<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$buyerName = getUserName();

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

// Active auctions
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM auctions
    WHERE status = 'active'
");
$stmt->execute();
$activeAuctions = (int) $stmt->fetchColumn();

// Total bids by this buyer
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bids
    WHERE buyer_id = ?
");
$stmt->execute([$buyerId]);
$totalBids = (int) $stmt->fetchColumn();

// Auctions won
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE winner_id = ?
");
$stmt->execute([$buyerId]);
$auctionsWon = (int) $stmt->fetchColumn();

// Active bids
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    WHERE b.buyer_id = ?
    AND a.status = 'active'
");
$stmt->execute([$buyerId]);
$activeBids = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Recent Bids
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        b.id,
        b.bid_amount,
        b.bid_time,
        b.status,
        a.id AS auction_id,
        a.starting_price,
        a.end_time,
        l.animal_type,
        l.breed,
        l.image
    FROM bids b
    INNER JOIN auctions a ON a.id = b.auction_id
    INNER JOIN livestock l ON l.id = a.livestock_id
    WHERE b.buyer_id = ?
    ORDER BY b.bid_time DESC
    LIMIT 5
");

$stmt->execute([$buyerId]);
$recentBids = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Active Auctions
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        a.id,
        a.starting_price,
        a.minimum_bid_increment,
        a.start_time,
        a.end_time,
        l.animal_type,
        l.breed,
        l.weight,
        l.location,
        l.image,

        (
            SELECT MAX(b.bid_amount)
            FROM bids b
            WHERE b.auction_id = a.id
        ) AS current_bid

    FROM auctions a
    INNER JOIN livestock l ON l.id = a.livestock_id
    WHERE a.status = 'active'
    ORDER BY a.end_time ASC
    LIMIT 6
");

$activeAuctionList = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Buyer Dashboard | Digital Livestock Auction</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

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

        .stat-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 22px;
            height: 100%;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
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

        .stat-label {
            color: #718096;
            font-size: 14px;
        }

        .section-card {
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            padding: 22px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
        }

        .auction-card {
            border: 1px solid #edf0f2;
            border-radius: 12px;
            overflow: hidden;
            height: 100%;
            transition: 0.2s;
        }

        .auction-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .auction-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #eef1f0;
        }

        .auction-body {
            padding: 16px;
        }

        .auction-title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .auction-meta {
            color: #718096;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .current-bid {
            font-size: 19px;
            font-weight: 700;
            color: #12372A;
        }

        .bid-table td {
            vertical-align: middle;
        }

        .badge-active {
            background: #e6f1ec;
            color: #12372A;
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
        }

        .quick-action:hover {
            background: #f7faf8;
            border-color: #cbded4;
        }

        .quick-action i {
            font-size: 20px;
            color: #12372A;
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

</head>

<body>

<?php require_once __DIR__ . '/../includes/buyer-nav.php'; ?>


<!-- Main Content -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">

        <div class="welcome">
            <h3>
                Welcome back, <?= htmlspecialchars($buyerName) ?> 👋
            </h3>

            <p>
                Discover livestock and participate in live auctions.
            </p>
        </div>

    </div>


    <!-- Statistics -->
    <div class="row g-4 mb-4">

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hammer"></i>
                </div>

                <div class="stat-number">
                    <?= $activeAuctions ?>
                </div>

                <div class="stat-label">
                    Active Auctions
                </div>

            </div>
        </div>


        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-hand-index-thumb"></i>
                </div>

                <div class="stat-number">
                    <?= $totalBids ?>
                </div>

                <div class="stat-label">
                    Total Bids
                </div>

            </div>
        </div>


        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-clock-history"></i>
                </div>

                <div class="stat-number">
                    <?= $activeBids ?>
                </div>

                <div class="stat-label">
                    Active Bids
                </div>

            </div>
        </div>


        <div class="col-md-6 col-xl-3">
            <div class="stat-card">

                <div class="stat-icon">
                    <i class="bi bi-trophy"></i>
                </div>

                <div class="stat-number">
                    <?= $auctionsWon ?>
                </div>

                <div class="stat-label">
                    Auctions Won
                </div>

            </div>
        </div>

    </div>


    <!-- Quick Actions -->
    <div class="section-card mb-4">

        <div class="section-title mb-3">
            Quick Actions
        </div>

        <div class="row g-3">

            <div class="col-md-4">

                <a href="livestock.php" class="quick-action">

                    <i class="bi bi-search"></i>

                    <div>
                        <strong>Browse Livestock</strong>
                        <small class="d-block text-muted">
                            Find animals available for auction
                        </small>
                    </div>

                </a>

            </div>


            <div class="col-md-4">

                <a href="auctions.php" class="quick-action">

                    <i class="bi bi-hammer"></i>

                    <div>
                        <strong>View Auctions</strong>
                        <small class="d-block text-muted">
                            Explore active livestock auctions
                        </small>
                    </div>

                </a>

            </div>


            <div class="col-md-4">

                <a href="my-bids.php" class="quick-action">

                    <i class="bi bi-list-check"></i>

                    <div>
                        <strong>My Bids</strong>
                        <small class="d-block text-muted">
                            Track your bidding activity
                        </small>
                    </div>

                </a>

            </div>

        </div>

    </div>


    <!-- Active Auctions -->
    <div class="section-card mb-4">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div class="section-title">
                Active Auctions
            </div>

            <a href="auctions.php" class="btn btn-sm btn-outline-success">
                View All
            </a>

        </div>


        <div class="row g-4">

            <?php if (empty($activeAuctionList)): ?>

                <div class="col-12">

                    <div class="text-center py-5">

                        <i class="bi bi-hammer fs-1 text-muted"></i>

                        <h5 class="mt-3">
                            No active auctions
                        </h5>

                        <p class="text-muted">
                            There are currently no livestock auctions available.
                        </p>

                    </div>

                </div>

            <?php else: ?>

                <?php foreach ($activeAuctionList as $auction): ?>

                    <?php

                    $currentBid = $auction['current_bid']
                        ? (float) $auction['current_bid']
                        : (float) $auction['starting_price'];

                    $image = !empty($auction['image'])
                        ? '../uploads/livestock/' . $auction['image']
                        : '../assets/images/GANADO DOBLE PROPOSITO.png';

                    ?>

                    <div class="col-md-6 col-xl-4">

                        <div class="auction-card">

                            <img
                                src="<?= htmlspecialchars($image) ?>"
                                alt="<?= htmlspecialchars($auction['animal_type']) ?>"
                                class="auction-image"
                            >

                            <div class="auction-body">

                                <div class="auction-title">

                                    <?= htmlspecialchars($auction['animal_type']) ?>

                                    <?php if (!empty($auction['breed'])): ?>

                                        - <?= htmlspecialchars($auction['breed']) ?>

                                    <?php endif; ?>

                                </div>

                                <div class="auction-meta">

                                    <i class="bi bi-geo-alt"></i>
                                    <?= htmlspecialchars($auction['location'] ?? 'Location unavailable') ?>

                                    <?php if (!empty($auction['weight'])): ?>
                                        · <?= htmlspecialchars($auction['weight']) ?> kg
                                    <?php endif; ?>

                                </div>

                                <div class="mb-2">

                                    <small class="text-muted">
                                        Current Bid
                                    </small>

                                    <div class="current-bid">

                                        KES
                                        <?= number_format($currentBid, 2) ?>

                                    </div>

                                </div>

                                <div class="d-flex justify-content-between align-items-center">

                                    <small class="text-muted">

                                        Ends:
                                        <?= date('d M Y, H:i', strtotime($auction['end_time'])) ?>

                                    </small>

                                    <a
                                        href="auction-details.php?id=<?= (int) $auction['id'] ?>"
                                        class="btn btn-success btn-sm"
                                    >
                                        View Auction
                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- Recent Bids -->
    <div class="section-card">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div class="section-title">
                Recent Bidding Activity
            </div>

            <a href="my-bids.php" class="btn btn-sm btn-outline-success">
                View All
            </a>

        </div>


        <?php if (empty($recentBids)): ?>

            <div class="text-center py-4">

                <i class="bi bi-hand-index-thumb fs-2 text-muted"></i>

                <p class="text-muted mt-2 mb-0">
                    You haven't placed any bids yet.
                </p>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table bid-table align-middle">

                    <thead>

                        <tr>

                            <th>Livestock</th>
                            <th>Bid Amount</th>
                            <th>Bid Date</th>
                            <th>Status</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($recentBids as $bid): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars($bid['animal_type']) ?>
                                    </strong>

                                    <?php if (!empty($bid['breed'])): ?>

                                        <small class="text-muted d-block">
                                            <?= htmlspecialchars($bid['breed']) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <strong>
                                        KES <?= number_format((float) $bid['bid_amount'], 2) ?>
                                    </strong>

                                </td>

                                <td>

                                    <?= date(
                                        'd M Y, H:i',
                                        strtotime($bid['bid_time'])
                                    ) ?>

                                </td>

                                <td>

                                    <?php if ($bid['status'] === 'winning'): ?>

                                        <span class="badge bg-success">
                                            Winning
                                        </span>

                                    <?php elseif ($bid['status'] === 'outbid'): ?>

                                        <span class="badge bg-danger">
                                            Outbid
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars(ucfirst($bid['status'])) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>