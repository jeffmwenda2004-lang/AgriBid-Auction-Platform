<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$farmerName = getUserName();

$csrfToken = getCsrfToken();

$successMessage = $_SESSION['farmer_auction_success'] ?? '';
$errorMessage = $_SESSION['farmer_auction_error'] ?? '';
unset($_SESSION['farmer_auction_success'], $_SESSION['farmer_auction_error']);

$errors = [];
$oldInput = $_SESSION['farmer_auction_old_input'] ?? [];
unset($_SESSION['farmer_auction_old_input']);

$selectedLivestockId = filter_input(
    INPUT_GET,
    'livestock_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if (isset($oldInput['livestock_id'])) {
    $selectedLivestockId = (int) $oldInput['livestock_id'];
}

$startingPrice = $oldInput['starting_price'] ?? '';
$minimumIncrement = $oldInput['minimum_bid_increment'] ?? '100';
$startTimeInput = $oldInput['start_time'] ?? '';
$endTimeInput = $oldInput['end_time'] ?? '';

$livestockStmt = $pdo->prepare("
    SELECT
        l.id,
        l.animal_type,
        l.breed,
        l.gender,
        l.age,
        l.weight,
        l.health_status,
        l.location,
        l.image
    FROM livestock l
    WHERE l.farmer_id = ?
      AND l.status = 'available'
      AND l.moderation_status = 'approved'
    ORDER BY l.created_at DESC, l.id DESC
");
$livestockStmt->execute([$farmerId]);
$eligibleLivestock = $livestockStmt->fetchAll();

$databaseTime = new DateTimeImmutable((string) $pdo->query('SELECT NOW()')->fetchColumn());
$minimumStartInput = $databaseTime->format('Y-m-d\TH:i');
if ($startTimeInput === '') {
    $startTimeInput = $databaseTime->modify('+5 minutes')->format('Y-m-d\TH:i');
}
if ($endTimeInput === '') {
    $endTimeInput = $databaseTime->modify('+65 minutes')->format('Y-m-d\TH:i');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $rawLivestockId = $_POST['livestock_id'] ?? '';
    $rawStartingPrice = $_POST['starting_price'] ?? '';
    $rawMinimumIncrement = $_POST['minimum_bid_increment'] ?? '';
    $rawStartTime = $_POST['start_time'] ?? '';
    $rawEndTime = $_POST['end_time'] ?? '';

    $oldInput = [
        'livestock_id' => is_scalar($rawLivestockId) ? (string) $rawLivestockId : '',
        'starting_price' => is_scalar($rawStartingPrice) ? (string) $rawStartingPrice : '',
        'minimum_bid_increment' => is_scalar($rawMinimumIncrement) ? (string) $rawMinimumIncrement : '',
        'start_time' => is_scalar($rawStartTime) ? (string) $rawStartTime : '',
        'end_time' => is_scalar($rawEndTime) ? (string) $rawEndTime : '',
    ];

    $selectedLivestockId = filter_var(
        $oldInput['livestock_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    ) ?: null;
    $startingPrice = $oldInput['starting_price'];
    $minimumIncrement = $oldInput['minimum_bid_increment'];
    $startTimeInput = $oldInput['start_time'];
    $endTimeInput = $oldInput['end_time'];

    if (
        !isValidCsrfToken($postedToken)
    ) {
        $errors[] = 'Your form session expired. Please reload the page and try again.';
    }

    $ownedEligible = false;
    foreach ($eligibleLivestock as $animal) {
        if ((int) $animal['id'] === (int) $selectedLivestockId) {
            $ownedEligible = true;
            break;
        }
    }
    if (!$ownedEligible) {
        $errors[] = 'Select available livestock that belongs to your account.';
    }

    $isValidMoney = static function (string $value): bool {
        return preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value) === 1
            && (float) $value > 0;
    };
    if (!$isValidMoney($startingPrice)) {
        $errors[] = 'Starting price must be a positive KES amount with no more than two decimal places.';
    }
    if (!$isValidMoney($minimumIncrement)) {
        $errors[] = 'Minimum bid increment must be a positive KES amount with no more than two decimal places.';
    }

    $parseDateTime = static function (string $value): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value
        ) {
            return null;
        }

        return $date;
    };

    $startDateTime = $parseDateTime($startTimeInput);
    $endDateTime = $parseDateTime($endTimeInput);
    if ($startDateTime === null) {
        $errors[] = 'Enter a valid auction start date and time.';
    } elseif ($startDateTime < $databaseTime) {
        $errors[] = 'Auction start time cannot be in the past.';
    }
    if ($endDateTime === null) {
        $errors[] = 'Enter a valid auction end date and time.';
    } elseif ($startDateTime !== null && $endDateTime <= $startDateTime) {
        $errors[] = 'Auction end time must be later than its start time.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $lockLivestock = $pdo->prepare("
                SELECT id
                FROM livestock
                WHERE id = ? AND farmer_id = ? AND status = 'available'
                  AND moderation_status = 'approved'
                FOR UPDATE
            ");
            $lockLivestock->execute([$selectedLivestockId, $farmerId]);
            if (!$lockLivestock->fetchColumn()) {
                throw new DomainException('Selected livestock is no longer available or is not owned by your account.');
            }

            $startSql = $startDateTime->format('Y-m-d H:i:s');
            $endSql = $endDateTime->format('Y-m-d H:i:s');

            $overlapStmt = $pdo->prepare("
                SELECT id
                FROM auctions
                WHERE livestock_id = ?
                  AND farmer_id = ?
                  AND status <> 'cancelled'
                  AND start_time < ?
                  AND end_time > ?
                LIMIT 1
                FOR UPDATE
            ");
            $overlapStmt->execute([$selectedLivestockId, $farmerId, $endSql, $startSql]);
            if ($overlapStmt->fetch()) {
                throw new DomainException('This livestock already has an auction scheduled during that time.');
            }

            $insertAuction = $pdo->prepare("
                INSERT INTO auctions
                    (livestock_id, farmer_id, starting_price, minimum_bid_increment, start_time, end_time, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insertAuction->execute([
                $selectedLivestockId,
                $farmerId,
                $startingPrice,
                $minimumIncrement,
                $startSql,
                $endSql,
                'pending',
            ]);

            $pdo->commit();

            $_SESSION['farmer_auction_success'] = 'Auction scheduled. It will go live automatically at its start time once the livestock is approved.';
            unset($_SESSION['farmer_auction_old_input']);
            header('Location: auctions.php');
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Farmer auction creation failed: ' . $exception->getMessage());
            $errors[] = 'The auction could not be created right now. Please try again.';
        }
    }
}

$escape = 'escapeHtml';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Auction | Digital Livestock Auction</title>
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
        .topbar, .form-card, .livestock-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
        .topbar p { margin: 5px 0 0; color: #718096; }
        .form-card { padding: 24px; }
        .livestock-card {
            padding: 12px; border: 2px solid #edf0f2; cursor: pointer; height: 100%;
            transition: border-color .15s, background .15s;
        }
        .livestock-option:checked + .livestock-card {
            border-color: #198754; background: #f4faf6;
        }
        .livestock-option:focus-visible + .livestock-card {
            outline: 3px solid rgba(25,135,84,.25);
        }
        .animal-image {
            width: 100%; height: 135px; object-fit: cover;
            border-radius: 9px; background: #eef1f0;
        }
        .form-control, .form-select { min-height: 46px; }
        .price-prefix {
            background: #e6f1ec; color: #12372A; font-weight: 700;
        }
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
            <h1>Create Auction</h1>
            <p>Welcome, <?= $escape($farmerName ?? 'Farmer') ?>. Select your livestock and schedule bidding.</p>
        </div>
        <a href="auctions.php" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> My Auctions
        </a>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="alert"><?= $escape($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Please correct the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (empty($eligibleLivestock)): ?>
        <div class="form-card text-center py-5">
            <i class="bi bi-box-seam fs-1 text-muted"></i>
            <h2 class="h4 mt-3">No available livestock</h2>
            <p class="text-muted">Add an available livestock listing before creating an auction.</p>
            <a href="add-livestock.php" class="btn btn-success">Add Livestock</a>
            <a href="livestock.php" class="btn btn-outline-secondary">View Livestock</a>
        </div>
    <?php else: ?>
        <form method="POST" action="create-auction.php" class="form-card">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

            <section class="mb-4" aria-labelledby="select-livestock-heading">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 id="select-livestock-heading" class="h5 mb-0">1. Select Livestock</h2>
                    <span class="text-muted small">Only livestock registered to your account is shown.</span>
                </div>

                <div class="row g-3">
                    <?php foreach ($eligibleLivestock as $animal): ?>
                        <?php
                        $animalImage = !empty($animal['image'])
                            ? '../uploads/livestock/' . $animal['image']
                            : '../assets/images/GANADO DOBLE PROPOSITO.png';
                        $animalSelected = (int) $selectedLivestockId === (int) $animal['id'];
                        ?>
                        <div class="col-md-6 col-xl-4">
                            <input
                                type="radio"
                                class="visually-hidden livestock-option"
                                id="livestock-<?= (int) $animal['id'] ?>"
                                name="livestock_id"
                                value="<?= (int) $animal['id'] ?>"
                                required
                                <?= $animalSelected ? 'checked' : '' ?>
                            >
                            <label class="livestock-card d-block" for="livestock-<?= (int) $animal['id'] ?>">
                                <img
                                    src="<?= $escape($animalImage) ?>"
                                    alt="<?= $escape($animal['animal_type']) ?>"
                                    class="animal-image mb-2"
                                >
                                <strong><?= $escape($animal['animal_type']) ?></strong>
                                <?php if (!empty($animal['breed'])): ?>
                                    <span class="text-muted"> · <?= $escape($animal['breed']) ?></span>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">
                                    <?= $animal['weight'] !== null ? $escape($animal['weight']) . ' kg' : 'Weight not specified' ?>
                                    · <?= $escape(ucfirst($animal['gender'])) ?>
                                </div>
                                <div class="small text-muted">
                                    Health: <?= $escape($animal['health_status'] ?? 'Not specified') ?>
                                </div>
                                <div class="small text-muted">
                                    <?= $escape($animal['location'] ?? 'Location not specified') ?>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <hr>
            <section aria-labelledby="auction-settings-heading">
                <h2 id="auction-settings-heading" class="h5 mb-3">2. Auction Settings</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="starting_price" class="form-label fw-semibold">Starting Price <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text price-prefix">KES</span>
                            <input
                                type="number"
                                id="starting_price"
                                name="starting_price"
                                class="form-control"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                value="<?= $escape($startingPrice) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="minimum_bid_increment" class="form-label fw-semibold">Minimum Bid Increment <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text price-prefix">KES</span>
                            <input
                                type="number"
                                id="minimum_bid_increment"
                                name="minimum_bid_increment"
                                class="form-control"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                value="<?= $escape($minimumIncrement) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="start_time" class="form-label fw-semibold">Auction Start <span class="text-danger">*</span></label>
                        <input
                            type="datetime-local"
                            id="start_time"
                            name="start_time"
                            class="form-control"
                            min="<?= $escape($minimumStartInput) ?>"
                            value="<?= $escape($startTimeInput) ?>"
                            required
                        >
                        <div class="form-text">Bidding is enabled only once the scheduled start time is reached.</div>
                    </div>

                    <div class="col-md-6">
                        <label for="end_time" class="form-label fw-semibold">Auction End <span class="text-danger">*</span></label>
                        <input
                            type="datetime-local"
                            id="end_time"
                            name="end_time"
                            class="form-control"
                            value="<?= $escape($endTimeInput) ?>"
                            required
                        >
                    </div>
                </div>
            </section>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-hammer me-1"></i> Create Auction
                </button>
                <a href="livestock.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</main>

<script>
    const startInput = document.getElementById('start_time');
    const endInput = document.getElementById('end_time');
    if (startInput && endInput) {
        startInput.addEventListener('change', function () {
            endInput.min = startInput.value;
        });
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>