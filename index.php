```php
<?php
session_start();
$pageTitle = 'Digital Livestock Auction System';
$pageDescription = 'A digital livestock auction platform connecting farmers and buyers through transparent online auctions.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <!-- Custom CSS -->
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
        }

        .navbar {
            background: #1f2937;
        }

        .navbar-brand {
            font-weight: 700;
        }

        .hero {
            min-height: 85vh;
            display: flex;
            align-items: center;
            background:
                linear-gradient(rgba(0, 0, 0, 0.60), rgba(0, 0, 0, 0.60)),
                url('/assets/images/GANADO DOBLE PROPOSITO.png')
                center/cover no-repeat;
            color: white;
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 700;
        }

        .hero p {
            font-size: 1.15rem;
            max-width: 650px;
        }

        .feature-card {
            border: none;
            border-radius: 15px;
            transition: 0.3s ease;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .auction-section {
            background: white;
        }

        footer {
            background: #1f2937;
            color: white;
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.4rem;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/includes/navbar.php'; ?>


<!-- HERO SECTION -->
<section class="hero">

    <div class="container">

        <div class="row">

            <div class="col-lg-8">

                <span class="badge bg-light text-dark mb-3 px-3 py-2">
                    Digital Livestock Marketplace
                </span>

                <h1>
                    Buy and Sell Livestock Through Online Auctions
                </h1>

                <p class="lead mt-3">
                    Connect farmers and buyers through a transparent
                    digital auction platform. View livestock information,
                    participate in auctions and compete through online bidding.
                </p>

                <div class="mt-4">

                    <a href="auth/register.php"
                       class="btn btn-light btn-lg me-2">
                        <i class="bi bi-person-plus"></i>
                        Get Started
                    </a>

                    <a href="#how-it-works"
                       class="btn btn-outline-light btn-lg">
                        Learn More
                    </a>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- FEATURES -->
<section id="features" class="py-5">

    <div class="container">

        <div class="text-center mb-5">

            <span class="text-uppercase text-muted">
                Platform Features
            </span>

            <h2 class="fw-bold mt-2">
                Everything You Need for Digital Livestock Auctions
            </h2>

            <p class="text-muted">
                A centralized platform designed to make livestock trading
                more accessible and transparent.
            </p>

        </div>


        <div class="row g-4">

            <!-- Livestock -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-clipboard-data"></i>
                    </div>

                    <h4>
                        Livestock Listings
                    </h4>

                    <p class="text-muted">
                        Farmers can provide livestock details including
                        breed, age, weight, health information and images.
                    </p>

                </div>

            </div>


            <!-- Auction -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-hammer"></i>
                    </div>

                    <h4>
                        Online Auctions
                    </h4>

                    <p class="text-muted">
                        Farmers can schedule livestock auctions while
                        buyers participate remotely.
                    </p>

                </div>

            </div>


            <!-- Bidding -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <h4>
                        Competitive Bidding
                    </h4>

                    <p class="text-muted">
                        Buyers can place bids during active auctions
                        and compete for livestock.
                    </p>

                </div>

            </div>


            <!-- Transparency -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <h4>
                        Transparent Trading
                    </h4>

                    <p class="text-muted">
                        Auction information and bidding activities are
                        managed through a centralized system.
                    </p>

                </div>

            </div>


            <!-- Market -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-globe2"></i>
                    </div>

                    <h4>
                        Wider Market Access
                    </h4>

                    <p class="text-muted">
                        Farmers can reach potential buyers beyond
                        traditional physical livestock markets.
                    </p>

                </div>

            </div>


            <!-- Records -->
            <div class="col-md-6 col-lg-4">

                <div class="card feature-card shadow-sm p-4 text-center">

                    <div class="feature-icon">
                        <i class="bi bi-database-check"></i>
                    </div>

                    <h4>
                        Digital Records
                    </h4>

                    <p class="text-muted">
                        Auctions, bids, livestock and user activities
                        can be stored and managed digitally.
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- HOW IT WORKS -->
<section id="how-it-works" class="auction-section py-5">

    <div class="container">

        <div class="text-center mb-5">

            <h2 class="fw-bold">
                How It Works
            </h2>

            <p class="text-muted">
                Simple steps for farmers and buyers.
            </p>

        </div>


        <div class="row g-4">

            <!-- Farmer -->
            <div class="col-lg-6">

                <div class="card shadow-sm border-0 h-100">

                    <div class="card-body p-4">

                        <h3 class="mb-4">
                            <i class="bi bi-person-workspace"></i>
                            For Farmers
                        </h3>

                        <div class="mb-3">
                            <strong>1. Create an account</strong>
                            <p class="text-muted mb-0">
                                Register as a farmer on the platform.
                            </p>
                        </div>

                        <div class="mb-3">
                            <strong>2. List your livestock</strong>
                            <p class="text-muted mb-0">
                                Add animal details, images and health information.
                            </p>
                        </div>

                        <div class="mb-3">
                            <strong>3. Create an auction</strong>
                            <p class="text-muted mb-0">
                                Set the starting price and auction period.
                            </p>
                        </div>

                        <div>
                            <strong>4. Receive bids</strong>
                            <p class="text-muted mb-0">
                                Buyers compete by placing bids during the auction.
                            </p>
                        </div>

                    </div>

                </div>

            </div>


            <!-- Buyer -->
            <div class="col-lg-6">

                <div class="card shadow-sm border-0 h-100">

                    <div class="card-body p-4">

                        <h3 class="mb-4">
                            <i class="bi bi-person-check"></i>
                            For Buyers
                        </h3>

                        <div class="mb-3">
                            <strong>1. Create an account</strong>
                            <p class="text-muted mb-0">
                                Register as a buyer.
                            </p>
                        </div>

                        <div class="mb-3">
                            <strong>2. Browse livestock</strong>
                            <p class="text-muted mb-0">
                                Search available livestock and view their details.
                            </p>
                        </div>

                        <div class="mb-3">
                            <strong>3. Join an auction</strong>
                            <p class="text-muted mb-0">
                                View active auctions and current bids.
                            </p>
                        </div>

                        <div>
                            <strong>4. Place your bid</strong>
                            <p class="text-muted mb-0">
                                Submit competitive bids before the auction ends.
                            </p>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- CALL TO ACTION -->
<section class="py-5">

    <div class="container">

        <div class="card border-0 shadow-sm text-center p-5">

            <h2 class="fw-bold">
                Ready to Join the Digital Livestock Market?
            </h2>

            <p class="text-muted">
                Register today and participate in a modern livestock
                auction experience.
            </p>

            <div>

                <a href="auth/register.php"
                   class="btn btn-dark btn-lg">
                    Create Account
                </a>

            </div>

        </div>

    </div>

</section>


<!-- FOOTER -->
<footer class="py-4">

    <div class="container">

        <div class="row align-items-center">

            <div class="col-md-6">

                <strong>
                    Digital Livestock Auction System
                </strong>

                <p class="mb-0 mt-1 small">
                    Connecting farmers and buyers through digital auctions.
                </p>

            </div>

            <div class="col-md-6 text-md-end mt-3 mt-md-0">

                <span class="small">
                    &copy; <?php echo date('Y'); ?>
                    Digital Livestock Auction System
                </span>

            </div>

        </div>

    </div>

</footer>


<!-- Bootstrap JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
```
