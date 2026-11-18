<?php

function processAuctionLifecycle(PDO $pdo): void
{
    try {
        $activateStmt = $pdo->prepare(
            "UPDATE auctions a
             INNER JOIN livestock l ON l.id = a.livestock_id
             SET a.status = 'active'
             WHERE a.status = 'pending'
               AND l.moderation_status = 'approved'
               AND a.start_time <= NOW()"
        );
        $activateStmt->execute();

        $expiredIds = $pdo->query(
            "SELECT id FROM auctions
             WHERE status = 'active' AND end_time <= NOW()
             ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expiredIds as $rawAuctionId) {
            $auctionId = (int) $rawAuctionId;

            try {
                $pdo->beginTransaction();

                $auctionStmt = $pdo->prepare(
                    "SELECT id, livestock_id, farmer_id
                     FROM auctions
                     WHERE id = ? AND status = 'active' AND end_time <= NOW()
                     FOR UPDATE"
                );
                $auctionStmt->execute([$auctionId]);
                $auction = $auctionStmt->fetch();

                if (!$auction) {
                    $pdo->rollBack();
                    continue;
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

                $outbidStmt = $pdo->prepare(
                    "UPDATE bids SET status = 'outbid'
                     WHERE auction_id = ? AND status <> 'cancelled'"
                );
                $outbidStmt->execute([$auctionId]);

                $winnerId = null;
                $winningAmount = null;
                if ($winningBid) {
                    $winnerId = (int) $winningBid['buyer_id'];
                    $winningAmount = (float) $winningBid['bid_amount'];

                    $winnerStmt = $pdo->prepare("UPDATE bids SET status = 'winning' WHERE id = ?");
                    $winnerStmt->execute([(int) $winningBid['id']]);
                }

                $completeStmt = $pdo->prepare(
                    "UPDATE auctions
                     SET status = 'completed', winner_id = ?, winning_bid = ?
                     WHERE id = ? AND status = 'active'"
                );
                $completeStmt->execute([$winnerId, $winningAmount, $auctionId]);

                $livestockStatus = $winnerId === null ? 'available' : 'sold';
                $livestockStmt = $pdo->prepare(
                    'UPDATE livestock SET status = ? WHERE id = ? AND farmer_id = ?'
                );
                $livestockStmt->execute([
                    $livestockStatus,
                    (int) $auction['livestock_id'],
                    (int) $auction['farmer_id'],
                ]);

                if ($winnerId !== null) {
                    $cancelLaterAuctionsStmt = $pdo->prepare(
                        "UPDATE auctions
                         SET status = 'cancelled'
                         WHERE livestock_id = ? AND id <> ? AND status IN ('pending', 'active')"
                    );
                    $cancelLaterAuctionsStmt->execute([(int) $auction['livestock_id'], $auctionId]);
                }

                $pdo->commit();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Auction lifecycle update failed for auction ' . $auctionId . ': ' . $exception->getMessage());
            }
        }
    } catch (PDOException $exception) {
        error_log('Auction lifecycle processing failed: ' . $exception->getMessage());
    }
}