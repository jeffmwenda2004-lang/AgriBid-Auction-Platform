<?php

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentSection = match ($currentPage) {
    'livestock.php', 'add-livestock.php', 'edit-livestock.php' => 'livestock',
    'auctions.php', 'create-auction.php' => 'auctions',
    'bids.php' => 'bids',
    'profile.php' => 'profile',
    default => 'dashboard',
};
$farmerNavName = getUserName() ?? 'Farmer';
?>
<header class="farmer-shell">
    <div class="farmer-shell__top">
        <a href="dashboard.php" class="farmer-brand">
            <span class="farmer-brand__mark"><i class="bi bi-shop"></i></span>
            <span>Livestock <strong>Auction</strong></span>
        </a>
        <div class="farmer-account">
            <span class="farmer-account__name"><i class="bi bi-person-circle"></i><?= htmlspecialchars($farmerNavName, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="../auth/logout.php" class="farmer-logout"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></a>
        </div>
    </div>
    <nav class="farmer-nav" aria-label="Farmer navigation">
        <a href="dashboard.php" class="<?= $currentSection === 'dashboard' ? 'active' : '' ?>" <?= $currentSection === 'dashboard' ? 'aria-current="page"' : '' ?>><i class="bi bi-grid"></i>Overview</a>
        <a href="livestock.php" class="<?= $currentSection === 'livestock' ? 'active' : '' ?>" <?= $currentSection === 'livestock' ? 'aria-current="page"' : '' ?>><i class="bi bi-box-seam"></i>Livestock</a>
        <a href="auctions.php" class="<?= $currentSection === 'auctions' ? 'active' : '' ?>" <?= $currentSection === 'auctions' ? 'aria-current="page"' : '' ?>><i class="bi bi-hammer"></i>Auctions</a>
        <a href="bids.php" class="<?= $currentSection === 'bids' ? 'active' : '' ?>" <?= $currentSection === 'bids' ? 'aria-current="page"' : '' ?>><i class="bi bi-hand-index-thumb"></i>Bids</a>
        <a href="profile.php" class="<?= $currentSection === 'profile' ? 'active' : '' ?>" <?= $currentSection === 'profile' ? 'aria-current="page"' : '' ?>><i class="bi bi-person"></i>Profile</a>
    </nav>
</header>
