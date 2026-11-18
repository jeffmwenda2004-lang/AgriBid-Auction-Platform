<?php

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentSection = match ($currentPage) {
    'livestock.php', 'livestock-details.php' => 'livestock',
    'auctions.php', 'auction-details.php' => 'auctions',
    'my-bids.php' => 'bids',
    'profile.php' => 'profile',
    default => 'dashboard',
};
$buyerNavName = getUserName() ?? 'Buyer';
?>
<header class="buyer-shell">
    <div class="buyer-shell__top">
        <a href="dashboard.php" class="buyer-brand">
            <span class="buyer-brand__mark"><i class="bi bi-shop-window"></i></span>
            <span>Livestock <strong>Auction</strong></span>
        </a>
        <div class="buyer-account">
            <span class="buyer-account__name"><i class="bi bi-person-circle"></i><?= htmlspecialchars($buyerNavName, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="../auth/logout.php" class="buyer-logout" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i><span>Sign out</span></a>
        </div>
    </div>
    <nav class="buyer-nav" aria-label="Buyer navigation">
        <a href="dashboard.php" class="<?= $currentSection === 'dashboard' ? 'active' : '' ?>" <?= $currentSection === 'dashboard' ? 'aria-current="page"' : '' ?>><i class="bi bi-grid"></i>Overview</a>
        <a href="livestock.php" class="<?= $currentSection === 'livestock' ? 'active' : '' ?>" <?= $currentSection === 'livestock' ? 'aria-current="page"' : '' ?>><i class="bi bi-box-seam"></i>Livestock</a>
        <a href="auctions.php" class="<?= $currentSection === 'auctions' ? 'active' : '' ?>" <?= $currentSection === 'auctions' ? 'aria-current="page"' : '' ?>><i class="bi bi-hammer"></i>Auctions</a>
        <a href="my-bids.php" class="<?= $currentSection === 'bids' ? 'active' : '' ?>" <?= $currentSection === 'bids' ? 'aria-current="page"' : '' ?>><i class="bi bi-hand-index-thumb"></i>My Bids</a>
        <a href="profile.php" class="<?= $currentSection === 'profile' ? 'active' : '' ?>" <?= $currentSection === 'profile' ? 'aria-current="page"' : '' ?>><i class="bi bi-person"></i>Profile</a>
    </nav>
</header>
