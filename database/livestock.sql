-- ============================================================
-- DIGITAL LIVESTOCK AUCTION SYSTEM
-- DATABASE SETUP
-- ============================================================

-- Create database
CREATE DATABASE IF NOT EXISTS digital_livestock_auction
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE digital_livestock_auction;


-- ============================================================
-- USERS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,

    email VARCHAR(150) NOT NULL UNIQUE,

    phone VARCHAR(20) NOT NULL,

    password VARCHAR(255) NOT NULL,

    role ENUM('farmer', 'buyer', 'admin')
        NOT NULL DEFAULT 'buyer',

    status ENUM('active', 'inactive', 'suspended')
        NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


-- ============================================================
-- LIVESTOCK TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS livestock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    farmer_id INT UNSIGNED NOT NULL,

    animal_type VARCHAR(50) NOT NULL,

    breed VARCHAR(100),

    gender ENUM('male', 'female') NOT NULL,

    age INT,

    weight DECIMAL(10,2),

    health_status VARCHAR(100),

    description TEXT,

    location VARCHAR(150),

    image VARCHAR(255),

    video VARCHAR(255),

    status ENUM('available', 'auctioned', 'sold', 'inactive')
        NOT NULL DEFAULT 'available',

    moderation_status ENUM('pending', 'approved', 'rejected', 'suspended')
        NOT NULL DEFAULT 'approved',

    moderation_reason TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_livestock_farmer
        FOREIGN KEY (farmer_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- HEALTH RECORDS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS health_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    livestock_id INT UNSIGNED NOT NULL,

    vaccination TEXT,

    treatment TEXT,

    veterinarian VARCHAR(150),

    record_date DATE,

    document VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_health_livestock
        FOREIGN KEY (livestock_id)
        REFERENCES livestock(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- AUCTIONS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS auctions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    livestock_id INT UNSIGNED NOT NULL,

    farmer_id INT UNSIGNED NOT NULL,

    starting_price DECIMAL(12,2) NOT NULL,

    minimum_bid_increment DECIMAL(12,2)
        NOT NULL DEFAULT 100.00,

    start_time DATETIME NOT NULL,

    end_time DATETIME NOT NULL,

    status ENUM(
        'pending',
        'active',
        'completed',
        'cancelled',
        'suspended'
    ) NOT NULL DEFAULT 'pending',

    winner_id INT UNSIGNED NULL,

    winning_bid DECIMAL(12,2) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_auction_livestock
        FOREIGN KEY (livestock_id)
        REFERENCES livestock(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_auction_farmer
        FOREIGN KEY (farmer_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_auction_winner
        FOREIGN KEY (winner_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


-- ============================================================
-- BIDS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS bids (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    auction_id INT UNSIGNED NOT NULL,

    buyer_id INT UNSIGNED NOT NULL,

    bid_amount DECIMAL(12,2) NOT NULL,

    bid_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    status ENUM(
        'active',
        'winning',
        'outbid',
        'cancelled'
    ) NOT NULL DEFAULT 'active',

    CONSTRAINT fk_bid_auction
        FOREIGN KEY (auction_id)
        REFERENCES auctions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_bid_buyer
        FOREIGN KEY (buyer_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- NOTIFICATIONS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    title VARCHAR(150) NOT NULL,

    message TEXT NOT NULL,

    type VARCHAR(50),

    is_read TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- INDEXES
-- ============================================================

CREATE INDEX idx_users_role
ON users(role);

CREATE INDEX idx_users_status
ON users(status);

CREATE INDEX idx_livestock_farmer
ON livestock(farmer_id);

CREATE INDEX idx_livestock_status
ON livestock(status);

CREATE INDEX idx_auction_status
ON auctions(status);

CREATE INDEX idx_auction_end_time
ON auctions(end_time);

CREATE INDEX idx_bids_auction
ON bids(auction_id);

CREATE INDEX idx_bids_buyer
ON bids(buyer_id);

CREATE INDEX idx_notifications_user
ON notifications(user_id);


-- ============================================================
-- DATABASE READY
-- ============================================================