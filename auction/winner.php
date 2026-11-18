<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$buyerName = getUserName();
$auctionId = filter_input(INPUT_GET, 'auction_id', FILTER_VALIDATE_INT);

if (!$auctionId || $auctionId < 1 || $buyerId === null) {
	header('Location: ../buyer/my-bids.php');
	exit;
}

$winnerStmt = $pdo->prepare(
	"SELECT
		a.id,
		a.winning_bid,
		a.starting_price,
		a.end_time,
		l.animal_type,
		l.breed,
		l.weight,
		l.location,
		l.image,
		farmer.name AS farmer_name
	 FROM auctions a
	 INNER JOIN livestock l ON l.id = a.livestock_id
	 INNER JOIN users farmer ON farmer.id = a.farmer_id
	 WHERE a.id = ? AND a.status = 'completed' AND a.winner_id = ?
	 LIMIT 1"
);
$winnerStmt->execute([$auctionId, $buyerId]);
$auction = $winnerStmt->fetch();

if (!$auction) {
	header('Location: ../buyer/my-bids.php');
	exit;
}

$escape = 'escapeHtml';
$imageName = !empty($auction['image']) ? basename((string) $auction['image']) : '';
$imageUrl = $imageName !== ''
	? '../uploads/livestock/' . rawurlencode($imageName)
	: '../assets/images/GANADO DOBLE PROPOSITO.jfif';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Auction Won | Digital Livestock Auction</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
	<style>
		body { min-height: 100vh; background: #f5f7f9; color: #263238; }
		.sidebar { width: 250px; min-height: 100vh; background: #12372A; position: fixed; inset: 0 auto 0 0; padding: 25px 15px; z-index: 1000; }
		.brand { color: #fff; font-size: 20px; font-weight: 700; padding: 10px 15px 30px; }
		.brand i { margin-right: 8px; }
		.sidebar a { display: flex; align-items: center; gap: 12px; color: #dce8e2; text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; }
		.sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.12); color: #fff; }
		.sidebar .logout { margin-top: 30px; color: #ffcccc; }
		.main { margin-left: 250px; padding: 30px; }
		.topbar, .result-panel { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
		.topbar { padding: 20px 25px; margin-bottom: 25px; }
		.topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
		.topbar p { margin: 5px 0 0; color: #718096; }
		.result-panel { max-width: 860px; overflow: hidden; }
		.result-image { display: block; width: 100%; max-height: 430px; object-fit: cover; background: #eef1f0; }
		.result-content { padding: 28px; }
		.result-label { color: #718096; font-size: 13px; }
		.winning-amount { color: #12372A; font-size: 28px; font-weight: 700; }
		@media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
		@media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } .result-content { padding: 20px; } }
	</style>
</head>
<body>
<nav class="sidebar" aria-label="Buyer navigation">
	<div class="brand"><i class="bi bi-shop"></i>Livestock Auction</div>
	<a href="../buyer/dashboard.php"><i class="bi bi-grid"></i>Dashboard</a>
	<a href="../buyer/livestock.php"><i class="bi bi-box-seam"></i>Browse Livestock</a>
	<a href="../buyer/auctions.php"><i class="bi bi-hammer"></i>Auctions</a>
	<a href="../buyer/my-bids.php" class="active"><i class="bi bi-hand-index-thumb"></i>My Bids</a>
	<a href="../buyer/profile.php"><i class="bi bi-person"></i>My Profile</a>
	<a href="../auth/logout.php" class="logout"><i class="bi bi-box-arrow-right"></i>Logout</a>
</nav>

<main class="main">
	<header class="topbar">
		<h1>Auction won</h1>
		<p>Congratulations, <?= $escape($buyerName ?? 'Buyer') ?>. This completed auction was awarded to you.</p>
	</header>

	<section class="result-panel" aria-labelledby="livestock-title">
		<img src="<?= $escape($imageUrl) ?>" alt="<?= $escape($auction['animal_type']) ?>" class="result-image">
		<div class="result-content">
			<span class="badge text-bg-success mb-2"><i class="bi bi-trophy me-1"></i>Winner</span>
			<h2 id="livestock-title" class="h3 fw-bold mb-1"><?= $escape($auction['animal_type']) ?></h2>
			<p class="text-muted mb-4"><?= $escape($auction['breed'] ?: 'Breed not specified') ?></p>

			<div class="row g-3 mb-4">
				<div class="col-sm-6">
					<div class="result-label">Winning bid</div>
					<div class="winning-amount">KES <?= number_format((float) $auction['winning_bid'], 2) ?></div>
				</div>
				<div class="col-sm-6">
					<div class="result-label">Farmer</div>
					<div class="fw-semibold"><?= $escape($auction['farmer_name']) ?></div>
				</div>
				<div class="col-sm-6">
					<div class="result-label">Weight</div>
					<div><?= $auction['weight'] !== null ? $escape($auction['weight']) . ' kg' : 'Not specified' ?></div>
				</div>
				<div class="col-sm-6">
					<div class="result-label">Location</div>
					<div><?= $escape($auction['location'] ?: 'Not specified') ?></div>
				</div>
				<div class="col-sm-6">
					<div class="result-label">Auction ended</div>
					<div><?= $escape(date('d M Y, H:i', strtotime((string) $auction['end_time']))) ?></div>
				</div>
				<div class="col-sm-6">
					<div class="result-label">Auction number</div>
					<div>#<?= (int) $auction['id'] ?></div>
				</div>
			</div>

			<a href="../buyer/my-bids.php" class="btn btn-success"><i class="bi bi-arrow-left me-1"></i>Back to my bids</a>
			<a href="../buyer/auction-details.php?id=<?= (int) $auction['id'] ?>" class="btn btn-outline-secondary ms-2">View auction</a>
		</div>
	</section>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
