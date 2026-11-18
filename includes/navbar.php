<?php
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentScriptDirectory = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$nestedPageDirectories = ['admin', 'auction', 'auth', 'buyer', 'farmer'];
$navigationPathPrefix = in_array($currentScriptDirectory, $nestedPageDirectories, true) ? '../' : '';
$isHomePage = $currentPage === 'index.php';
?>
<nav class="navbar navbar-expand-lg navbar-dark" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand" href="<?= $navigationPathPrefix ?>index.php">
            <i class="bi bi-shop" aria-hidden="true"></i>
            Livestock Auction
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNavbar"
            aria-controls="mainNavbar"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link<?= $isHomePage ? ' active' : '' ?>" href="<?= $navigationPathPrefix ?>index.php"<?= $isHomePage ? ' aria-current="page"' : '' ?>>
                        Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $navigationPathPrefix ?>index.php#how-it-works">How It Works</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $navigationPathPrefix ?>index.php#features">Features</a>
                </li>
                <li class="nav-item ms-lg-3">
                    <a href="<?= $navigationPathPrefix ?>auth/login.php" class="btn btn-outline-light">Login</a>
                </li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <a href="<?= $navigationPathPrefix ?>auth/register.php" class="btn btn-light">Register</a>
                </li>
            </ul>
        </div>
    </div>
</nav>