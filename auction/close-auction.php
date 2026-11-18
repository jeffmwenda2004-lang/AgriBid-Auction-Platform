<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: ../farmer/auctions.php');
	exit;
}

$farmerId = getUserId();
$postedToken = $_POST['csrf_token'] ?? '';
$auctionId = filter_var($_POST['auction_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!isValidCsrfToken($postedToken)) {
	$_SESSION['farmer_auction_error'] = 'Your form session expired. Please reload and try again.';
	header('Location: ../farmer/auctions.php');
	exit;
}

if ($farmerId === null || !$auctionId) {
	$_SESSION['farmer_auction_error'] = 'Choose a valid auction to close.';
	header('Location: ../farmer/auctions.php');
	exit;
}

try {
	$pdo->beginTransaction();

	$auctionStmt = $pdo->prepare(
		'SELECT id, livestock_id, status, end_time
		 FROM auctions
		 WHERE id = ? AND farmer_id = ?
		 FOR UPDATE'
	);
	$auctionStmt->execute([$auctionId, $farmerId]);
	$auction = $auctionStmt->fetch();

	if (!$auction) {
		throw new DomainException('Auction not found or you do not have permission to close it.');
	}
	if ($auction['status'] !== 'active') {
		throw new DomainException('Only active auctions can be closed.');
	}

	$databaseNow = new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());
	if (new DateTimeImmutable((string) $auction['end_time']) > $databaseNow) {
		throw new DomainException('This auction has not reached its scheduled end time.');
	}

	$winningBidStmt = $pdo->prepare(
		"SELECT id, buyer_id, bid_amount
		 FROM bids
		 WHERE auction_id = ? AND status <> 'cancelled'
		 ORDER BY bid_amount DESC, bid_time DESC, id DESC
		 LIMIT 1
		 FOR UPDATE"
	);
	$winningBidStmt->execute([$auctionId]);
	$winningBid = $winningBidStmt->fetch();

	$markOutbidStmt = $pdo->prepare(
		"UPDATE bids
		 SET status = 'outbid'
		 WHERE auction_id = ? AND status <> 'cancelled'"
	);
	$markOutbidStmt->execute([$auctionId]);

	$winnerId = null;
	$winningAmount = null;
	if ($winningBid) {
		$winnerId = (int) $winningBid['buyer_id'];
		$winningAmount = (float) $winningBid['bid_amount'];

		$markWinnerStmt = $pdo->prepare("UPDATE bids SET status = 'winning' WHERE id = ?");
		$markWinnerStmt->execute([(int) $winningBid['id']]);
	}

	$completeAuctionStmt = $pdo->prepare(
		"UPDATE auctions
		 SET status = 'completed', winner_id = ?, winning_bid = ?
		 WHERE id = ? AND farmer_id = ? AND status = 'active'"
	);
	$completeAuctionStmt->execute([$winnerId, $winningAmount, $auctionId, $farmerId]);
	if ($completeAuctionStmt->rowCount() !== 1) {
		throw new DomainException('The auction could not be completed. Refresh and try again.');
	}

	$livestockStatus = $winnerId === null ? 'available' : 'sold';
	$livestockStmt = $pdo->prepare(
		'UPDATE livestock SET status = ? WHERE id = ? AND farmer_id = ?'
	);
	$livestockStmt->execute([$livestockStatus, (int) $auction['livestock_id'], $farmerId]);

	$pdo->commit();
	$_SESSION['farmer_auction_success'] = $winnerId === null
		? 'Auction closed with no valid bids. The livestock is available again.'
		: 'Auction closed. The winning bid was KES ' . number_format($winningAmount, 2) . '.';
} catch (DomainException $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	$_SESSION['farmer_auction_error'] = $exception->getMessage();
} catch (PDOException $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	error_log('Farmer auction close failed: ' . $exception->getMessage());
	$_SESSION['farmer_auction_error'] = 'Unable to close the auction right now. Please try again.';
}

header('Location: ../farmer/auctions.php');
exit;
