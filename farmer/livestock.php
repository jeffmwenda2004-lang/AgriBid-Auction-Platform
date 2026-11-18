<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$farmerName = getUserName();

$csrfToken = getCsrfToken();
$actionSuccess = $_SESSION['farmer_livestock_success'] ?? '';
$actionError = $_SESSION['farmer_livestock_error'] ?? '';
unset($_SESSION['farmer_livestock_success'], $_SESSION['farmer_livestock_error']);

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_livestock,
        COALESCE(SUM(status = 'available'), 0) AS available_livestock
    FROM livestock
    WHERE farmer_id = ?
");
$statsStmt->execute([$farmerId]);
$livestockStats = $statsStmt->fetch();

$activeAuctionsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE farmer_id = ?
      AND status = 'active'
      AND end_time > NOW()
");
$activeAuctionsStmt->execute([$farmerId]);
$activeAuctions = (int) $activeAuctionsStmt->fetchColumn();

$animalTypesStmt = $pdo->prepare("
    SELECT DISTINCT animal_type
    FROM livestock
    WHERE farmer_id = ?
    ORDER BY animal_type
");
$animalTypesStmt->execute([$farmerId]);
$animalTypes = $animalTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$search = trim($_GET['search'] ?? '');
$animalTypeFilter = trim($_GET['animal_type'] ?? '');
$genderFilter = trim($_GET['gender'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$validGenders = ['male', 'female'];
$validStatuses = ['available', 'auctioned', 'sold', 'inactive'];

if (!in_array($genderFilter, $validGenders, true)) {
    $genderFilter = '';
}

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$conditions = ['l.farmer_id = ?'];
$params = [$farmerId];

if ($search !== '') {
    $conditions[] = '(l.animal_type LIKE ? OR l.breed LIKE ? OR l.location LIKE ?)';
    $searchTerm = '%' . $search . '%';
    array_push($params, $searchTerm, $searchTerm, $searchTerm);
}

if ($animalTypeFilter !== '' && in_array($animalTypeFilter, $animalTypes, true)) {
    $conditions[] = 'l.animal_type = ?';
    $params[] = $animalTypeFilter;
} else {
    $animalTypeFilter = '';
}

if ($genderFilter !== '') {
    $conditions[] = 'l.gender = ?';
    $params[] = $genderFilter;
}

if ($statusFilter !== '') {
    $conditions[] = 'l.status = ?';
    $params[] = $statusFilter;
}

$livestockStmt = $pdo->prepare("
    SELECT
        l.id,
        l.animal_type,
        l.breed,
        l.gender,
        l.age,
        l.weight,
        l.health_status,
        l.description,
        l.location,
        l.image,
        l.status,
        l.created_at,
        (
            SELECT a.id
            FROM auctions a
            WHERE a.livestock_id = l.id
              AND a.farmer_id = l.farmer_id
              AND a.status IN ('pending', 'active')
              AND a.end_time > NOW()
            ORDER BY a.created_at DESC
            LIMIT 1
        ) AS current_auction_id,
        (
            SELECT a.status
            FROM auctions a
            WHERE a.livestock_id = l.id
              AND a.farmer_id = l.farmer_id
              AND a.status IN ('pending', 'active')
              AND a.end_time > NOW()
            ORDER BY a.created_at DESC
            LIMIT 1
        ) AS current_auction_status
    FROM livestock l
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY l.created_at DESC, l.id DESC
");
$livestockStmt->execute($params);
$livestockList = $livestockStmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Livestock | Digital Livestock Auction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7f9;
            color: #263238;
        }

        .sidebar {
            width: 250px;
            min-height: 100vh;
            background: #12372A;
            position: fixed;
            left: 0;
            top: 0;
            padding: 25px 15px;
            z-index: 1000;
        }

        .brand {
            color: #fff;
            font-size: 20px;
            font-weight: 700;
            padding: 10px 15px 30px;
        }

        .brand i {
            margin-right: 8px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #dce8e2;
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: 0.2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }

        .sidebar .logout {
            margin-top: 30px;
            color: #ffcccc;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar,
        .stat-card,
        .filter-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .topbar {
            padding: 20px 25px;
            margin-bottom: 25px;
        }

        .topbar h3 {
            margin: 0;
            font-weight: 700;
        }

        .topbar p {
            margin: 5px 0 0;
            color: #718096;
        }

        .stat-card {
            padding: 20px;
            height: 100%;
        }

        .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #e6f1ec;
            color: #12372A;
            font-size: 20px;
        }

        .stat-number {
            margin-top: 12px;
            font-size: 27px;
            font-weight: 700;
        }

        .stat-label,
        .animal-meta {
            color: #718096;
            font-size: 13px;
        }

        .filter-card {
            padding: 20px;
            margin-bottom: 24px;
        }

        .livestock-card {
            height: 100%;
            overflow: hidden;
            background: #fff;
            border: 1px solid #edf0f2;
            border-radius: 14px;
            transition: 0.2s;
        }

        .livestock-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .livestock-image {
            width: 100%;
            height: 205px;
            object-fit: cover;
            background: #eef1f0;
        }

        .livestock-body {
            padding: 18px;
        }

        .animal-title {
            margin-bottom: 8px;
            font-size: 19px;
            font-weight: 700;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 9px;
            margin: 15px 0;
        }

        .detail-item {
            padding: 9px;
            background: #f7faf8;
            border-radius: 8px;
        }

        .detail-label {
            display: block;
            color: #718096;
            font-size: 11px;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }

        @media (max-width: 767px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }
        }
    </style>
    <link href="../assets/css/farmer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/farmer-nav.php'; ?>

<main class="main">
    <header class="topbar d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h3>My Livestock</h3>
            <p>Welcome, <?= htmlspecialchars($farmerName ?? 'Farmer', ENT_QUOTES, 'UTF-8') ?>. Manage your listings and auctions.</p>
        </div>
        <a href="add-livestock.php" class="btn btn-success">
            <i class="bi bi-plus-circle me-1"></i> Add Livestock
        </a>
    </header>

    <?php if ($actionSuccess !== ''): ?>
        <div class="alert alert-success" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($actionSuccess, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($actionError !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="row g-4 mb-4" aria-label="Livestock statistics">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                <div class="stat-number"><?= (int) ($livestockStats['total_livestock'] ?? 0) ?></div>
                <div class="stat-label">Total Livestock Registered</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
                <div class="stat-number"><?= (int) ($livestockStats['available_livestock'] ?? 0) ?></div>
                <div class="stat-label">Available for Auction</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-hammer"></i></div>
                <div class="stat-number"><?= $activeAuctions ?></div>
                <div class="stat-label">Active Auctions</div>
            </div>
        </div>
    </section>

    <section class="filter-card">
        <form method="GET" action="livestock.php" class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label for="search" class="form-label fw-semibold">Search livestock</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        class="form-control"
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Animal, breed, or location"
                    >
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <label for="animal_type" class="form-label fw-semibold">Animal Type</label>
                <select id="animal_type" name="animal_type" class="form-select">
                    <option value="">All animal types</option>
                    <?php foreach ($animalTypes as $type): ?>
                        <option
                            value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $animalTypeFilter === $type ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label for="gender" class="form-label fw-semibold">Gender</label>
                <select id="gender" name="gender" class="form-select">
                    <option value="">All</option>
                    <option value="male" <?= $genderFilter === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= $genderFilter === 'female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-4">
                <label for="status" class="form-label fw-semibold">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-4 d-grid">
                <button type="submit" class="btn btn-outline-success">
                    <i class="bi bi-funnel"></i><span class="d-lg-none ms-1">Filter</span>
                </button>
            </div>
        </form>
    </section>

    <section aria-labelledby="listings-heading">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 id="listings-heading" class="h4 mb-0">Your Livestock</h2>
            <span class="badge bg-success rounded-pill"><?= count($livestockList) ?> listing(s)</span>
        </div>

        <?php if (empty($livestockList)): ?>
            <div class="text-center bg-white rounded-4 shadow-sm py-5 px-3">
                <i class="bi bi-box-seam fs-1 text-muted"></i>
                <h4 class="mt-3">
                    <?= $search !== '' || $animalTypeFilter !== '' || $genderFilter !== '' || $statusFilter !== ''
                        ? 'No livestock matches your filters'
                        : 'No livestock registered yet' ?>
                </h4>
                <p class="text-muted">Try changing the filters or add a livestock listing to get started.</p>
                <?php if ($search !== '' || $animalTypeFilter !== '' || $genderFilter !== '' || $statusFilter !== ''): ?>
                    <a href="livestock.php" class="btn btn-outline-success me-2">Clear Filters</a>
                <?php endif; ?>
                <a href="add-livestock.php" class="btn btn-success">Add Livestock</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($livestockList as $animal): ?>
                    <?php
                    $image = !empty($animal['image'])
                        ? '../uploads/livestock/' . $animal['image']
                        : '../assets/images/GANADO DOBLE PROPOSITO.png';
                    $statusClass = $animal['status'] === 'available'
                        ? 'bg-success'
                        : ($animal['status'] === 'auctioned' ? 'bg-primary' : 'bg-secondary');
                    ?>
                    <div class="col-md-6 col-xl-4" id="livestock-<?= (int) $animal['id'] ?>">
                        <article class="livestock-card">
                            <img
                                src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($animal['animal_type'], ENT_QUOTES, 'UTF-8') ?>"
                                class="livestock-image"
                            >
                            <div class="livestock-body">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="animal-title">
                                        <?= htmlspecialchars($animal['animal_type'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($animal['breed'])): ?>
                                            <div class="text-muted fw-normal fs-6"><?= htmlspecialchars($animal['breed'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= htmlspecialchars(ucfirst($animal['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>

                                <div class="details-grid">
                                    <div class="detail-item">
                                        <span class="detail-label">Gender</span>
                                        <span class="detail-value"><?= htmlspecialchars(ucfirst($animal['gender']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Weight</span>
                                        <span class="detail-value"><?= $animal['weight'] !== null ? htmlspecialchars($animal['weight'], ENT_QUOTES, 'UTF-8') . ' kg' : 'Not specified' ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Age</span>
                                        <span class="detail-value"><?= $animal['age'] !== null ? (int) $animal['age'] . ' months' : 'Not specified' ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Health</span>
                                        <span class="detail-value"><?= htmlspecialchars($animal['health_status'] ?? 'Not specified', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>

                                <div class="animal-meta mb-3">
                                    <i class="bi bi-geo-alt me-1"></i>
                                    <?= htmlspecialchars($animal['location'] ?? 'Location not specified', ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div class="d-flex gap-2">
                                    <a
                                        href="edit-livestock.php?id=<?= (int) $animal['id'] ?>"
                                        class="btn btn-outline-success flex-fill"
                                    >
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </a>

                                    <?php if ($animal['status'] === 'available' && empty($animal['current_auction_id'])): ?>
                                        <a
                                            href="create-auction.php?livestock_id=<?= (int) $animal['id'] ?>"
                                            class="btn btn-success flex-fill"
                                        >
                                            <i class="bi bi-hammer me-1"></i> Create Auction
                                        </a>
                                    <?php elseif (!empty($animal['current_auction_id'])): ?>
                                        <a href="auctions.php" class="btn btn-outline-primary flex-fill">
                                            <i class="bi bi-eye me-1"></i>
                                            <?= $animal['current_auction_status'] === 'active' ? 'View Auction' : 'Auction Pending' ?>
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary flex-fill" disabled>
                                            Not Available
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>