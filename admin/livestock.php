<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminName = getUserName();
$escape = 'escapeHtml';

$csrfToken = getCsrfToken();

$flashSuccess = $_SESSION['admin_livestock_success'] ?? '';
$flashError = $_SESSION['admin_livestock_error'] ?? '';
unset($_SESSION['admin_livestock_success'], $_SESSION['admin_livestock_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedToken = $_POST['csrf_token'] ?? '';
	$livestockId = filter_var($_POST['livestock_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	$action = $_POST['action'] ?? '';
	$reason = $_POST['moderation_reason'] ?? '';

	if (!isValidCsrfToken($postedToken)) {
		$_SESSION['admin_livestock_error'] = 'Your form session expired. Please reload and try again.';
	} elseif (!$livestockId || !is_string($action) || !in_array($action, ['approve', 'reject', 'suspend', 'reinstate'], true)) {
		$_SESSION['admin_livestock_error'] = 'Choose a valid livestock record and action.';
	} elseif (!is_string($reason) || strlen($reason) > 2000) {
		$_SESSION['admin_livestock_error'] = 'The moderation reason must be 2000 characters or fewer.';
	} elseif ($action === 'reject' && trim($reason) === '') {
		$_SESSION['admin_livestock_error'] = 'Provide a reason when rejecting a livestock listing.';
	} else {
		try {
			$pdo->beginTransaction();
			$recordStmt = $pdo->prepare(
				'SELECT moderation_status FROM livestock WHERE id = ? FOR UPDATE'
			);
			$recordStmt->execute([$livestockId]);
			$record = $recordStmt->fetch();

			if (!$record) {
				throw new DomainException('Livestock record not found.');
			}

			$currentStatus = $record['moderation_status'];
			$nextStatus = null;
			$nextReason = trim($reason) === '' ? null : trim($reason);
			$successMessage = '';

			if ($action === 'approve' && in_array($currentStatus, ['pending', 'rejected', 'suspended'], true)) {
				$nextStatus = 'approved';
				$nextReason = null;
				$successMessage = 'Livestock listing approved.';
			} elseif ($action === 'reject' && $currentStatus === 'pending') {
				$nextStatus = 'rejected';
				$successMessage = 'Livestock listing rejected.';
			} elseif ($action === 'suspend' && $currentStatus === 'approved') {
				$nextStatus = 'suspended';
				$successMessage = 'Livestock listing suspended.';
			} elseif ($action === 'reinstate' && $currentStatus === 'suspended') {
				$nextStatus = 'approved';
				$nextReason = null;
				$successMessage = 'Livestock listing reinstated.';
			} else {
				throw new DomainException('That action is not available for the listing’s current status.');
			}

			$updateStmt = $pdo->prepare(
				'UPDATE livestock SET moderation_status = ?, moderation_reason = ? WHERE id = ?'
			);
			$updateStmt->execute([$nextStatus, $nextReason, $livestockId]);
			$pdo->commit();
			$_SESSION['admin_livestock_success'] = $successMessage;
		} catch (DomainException $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$_SESSION['admin_livestock_error'] = $exception->getMessage();
		} catch (PDOException $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Admin livestock moderation failed: ' . $exception->getMessage());
			$_SESSION['admin_livestock_error'] = 'Unable to update the livestock listing right now. Please try again.';
		}
	}

	$redirectParams = [];
	foreach (['search', 'status', 'page'] as $key) {
		$value = $_POST[$key] ?? '';
		if (is_scalar($value) && (string) $value !== '') {
			$redirectParams[$key] = (string) $value;
		}
	}
	header('Location: livestock.php' . ($redirectParams === [] ? '' : '?' . http_build_query($redirectParams)));
	exit;
}

$searchInput = $_GET['search'] ?? '';
$statusInput = $_GET['status'] ?? '';
$search = is_string($searchInput) ? substr(trim($searchInput), 0, 100) : '';
$status = is_string($statusInput) ? $statusInput : '';
$validStatuses = ['pending', 'approved', 'rejected', 'suspended'];
if (!in_array($status, $validStatuses, true)) {
	$status = '';
}

$stats = $pdo->query(
	"SELECT
		COUNT(*) AS total,
		COALESCE(SUM(moderation_status = 'pending'), 0) AS pending,
		COALESCE(SUM(moderation_status = 'approved'), 0) AS approved,
		COALESCE(SUM(moderation_status = 'rejected'), 0) AS rejected,
		COALESCE(SUM(moderation_status = 'suspended'), 0) AS suspended
	 FROM livestock"
)->fetch();

$conditions = [];
$params = [];
if ($search !== '') {
	$conditions[] = '(l.animal_type LIKE ? OR l.breed LIKE ? OR l.location LIKE ? OR farmer.name LIKE ? OR farmer.email LIKE ? OR CAST(l.id AS CHAR) = ?)';
	$like = '%' . $search . '%';
	array_push($params, $like, $like, $like, $like, $like, $search);
}
if ($status !== '') {
	$conditions[] = 'l.moderation_status = ?';
	$params[] = $status;
}
$whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare(
	"SELECT COUNT(*)
	 FROM livestock l
	 INNER JOIN users farmer ON farmer.id = l.farmer_id
	 $whereSql"
);
$countStmt->execute($params);
$filteredCount = (int) $countStmt->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int) ceil($filteredCount / $perPage));
$pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $pageInput === false || $pageInput === null ? 1 : max(1, $pageInput);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$livestockStmt = $pdo->prepare(
	"SELECT
		l.id, l.animal_type, l.breed, l.gender, l.age, l.weight, l.health_status,
		l.description, l.location, l.image, l.status, l.moderation_status,
		l.moderation_reason, l.created_at, farmer.name AS farmer_name, farmer.email AS farmer_email
	 FROM livestock l
	 INNER JOIN users farmer ON farmer.id = l.farmer_id
	 $whereSql
	 ORDER BY FIELD(l.moderation_status, 'pending', 'approved', 'rejected', 'suspended'), l.created_at DESC, l.id DESC
	 LIMIT $perPage OFFSET $offset"
);
$livestockStmt->execute($params);
$livestockRecords = $livestockStmt->fetchAll();

$queryParams = array_filter([
	'search' => $search,
	'status' => $status,
], static fn ($value): bool => $value !== '');
$statusClasses = [
	'pending' => 'warning',
	'approved' => 'success',
	'rejected' => 'danger',
	'suspended' => 'secondary',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Livestock Management | Digital Livestock Auction</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
	<style>
		body { background: #f5f7f9; color: #263238; }
		.sidebar { width: 250px; min-height: 100vh; background: #12372A; position: fixed; inset: 0 auto 0 0; padding: 25px 15px; z-index: 1000; }
		.brand { color: #fff; font-size: 20px; font-weight: 700; padding: 10px 15px 30px; }
		.brand i { margin-right: 8px; }
		.sidebar a { display: flex; align-items: center; gap: 12px; color: #dce8e2; text-decoration: none; padding: 12px 15px; border-radius: 8px; margin-bottom: 5px; }
		.sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.12); color: #fff; }
		.sidebar .logout { margin-top: 30px; color: #ffcccc; }
		.main { margin-left: 250px; padding: 30px; }
		.topbar, .stat-card, .content-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
		.topbar { padding: 20px 25px; margin-bottom: 25px; }
		.topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
		.topbar p { margin: 5px 0 0; color: #718096; }
		.stat-card, .content-card { padding: 20px; }
		.stat-label { color: #718096; font-size: 13px; }
		.stat-number { margin-top: 7px; color: #12372A; font-size: 26px; font-weight: 700; }
		.livestock-image { width: 68px; height: 58px; object-fit: cover; border-radius: 6px; background: #eef1f0; }
		.table td, .table th { vertical-align: middle; }
		@media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
		@media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } }
	</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
	<header class="topbar">
		<h1>Livestock Management</h1>
		<p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Review and manage farmer listings.</p>
	</header>

	<?php if ($flashSuccess !== ''): ?><div class="alert alert-success" role="alert"><?= $escape($flashSuccess) ?></div><?php endif; ?>
	<?php if ($flashError !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($flashError) ?></div><?php endif; ?>

	<section class="row g-3 mb-4" aria-label="Livestock moderation summary">
		<?php foreach ([['All listings', 'total'], ['Pending', 'pending'], ['Approved', 'approved'], ['Rejected', 'rejected'], ['Suspended', 'suspended']] as [$label, $key]): ?>
			<div class="col-sm-6 col-xl">
				<article class="stat-card h-100">
					<div class="stat-label"><?= $escape($label) ?></div>
					<div class="stat-number"><?= number_format((int) $stats[$key]) ?></div>
				</article>
			</div>
		<?php endforeach; ?>
	</section>

	<section class="content-card mb-4">
		<form method="GET" action="livestock.php" class="row g-3 align-items-end">
			<div class="col-lg-7">
				<label for="search" class="form-label">Search livestock or farmer</label>
				<input id="search" name="search" type="search" class="form-control" maxlength="100" value="<?= $escape($search) ?>" placeholder="Animal, breed, location, farmer, or ID">
			</div>
			<div class="col-sm-8 col-lg-3">
				<label for="status" class="form-label">Moderation status</label>
				<select id="status" name="status" class="form-select">
					<option value="">All statuses</option>
					<?php foreach ($validStatuses as $moderationStatus): ?>
						<option value="<?= $escape($moderationStatus) ?>" <?= $status === $moderationStatus ? 'selected' : '' ?>><?= $escape(ucfirst($moderationStatus)) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-sm-4 col-lg-2 d-flex gap-2">
				<button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
				<a href="livestock.php" class="btn btn-outline-secondary">Reset</a>
			</div>
		</form>
	</section>

	<section class="content-card">
		<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
			<h2 class="h5 fw-bold mb-0">Listings</h2>
			<span class="text-muted small">Showing <?= $filteredCount === 0 ? 0 : $offset + 1 ?>–<?= min($offset + count($livestockRecords), $filteredCount) ?> of <?= number_format($filteredCount) ?></span>
		</div>

		<?php if ($livestockRecords === []): ?>
			<p class="text-muted mb-0">No livestock listings match these filters.</p>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-hover align-middle">
					<thead>
					<tr><th>Livestock</th><th>Farmer</th><th>Details</th><th>Submitted</th><th>Review status</th><th>Moderation</th><th>Actions</th></tr>
					</thead>
					<tbody>
					<?php foreach ($livestockRecords as $record): ?>
						<?php
						$imageName = !empty($record['image']) ? basename((string) $record['image']) : '';
						$imageUrl = $imageName !== ''
							? '../uploads/livestock/' . rawurlencode($imageName)
							: '../assets/images/GANADO DOBLE PROPOSITO.jfif';
						$moderationStatus = (string) $record['moderation_status'];
						?>
						<tr>
							<td>
								<div class="d-flex align-items-center gap-2">
									<img src="<?= $escape($imageUrl) ?>" alt="" class="livestock-image">
									<div><div class="fw-semibold"><?= $escape($record['animal_type']) ?></div><small class="text-muted"><?= $escape($record['breed'] ?: 'Breed not specified') ?></small></div>
								</div>
							</td>
							<td><?= $escape($record['farmer_name']) ?><small class="d-block text-muted"><?= $escape($record['farmer_email']) ?></small></td>
							<td class="small">
								<?= $escape(ucfirst((string) $record['gender'])) ?>
								<?= $record['age'] !== null ? ' · ' . $escape($record['age']) . ' months' : '' ?>
								<?= $record['weight'] !== null ? ' · ' . $escape($record['weight']) . ' kg' : '' ?>
								<small class="d-block text-muted"><?= $escape($record['location'] ?: 'Location not specified') ?></small>
							</td>
							<td class="text-nowrap"><?= $escape(date('d M Y', strtotime((string) $record['created_at']))) ?></td>
							<td><span class="badge text-bg-<?= $escape($statusClasses[$moderationStatus] ?? 'secondary') ?>"><?= $escape(ucfirst($moderationStatus)) ?></span><small class="d-block text-muted mt-1">Listing: <?= $escape(ucfirst((string) $record['status'])) ?></small></td>
							<td class="small text-break"><?= $record['moderation_reason'] ? $escape($record['moderation_reason']) : '<span class="text-muted">—</span>' ?></td>
							<td>
								<form method="POST" class="d-grid gap-2" style="min-width: 190px">
									<input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
									<input type="hidden" name="livestock_id" value="<?= (int) $record['id'] ?>">
									<input type="hidden" name="search" value="<?= $escape($search) ?>">
									<input type="hidden" name="status" value="<?= $escape($status) ?>">
									<input type="hidden" name="page" value="<?= $page ?>">
									<?php if ($moderationStatus === 'pending'): ?>
										<input type="text" name="moderation_reason" class="form-control form-control-sm" maxlength="2000" placeholder="Reason required to reject">
										<div class="btn-group btn-group-sm">
											<button class="btn btn-success" name="action" value="approve" type="submit">Approve</button>
											<button class="btn btn-outline-danger" name="action" value="reject" type="submit">Reject</button>
										</div>
									<?php elseif ($moderationStatus === 'approved'): ?>
										<input type="text" name="moderation_reason" class="form-control form-control-sm" maxlength="2000" placeholder="Optional suspension reason">
										<button class="btn btn-sm btn-outline-secondary" name="action" value="suspend" type="submit">Suspend</button>
									<?php elseif ($moderationStatus === 'suspended'): ?>
										<button class="btn btn-sm btn-success" name="action" value="reinstate" type="submit">Reinstate</button>
									<?php elseif ($moderationStatus === 'rejected'): ?>
										<button class="btn btn-sm btn-success" name="action" value="approve" type="submit">Approve</button>
									<?php endif; ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ($totalPages > 1): ?>
				<nav aria-label="Livestock pages" class="d-flex justify-content-between align-items-center">
					<a class="btn btn-outline-secondary btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query($queryParams + ['page' => max(1, $page - 1)]) ?>">Previous</a>
					<span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
					<a class="btn btn-outline-secondary btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query($queryParams + ['page' => min($totalPages, $page + 1)]) ?>">Next</a>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
