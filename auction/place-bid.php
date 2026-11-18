<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../buyer/auctions.php');
    exit;
}

$buyerId = getUserId();
$auctionId = (int) ($_POST['auction_id'] ?? 0);
$bidAmountInput = $_POST['bid_amount'] ?? null;
$bidAmount = is_scalar($bidAmountInput) && is_numeric($bidAmountInput)
    ? (float) $bidAmountInput
    : 0.0;
$returnUrl = $auctionId > 0
    ? '../buyer/auction-details.php?id=' . $auctionId
    : '../buyer/auctions.php';

if ($buyerId === null || $auctionId <= 0 || !is_finite($bidAmount) || $bidAmount <= 0) {
    $_SESSION['auction_error'] = 'Please provide a valid bid amount.';
    header('Location: ' . $returnUrl);
    exit;
}

try {
    $pdo->beginTransaction();

    $auctionStmt = $pdo->prepare(
        "SELECT
            a.id,
            a.farmer_id,
            l.farmer_id AS livestock_farmer_id,
            a.starting_price,
            a.minimum_bid_increment,
            a.start_time,
            a.end_time,
            a.status,
            l.moderation_status
         FROM auctions a
         INNER JOIN livestock l ON l.id = a.livestock_id
         WHERE a.id = ?
         LIMIT 1"
         . " FOR UPDATE"
    );
    $auctionStmt->execute([$auctionId]);
    $auction = $auctionStmt->fetch();

    if (!$auction) {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'Auction not found.';
        header('Location: ' . $returnUrl);
        exit;
    }

    if (
        (int) $auction['farmer_id'] === $buyerId
        || (int) $auction['livestock_farmer_id'] === $buyerId
    ) {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'You cannot place a bid on your own auction.';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($auction['status'] !== 'active') {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'This auction has not been approved or is not open for bidding.';
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($auction['moderation_status'] !== 'approved') {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'This livestock listing is not approved for bidding.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $databaseTime = new DateTime((string) $pdo->query('SELECT NOW()')->fetchColumn());
    if (new DateTime($auction['start_time']) > $databaseTime) {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'This auction has not started yet. Bidding is not open.';
        header('Location: ' . $returnUrl);
        exit;
    }

    if (new DateTime($auction['end_time']) <= $databaseTime) {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'This auction has already ended. Bidding is closed.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $currentBidStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(bid_amount), ?) FROM bids WHERE auction_id = ?'
    );
    $currentBidStmt->execute([$auction['starting_price'], $auctionId]);
    $currentBid = (float) $currentBidStmt->fetchColumn();
    $minimumValidBid = $currentBid + (float) $auction['minimum_bid_increment'];
    if ($bidAmount < $minimumValidBid) {
        $pdo->rollBack();
        $_SESSION['auction_error'] = 'Your bid must be at least KES ' . number_format($minimumValidBid, 2) . '.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO bids (auction_id, buyer_id, bid_amount, status)
         VALUES (?, ?, ?, 'active')"
    );
    $insertStmt->execute([$auctionId, $buyerId, $bidAmount]);

    $pdo->commit();

    $_SESSION['auction_success'] = 'Your bid of KES ' . number_format($bidAmount, 2) . ' has been placed successfully.';
    header('Location: ' . $returnUrl);
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['auction_error'] = 'Unable to place the bid right now. Please try again.';
    header('Location: ' . $returnUrl);
    exit;
}
