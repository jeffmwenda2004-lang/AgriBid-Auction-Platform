<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$respond = static function (int $statusCode, array $data): never {
	http_response_code($statusCode);
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
	exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	header('Allow: GET');
	$respond(405, ['error' => 'Method not allowed.']);
}

$userId = getUserId();
$userRole = getUserRole();
if ($userId === null || !in_array($userRole, ['buyer', 'farmer', 'admin'], true)) {
	$respond(401, ['error' => 'Authentication required.']);
}

$auctionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$auctionId) {
	$respond(400, ['error' => 'A valid auction ID is required.']);
}

$statusStmt = $pdo->prepare(
	"SELECT
		a.id,
		a.farmer_id,
		a.status,
		a.start_time,
		a.end_time,
		a.winner_id,
		a.winning_bid,
		l.farmer_id AS livestock_farmer_id,
		l.moderation_status,
		COALESCE((
			SELECT MAX(b.bid_amount)
			FROM bids b
			WHERE b.auction_id = a.id AND b.status <> 'cancelled'
		), a.starting_price) AS current_bid,
		EXISTS(
			SELECT 1 FROM bids viewer_bid
			WHERE viewer_bid.auction_id = a.id AND viewer_bid.buyer_id = ?
		) AS viewer_has_bid
	 FROM auctions a
	 INNER JOIN livestock l ON l.id = a.livestock_id
	 WHERE a.id = ?
	 LIMIT 1"
);
$statusStmt->execute([$userId, $auctionId]);
$auction = $statusStmt->fetch();

if (!$auction) {
	$respond(404, ['error' => 'Auction not found.']);
}

$isAdmin = $userRole === 'admin';
$isFarmerOwner = $userRole === 'farmer'
	&& (int) $auction['farmer_id'] === $userId
	&& (int) $auction['livestock_farmer_id'] === $userId;
$isBuyer = $userRole === 'buyer';
$canSeeApprovedAuction = $isBuyer && (
	$auction['moderation_status'] === 'approved'
	&& !in_array($auction['status'], ['cancelled', 'suspended'], true)
);
$viewerHasBid = (int) $auction['viewer_has_bid'] === 1;

if (!$isAdmin && !$isFarmerOwner && !$canSeeApprovedAuction && !$viewerHasBid) {
	$respond(404, ['error' => 'Auction not found.']);
}

$databaseNow = new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());
$startTime = new DateTimeImmutable((string) $auction['start_time']);
$endTime = new DateTimeImmutable((string) $auction['end_time']);
$isWinner = $isBuyer
	&& $auction['winner_id'] !== null
	&& (int) $auction['winner_id'] === $userId;
$canBid = $isBuyer
	&& !$isFarmerOwner
	&& $auction['moderation_status'] === 'approved'
	&& $auction['status'] === 'active'
	&& $startTime <= $databaseNow
	&& $endTime > $databaseNow;

$respond(200, [
	'auction_id' => (int) $auction['id'],
	'status' => $auction['status'],
	'starts_at' => $startTime->format(DATE_ATOM),
	'ends_at' => $endTime->format(DATE_ATOM),
	'current_bid' => (float) $auction['current_bid'],
	'can_bid' => $canBid,
	'is_winner' => $isWinner,
	'winning_bid' => $auction['winning_bid'] === null ? null : (float) $auction['winning_bid'],
]);
