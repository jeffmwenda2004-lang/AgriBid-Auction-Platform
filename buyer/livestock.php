<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$search = trim($_GET['search'] ?? '');
$animalType = trim($_GET['animal_type'] ?? '');
$gender = trim($_GET['gender'] ?? '');

$animalTypes = $pdo->query(
    "SELECT DISTINCT animal_type
     FROM livestock
     WHERE status = 'available'
       AND moderation_status = 'approved'
     ORDER BY animal_type ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$where = ["l.status = 'available'", "l.moderation_status = 'approved'"];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(l.animal_type LIKE ? OR l.breed LIKE ? OR l.location LIKE ? OR l.health_status LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if ($animalType !== '') {
    $where[] = 'l.animal_type = ?';
    $params[] = $animalType;
}

if ($gender !== '') {
    $where[] = 'l.gender = ?';
    $params[] = $gender;
}

$sql = "
    SELECT
        l.*,
        a.id AS auction_id,
        a.starting_price,
        a.end_time,
        a.status AS auction_status
    FROM livestock l
    LEFT JOIN auctions a ON a.livestock_id = l.id AND a.status = 'active'
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$livestock = $stmt->fetchAll();

$buyerName = getUserName();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Livestock | Digital Livestock Auction</title>
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
            color: #ffffff;
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
            color: #ffffff;
        }

        .sidebar .logout {
            margin-top: 30px;
            color: #ffcccc;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar {
            background: #ffffff;
            border-radius: 14px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .welcome h3 {
            margin: 0;
            font-weight: 700;
        }

        .welcome p {
            margin: 5px 0 0;
            color: #718096;
        }

        .filters-card,
        .section-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        }

        .animal-card {
            border: 1px solid #edf0f2;
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
            transition: 0.2s;
            background: #fff;
        }

        .animal-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 22px rgba(0,0,0,0.08);
        }

        .animal-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            background: #eef1f0;
        }

        .animal-body {
            padding: 18px;
        }

        .animal-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .meta-pill {
            background: #edf7f1;
            color: #12372A;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .text-muted-small {
            color: #718096;
            font-size: 13px;
        }

        .price-box {
            font-size: 24px;
            font-weight: 700;
            color: #12372A;
            margin: 10px 0 15px;
        }

        @media (max-width: 991px) {
            .sidebar {
                width: 220px;
            }

            .main {
                margin-left: 220px;
            }
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
    <link href="../assets/css/buyer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/buyer-nav.php'; ?>

<div class="main">
    <div class="topbar">
        <div class="welcome">
            <h3>Browse Livestock, <?= htmlspecialchars($buyerName ?? 'Buyer') ?> 👋</h3>
            <p>Search available animals and choose the right livestock for auction.</p>
        </div>
    </div>

    <div class="filters-card mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        class="form-control"
                        name="search"
                        placeholder="Animal type, breed, location..."
                        value="<?= htmlspecialchars($search) ?>"
                    >
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Animal Type</label>
                <select name="animal_type" class="form-select">
                    <option value="">All types</option>
                    <?php foreach ($animalTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $animalType === $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Gender</label>
                <select name="gender" class="form-select">
                    <option value="">All genders</option>
                    <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <div class="section-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-0">Available Livestock</h4>
            </div>
            <span class="badge bg-success rounded-pill fs-6"><?= count($livestock) ?> item(s)</span>
        </div>

        <?php if (empty($livestock)): ?>
            <div class="text-center py-5">
                <i class="bi bi-box-seam fs-1 text-muted"></i>
                <h5 class="mt-3">No livestock matches your filter</h5>
                <p class="text-muted mb-0">Try adjusting your search or clear the filters to see all available animals.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($livestock as $animal): ?>
                    <?php
                    $image = !empty($animal['image'])
                        ? '../uploads/livestock/' . $animal['image']
                        : '../assets/images/GANADO DOBLE PROPOSITO.png';

                    $startingPrice = !empty($animal['starting_price'])
                        ? (float) $animal['starting_price']
                        : 0;
                    ?>

                    <div class="col-md-6 col-xl-4">
                        <div class="animal-card">
                            <img
                                src="<?= htmlspecialchars($image) ?>"
                                alt="<?= htmlspecialchars($animal['animal_type']) ?>"
                                class="animal-image"
                            >

                            <div class="animal-body">
                                <div class="animal-name">
                                    <?= htmlspecialchars($animal['animal_type']) ?>
                                    <?php if (!empty($animal['breed'])): ?>
                                        <small class="text-muted">- <?= htmlspecialchars($animal['breed']) ?></small>
                                    <?php endif; ?>
                                </div>

                                <div class="meta-row">
                                    <span class="meta-pill"><?= htmlspecialchars(ucfirst($animal['gender'])) ?></span>
                                    <?php if (!empty($animal['weight'])): ?>
                                        <span class="meta-pill"><?= htmlspecialchars($animal['weight']) ?> kg</span>
                                    <?php endif; ?>
                                    <?php if (!empty($animal['health_status'])): ?>
                                        <span class="meta-pill"><?= htmlspecialchars($animal['health_status']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="text-muted-small mb-2">
                                    <i class="bi bi-geo-alt"></i>
                                    <?= htmlspecialchars($animal['location'] ?? 'Location unavailable') ?>
                                </div>

                                <div class="price-box">
                                    KES <?= number_format($startingPrice, 2) ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted-small">
                                        <?php if (!empty($animal['auction_id'])): ?>
                                            <span class="text-success fw-semibold">Auction live</span>
                                        <?php else: ?>
                                            <span class="text-warning fw-semibold">Awaiting auction</span>
                                        <?php endif; ?>
                                    </div>

                                    <a href="livestock-details.php?id=<?= (int) $animal['id'] ?>" class="btn btn-success btn-sm">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
