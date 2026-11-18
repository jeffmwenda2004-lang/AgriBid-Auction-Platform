<?php
require_once __DIR__ . '/functions.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminItems = [
    ['dashboard.php', 'bi-grid', 'Dashboard'],
    ['users.php', 'bi-people', 'Manage Users'],
    ['livestock.php', 'bi-box-seam', 'Manage Livestock'],
    ['auctions.php', 'bi-hammer', 'Manage Auctions'],
    ['bids.php', 'bi-hand-index-thumb', 'View Bids'],
    ['reports.php', 'bi-bar-chart', 'Reports'],
    ['settings.php', 'bi-gear', 'Settings'],
];
?>
<aside class="sidebar">
    <div class="brand"><i class="bi bi-shield-check"></i>Livestock Auction</div>
    <nav aria-label="Admin navigation">
        <?php foreach ($adminItems as [$href, $icon, $label]): ?>
            <?php $isActive = $currentPage === $href; ?>
            <a
                href="<?= escapeHtml($href) ?>"
                class="<?= $isActive ? 'active' : '' ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            >
                <i class="bi <?= escapeHtml($icon) ?>" aria-hidden="true"></i><?= escapeHtml($label) ?>
            </a>
        <?php endforeach; ?>
        <a href="../auth/logout.php" class="logout">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>Logout
        </a>
    </nav>
</aside>