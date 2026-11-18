<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$livestockId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$livestockId) {
    $_SESSION['farmer_livestock_error'] = 'Choose a valid livestock listing to edit.';
    header('Location: livestock.php');
    exit;
}

$livestockStmt = $pdo->prepare("
    SELECT id, animal_type, breed, gender, age, weight, health_status, description, location, image, status
    FROM livestock
    WHERE id = ? AND farmer_id = ?
    LIMIT 1
");
$livestockStmt->execute([$livestockId, $farmerId]);
$livestock = $livestockStmt->fetch();

if (!$livestock) {
    http_response_code(404);
    $_SESSION['farmer_livestock_error'] = 'Livestock listing not found or you do not have permission to edit it.';
    header('Location: livestock.php');
    exit;
}

if (!in_array($livestock['status'], ['available', 'inactive'], true)) {
    $_SESSION['farmer_livestock_error'] = 'This livestock listing cannot be edited in its current status.';
    header('Location: livestock.php');
    exit;
}

$auctionStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM auctions
    WHERE livestock_id = ?
      AND farmer_id = ?
      AND status IN ('pending', 'active')
      AND end_time > NOW()
");
$auctionStmt->execute([$livestockId, $farmerId]);
if ((int) $auctionStmt->fetchColumn() > 0) {
    $_SESSION['farmer_livestock_error'] = 'Livestock cannot be edited while it has a pending or active auction.';
    header('Location: livestock.php');
    exit;
}

$csrfToken = getCsrfToken();
$error = $_SESSION['farmer_livestock_error'] ?? '';
unset($_SESSION['farmer_livestock_error']);

$e = 'escapeHtml';

$currentImage = !empty($livestock['image'])
    ? '../uploads/livestock/' . $livestock['image']
    : '../assets/images/GANADO DOBLE PROPOSITO.png';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Livestock | Digital Livestock Auction</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7f9; color: #263238; }
        .sidebar {
            width: 250px; min-height: 100vh; background: #12372A;
            position: fixed; left: 0; top: 0; padding: 25px 15px; z-index: 1000;
        }
        .brand { color: #fff; font-size: 20px; font-weight: 700; padding: 10px 15px 30px; }
        .brand i { margin-right: 8px; }
        .sidebar a {
            display: flex; align-items: center; gap: 12px; color: #dce8e2;
            text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 5px;
        }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.12); color: #fff; }
        .sidebar .logout { margin-top: 30px; color: #ffcccc; }
        .main { margin-left: 250px; padding: 30px; }
        .topbar, .form-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
        .topbar p { margin: 5px 0 0; color: #718096; }
        .form-card { padding: 26px; }
        .form-control, .form-select { min-height: 46px; }
        textarea.form-control { min-height: 120px; }
        .current-image {
            display: block; width: 180px; height: 135px; object-fit: cover;
            border-radius: 10px; background: #eef1f0; margin-bottom: 10px;
        }
        .required-mark { color: #dc3545; }
        @media (max-width: 991px) {
            .sidebar { width: 220px; }
            .main { margin-left: 220px; }
        }
        @media (max-width: 767px) {
            .sidebar { position: relative; width: 100%; min-height: auto; }
            .main { margin-left: 0; padding: 20px; }
        }
    </style>
    <link href="../assets/css/farmer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/farmer-nav.php'; ?>

<main class="main">
    <header class="topbar d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1>Edit Livestock</h1>
            <p>Update the listing details. Auctioned or sold livestock cannot be edited.</p>
        </div>
        <a href="livestock.php" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Back to Livestock
        </a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= $e($error) ?>
        </div>
    <?php endif; ?>

    <section class="form-card">
        <form action="action.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="livestock_id" value="<?= (int) $livestock['id'] ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="animal_type" class="form-label fw-semibold">Animal Type <span class="required-mark">*</span></label>
                    <input type="text" id="animal_type" name="animal_type" class="form-control" maxlength="50" required value="<?= $e($livestock['animal_type']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="breed" class="form-label fw-semibold">Breed</label>
                    <input type="text" id="breed" name="breed" class="form-control" maxlength="100" value="<?= $e($livestock['breed']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="gender" class="form-label fw-semibold">Gender <span class="required-mark">*</span></label>
                    <select id="gender" name="gender" class="form-select" required>
                        <option value="">Select gender</option>
                        <option value="male" <?= $livestock['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $livestock['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="age" class="form-label fw-semibold">Age (months)</label>
                    <input type="number" id="age" name="age" class="form-control" min="0" max="2000" step="1" value="<?= $e($livestock['age']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="weight" class="form-label fw-semibold">Weight (kg)</label>
                    <input type="number" id="weight" name="weight" class="form-control" min="0.01" max="99999999.99" step="0.01" value="<?= $e($livestock['weight']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="health_status" class="form-label fw-semibold">Health Status</label>
                    <input type="text" id="health_status" name="health_status" class="form-control" maxlength="100" value="<?= $e($livestock['health_status']) ?>">
                </div>

                <div class="col-md-6">
                    <label for="location" class="form-label fw-semibold">Location</label>
                    <input type="text" id="location" name="location" class="form-control" maxlength="150" value="<?= $e($livestock['location']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Current Photo</label>
                    <img
                        src="<?= $e($currentImage) ?>"
                        alt="<?= $e($livestock['animal_type']) ?>"
                        class="current-image"
                    >
                    <label for="image" class="form-label fw-semibold">Replace Photo (optional)</label>
                    <input type="file" id="image" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <div class="form-text">JPEG, PNG, or WebP, maximum 5 MB. Leave blank to keep the current photo.</div>
                </div>

                <div class="col-12">
                    <label for="description" class="form-label fw-semibold">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="4"><?= $e($livestock['description']) ?></textarea>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                    <a href="livestock.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>

        <hr class="my-4">

        <form action="action.php" method="POST" data-confirm="Delete this livestock record? This action cannot be undone.">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="livestock_id" value="<?= (int) $livestock['id'] ?>">
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i> Delete Livestock
            </button>
            <span class="form-text d-block mt-2">Deletion is available only when the livestock has no auction history.</span>
        </form>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>