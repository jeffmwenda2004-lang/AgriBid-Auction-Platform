USE digital_livestock_auction;

ALTER TABLE livestock
    ADD COLUMN moderation_status ENUM('pending', 'approved', 'rejected', 'suspended')
        NOT NULL DEFAULT 'approved' AFTER status,
    ADD COLUMN moderation_reason TEXT NULL AFTER moderation_status;
