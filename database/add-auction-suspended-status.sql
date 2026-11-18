USE digital_livestock_auction;

ALTER TABLE auctions
    MODIFY status ENUM(
        'pending',
        'active',
        'completed',
        'cancelled',
        'suspended'
    ) NOT NULL DEFAULT 'pending';
