<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminName = getUserName();
$escape = 'escapeHtml';

$isValidDate = static function (string $value): bool {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return false;
	}

	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
	return $date !== false && $date->format('Y-m-d') === $value;
};

$today = new DateTimeImmutable('today');
$defaultTo = $today->format('Y-m-d');
$defaultFrom = $today->modify('-29 days')->format('Y-m-d');
$dateFromInput = $_GET['date_from'] ?? '';
$dateToInput = $_GET['date_to'] ?? '';
$dateFrom = is_string($dateFromInput) && $isValidDate($dateFromInput) ? $dateFromInput : $defaultFrom;
$dateTo = is_string($dateToInput) && $isValidDate($dateToInput) ? $dateToInput : $defaultTo;

if ($dateFrom > $dateTo) {
	[$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$periodStart = $dateFrom . ' 00:00:00';
$periodEnd = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$statsStmt = $pdo->prepare(
	"SELECT
		(SELECT COUNT(*) FROM users WHERE role IN ('farmer', 'buyer') AND created_at >= ? AND created_at < ?) AS new_users,
		(SELECT COUNT(*) FROM livestock WHERE created_at >= ? AND created_at < ?) AS new_listings,
		(SELECT COUNT(*) FROM auctions WHERE created_at >= ? AND created_at < ?) AS new_auctions,
		(SELECT COUNT(*) FROM bids WHERE bid_time >= ? AND bid_time < ?) AS bids_placed"
);
$statsStmt->execute([
	$periodStart, $periodEnd,
	$periodStart, $periodEnd,
	$periodStart, $periodEnd,
	$periodStart, $periodEnd,
]);
$stats = $statsStmt->fetch();

$auctionStatusStmt = $pdo->prepare(
	'SELECT status, COUNT(*) AS status_count
	 FROM auctions
	 WHERE created_at >= ? AND created_at < ?
	 GROUP BY status'
);
$auctionStatusStmt->execute([$periodStart, $periodEnd]);
$auctionStatusCounts = array_fill_keys(['pending', 'active', 'completed', 'cancelled', 'suspended'], 0);
foreach ($auctionStatusStmt->fetchAll() as $statusRow) {
	if (isset($auctionStatusCounts[$statusRow['status']])) {
		$auctionStatusCounts[$statusRow['status']] = (int) $statusRow['status_count'];
	}
}

$topBidsStmt = $pdo->prepare(
	"SELECT
		a.id AS auction_id,
		l.animal_type,
		l.breed,
		farmer.name AS farmer_name,
		COUNT(b.id) AS bid_count,
		MAX(CASE WHEN b.status <> 'cancelled' THEN b.bid_amount END) AS highest_bid
	 FROM bids b
	 INNER JOIN auctions a ON a.id = b.auction_id
	 INNER JOIN livestock l ON l.id = a.livestock_id
	 INNER JOIN users farmer ON farmer.id = a.farmer_id
	 WHERE b.bid_time >= ? AND b.bid_time < ?
	 GROUP BY a.id, l.animal_type, l.breed, farmer.name
	 ORDER BY bid_count DESC, highest_bid DESC, a.id DESC
	 LIMIT 10"
);
$topBidsStmt->execute([$periodStart, $periodEnd]);
$topBidAuctions = $topBidsStmt->fetchAll();

$statusClasses = [
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
	<title>Reports | Digital Livestock Auction</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
	<style>
		body { background: #f5f7f9; color: #263238; }
		.sidebar { width: 250px; min-height: 100vh; background: #12372A; position: fixed; inset: 0 auto 0 0; padding: 25px 15px; z-index: 1000; }
		.brand { color: #fff; font-size: 20px; font-weight: 700; padding: 10px 15px 30px; }
		.brand i { margin-right: 8px; }
		.sidebar a { display: flex; align-items: center; gap: 12px; color: #dce8e2; text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; }
		.sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.12); color: #fff; }
		.sidebar .logout { margin-top: 30px; color: #ffcccc; }
		.main { margin-left: 250px; padding: 30px; }
		.topbar, .report-card, .content-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
		.topbar { padding: 20px 25px; margin-bottom: 25px; }
		.topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
		.topbar p { margin: 5px 0 0; color: #718096; }
		.report-card, .content-card { padding: 20px; }
		.report-label { color: #718096; font-size: 13px; }
		.report-value { margin-top: 7px; color: #12372A; font-size: 28px; font-weight: 700; }
		.report-icon { color: #12372A; font-size: 21px; }
		.table td, .table th { vertical-align: middle; }
		@media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
		@media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } }
	</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
	<header class="topbar">
		<h1>Platform Reports</h1>
		<p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Review activity from <?= $escape(date('d M Y', strtotime($dateFrom))) ?> to <?= $escape(date('d M Y', strtotime($dateTo))) ?>.</p>
	</header>

	<section class="content-card mb-4">
		<form method="GET" action="reports.php" class="row g-3 align-items-end">
			<div class="col-sm-5 col-lg-4">
				<label for="date_from" class="form-label">From</label>
				<input id="date_from" name="date_from" type="date" class="form-control" value="<?= $escape($dateFrom) ?>">
			</div>
			<div class="col-sm-5 col-lg-4">
				<label for="date_to" class="form-label">To</label>
				<input id="date_to" name="date_to" type="date" class="form-control" value="<?= $escape($dateTo) ?>">
			</div>
			<div class="col-sm-2 d-flex gap-2">
				<button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-calendar-range me-1"></i>Apply</button>
			</div>
		</form>
	</section>

	<section class="row g-3 mb-4" aria-label="Activity summary">
		<?php foreach ([
			['New accounts', 'new_users', 'bi-person-plus'],
			['Livestock submitted', 'new_listings', 'bi-box-seam'],
			['Auctions created', 'new_auctions', 'bi-hammer'],
			['Bids placed', 'bids_placed', 'bi-hand-index-thumb'],
		] as [$label, $key, $icon]): ?>
			<div class="col-sm-6 col-xl-3">
				<article class="report-card h-100">
					<i class="bi <?= $escape($icon) ?> report-icon"></i>
					<div class="report-value"><?= number_format((int) $stats[$key]) ?></div>
					<div class="report-label"><?= $escape($label) ?></div>
				</article>
			</div>
		<?php endforeach; ?>
	</section>

	<div class="row g-4">
		<div class="col-lg-5">
			<section class="content-card h-100">
				<h2 class="h5 fw-bold mb-3">Auctions by status</h2>
				<?php $auctionTotal = array_sum($auctionStatusCounts); ?>
				<?php if ($auctionTotal === 0): ?>
					<p class="text-muted mb-0">No auctions were created during this period.</p>
				<?php else: ?>
					<?php foreach ($auctionStatusCounts as $auctionStatus => $count): ?>
						<div class="d-flex justify-content-between align-items-center py-2 border-bottom">
							<span><span class="badge text-bg-<?= $escape($statusClasses[$auctionStatus]) ?> me-2"><?= $escape(ucfirst($auctionStatus)) ?></span></span>
							<strong><?= number_format($count) ?></strong>
						</div>
					<?php endforeach; ?>
					<div class="d-flex justify-content-between pt-3 fw-bold"><span>Total</span><span><?= number_format($auctionTotal) ?></span></div>
				<?php endif; ?>
			</section>
		</div>

		<div class="col-lg-7">
			<section class="content-card h-100">
				<h2 class="h5 fw-bold mb-3">Most active auctions</h2>
				<?php if ($topBidAuctions === []): ?>
					<p class="text-muted mb-0">No bids were placed during this period.</p>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-hover align-middle mb-0">
							<thead><tr><th>Livestock</th><th>Farmer</th><th>Bids in period</th><th>Highest non-cancelled bid</th></tr></thead>
							<tbody>
							<?php foreach ($topBidAuctions as $auction): ?>
								<tr>
									<td><a href="auctions.php?view=<?= (int) $auction['auction_id'] ?>" class="text-decoration-none fw-semibold"><?= $escape($auction['animal_type']) ?></a><small class="d-block text-muted"><?= $escape($auction['breed'] ?: 'Breed not specified') ?></small></td>
									<td><?= $escape($auction['farmer_name']) ?></td>
									<td><?= number_format((int) $auction['bid_count']) ?></td>
									<td class="text-nowrap"><?= $auction['highest_bid'] !== null ? 'KES ' . number_format((float) $auction['highest_bid'], 2) : '—' ?></td>
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
