<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminId = getUserId();
$adminName = getUserName();
$escape = 'escapeHtml';

$csrfToken = getCsrfToken();

$flashSuccess = $_SESSION['admin_users_success'] ?? '';
$flashError = $_SESSION['admin_users_error'] ?? '';
unset($_SESSION['admin_users_success'], $_SESSION['admin_users_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedToken = $_POST['csrf_token'] ?? '';
	$action = $_POST['action'] ?? '';
	$targetId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	$newStatus = $_POST['status'] ?? '';

	if (!isValidCsrfToken($postedToken)) {
		$_SESSION['admin_users_error'] = 'Your form session expired. Please reload and try again.';
	} elseif ($action !== 'change_status' || !$targetId || !is_string($newStatus) || !in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
		$_SESSION['admin_users_error'] = 'Choose a valid user and account status.';
	} elseif ($targetId === $adminId) {
		$_SESSION['admin_users_error'] = 'You cannot change your own account status here.';
	} else {
		try {
			$pdo->beginTransaction();
			$userStmt = $pdo->prepare('SELECT role, status FROM users WHERE id = ? FOR UPDATE');
			$userStmt->execute([$targetId]);
			$targetUser = $userStmt->fetch();

			if (!$targetUser) {
				throw new DomainException('User not found.');
			}
			if ($targetUser['role'] === 'admin') {
				throw new DomainException('Administrator account status cannot be changed here.');
			}
			if ($targetUser['status'] === $newStatus) {
				throw new DomainException('That user already has the selected status.');
			}

			$updateStmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ? AND role <> ?');
			$updateStmt->execute([$newStatus, $targetId, 'admin']);
			$pdo->commit();
			$_SESSION['admin_users_success'] = 'User account status updated.';
		} catch (DomainException $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			$_SESSION['admin_users_error'] = $exception->getMessage();
		} catch (PDOException $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Admin user status update failed: ' . $exception->getMessage());
			$_SESSION['admin_users_error'] = 'Unable to update the user account right now. Please try again.';
		}
	}

	$redirectParams = [];
	foreach (['search', 'role', 'page'] as $key) {
		$value = $_POST[$key] ?? '';
		if (is_scalar($value) && (string) $value !== '') {
			$redirectParams[$key] = (string) $value;
		}
	}
	$statusFilter = $_POST['status_filter'] ?? '';
	if (is_string($statusFilter) && $statusFilter !== '') {
		$redirectParams['status'] = $statusFilter;
	}
	header('Location: users.php' . ($redirectParams === [] ? '' : '?' . http_build_query($redirectParams)));
	exit;
}

$searchInput = $_GET['search'] ?? '';
$roleInput = $_GET['role'] ?? '';
$statusInput = $_GET['status'] ?? '';
$search = is_string($searchInput) ? substr(trim($searchInput), 0, 100) : '';
$role = is_string($roleInput) ? $roleInput : '';
$status = is_string($statusInput) ? $statusInput : '';
$validRoles = ['farmer', 'buyer', 'admin'];
$validStatuses = ['active', 'inactive', 'suspended'];
if (!in_array($role, $validRoles, true)) {
	$role = '';
}
if (!in_array($status, $validStatuses, true)) {
	$status = '';
}

$stats = $pdo->query(
	"SELECT
		COUNT(*) AS total_users,
		COALESCE(SUM(role = 'farmer'), 0) AS farmers,
		COALESCE(SUM(role = 'buyer'), 0) AS buyers,
		COALESCE(SUM(status = 'suspended'), 0) AS suspended"
)->fetch();

$conditions = [];
$params = [];
if ($search !== '') {
	$conditions[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR CAST(id AS CHAR) = ?)';
	$like = '%' . $search . '%';
	array_push($params, $like, $like, $like, $search);
}
if ($role !== '') {
	$conditions[] = 'role = ?';
	$params[] = $role;
}
if ($status !== '') {
	$conditions[] = 'status = ?';
	$params[] = $status;
}
$whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSql");
$countStmt->execute($params);
$filteredCount = (int) $countStmt->fetchColumn();

$perPage = 20;
$totalPages = max(1, (int) ceil($filteredCount / $perPage));
$pageInput = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $pageInput === false || $pageInput === null ? 1 : max(1, $pageInput);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$usersStmt = $pdo->prepare(
	"SELECT
		u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at,
		(SELECT COUNT(*) FROM livestock l WHERE l.farmer_id = u.id) AS livestock_count,
		(SELECT COUNT(*) FROM auctions a WHERE a.farmer_id = u.id) AS auction_count,
		(SELECT COUNT(*) FROM bids b WHERE b.buyer_id = u.id) AS bid_count
	 FROM users u
	 $whereSql
	 ORDER BY u.created_at DESC, u.id DESC
	 LIMIT $perPage OFFSET $offset"
);
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

$queryParams = array_filter([
	'search' => $search,
	'role' => $role,
	'status' => $status,
], static fn ($value): bool => $value !== '');
$statusClasses = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>User Management | Digital Livestock Auction</title>
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
		.table td, .table th { vertical-align: middle; }
		@media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
		@media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } }
	</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
	<header class="topbar">
		<h1>User Management</h1>
		<p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Review registered accounts and access status.</p>
	</header>

	<?php if ($flashSuccess !== ''): ?><div class="alert alert-success" role="alert"><?= $escape($flashSuccess) ?></div><?php endif; ?>
	<?php if ($flashError !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($flashError) ?></div><?php endif; ?>

	<section class="row g-3 mb-4" aria-label="User summary">
		<?php foreach ([['All accounts', 'total_users'], ['Farmers', 'farmers'], ['Buyers', 'buyers'], ['Suspended', 'suspended']] as [$label, $key]): ?>
			<div class="col-sm-6 col-xl-3">
				<article class="stat-card h-100">
					<div class="stat-label"><?= $escape($label) ?></div>
					<div class="stat-number"><?= number_format((int) $stats[$key]) ?></div>
				</article>
			</div>
		<?php endforeach; ?>
	</section>

	<section class="content-card mb-4">
		<form method="GET" action="users.php" class="row g-3 align-items-end">
			<div class="col-lg-5">
				<label for="search" class="form-label">Search users</label>
				<input id="search" name="search" type="search" class="form-control" maxlength="100" value="<?= $escape($search) ?>" placeholder="Name, email, phone, or ID">
			</div>
			<div class="col-sm-6 col-lg-2">
				<label for="role" class="form-label">Role</label>
				<select id="role" name="role" class="form-select">
					<option value="">All roles</option>
					<?php foreach ($validRoles as $userRole): ?><option value="<?= $escape($userRole) ?>" <?= $role === $userRole ? 'selected' : '' ?>><?= $escape(ucfirst($userRole)) ?></option><?php endforeach; ?>
				</select>
			</div>
			<div class="col-sm-6 col-lg-2">
				<label for="status" class="form-label">Status</label>
				<select id="status" name="status" class="form-select">
					<option value="">All statuses</option>
					<?php foreach ($validStatuses as $userStatus): ?><option value="<?= $escape($userStatus) ?>" <?= $status === $userStatus ? 'selected' : '' ?>><?= $escape(ucfirst($userStatus)) ?></option><?php endforeach; ?>
				</select>
			</div>
			<div class="col-lg-3 d-flex gap-2">
				<button class="btn btn-success flex-grow-1" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
				<a href="users.php" class="btn btn-outline-secondary">Reset</a>
			</div>
		</form>
	</section>

	<section class="content-card">
		<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
			<h2 class="h5 fw-bold mb-0">Accounts</h2>
			<span class="text-muted small">Showing <?= $filteredCount === 0 ? 0 : $offset + 1 ?>–<?= min($offset + count($users), $filteredCount) ?> of <?= number_format($filteredCount) ?></span>
		</div>

		<?php if ($users === []): ?>
			<p class="text-muted mb-0">No accounts match these filters.</p>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-hover align-middle">
					<thead><tr><th>User</th><th>Role</th><th>Activity</th><th>Joined</th><th>Status</th><th>Account access</th></tr></thead>
					<tbody>
					<?php foreach ($users as $user): ?>
						<tr>
							<td><div class="fw-semibold"><?= $escape($user['name']) ?></div><small class="text-muted"><?= $escape($user['email']) ?> · <?= $escape($user['phone']) ?></small></td>
							<td><span class="badge text-bg-<?= $user['role'] === 'admin' ? 'dark' : 'info' ?>"><?= $escape(ucfirst((string) $user['role'])) ?></span></td>
							<td class="small">
								<?php if ($user['role'] === 'farmer'): ?>
									<?= number_format((int) $user['livestock_count']) ?> listings<small class="d-block text-muted"><?= number_format((int) $user['auction_count']) ?> auctions</small>
								<?php elseif ($user['role'] === 'buyer'): ?>
									<?= number_format((int) $user['bid_count']) ?> bids
								<?php else: ?>
									<span class="text-muted">—</span>
								<?php endif; ?>
							</td>
							<td class="text-nowrap"><?= $escape(date('d M Y', strtotime((string) $user['created_at']))) ?></td>
							<td><span class="badge text-bg-<?= $escape($statusClasses[$user['status']] ?? 'secondary') ?>"><?= $escape(ucfirst((string) $user['status'])) ?></span></td>
							<td>
								<?php if ($user['role'] === 'admin'): ?>
									<span class="text-muted small">Administrator account</span>
								<?php elseif ((int) $user['id'] === $adminId): ?>
									<span class="text-muted small">Your account</span>
								<?php else: ?>
									<form method="POST" action="users.php" class="d-flex gap-2">
										<input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
										<input type="hidden" name="action" value="change_status">
										<input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
										<input type="hidden" name="search" value="<?= $escape($search) ?>">
										<input type="hidden" name="role" value="<?= $escape($role) ?>">
										<input type="hidden" name="status_filter" value="<?= $escape($status) ?>">
										<input type="hidden" name="page" value="<?= $page ?>">
										<select name="status" class="form-select form-select-sm" aria-label="Account status for <?= $escape($user['name']) ?>">
											<?php foreach ($validStatuses as $userStatus): ?><option value="<?= $escape($userStatus) ?>" <?= $user['status'] === $userStatus ? 'selected' : '' ?>><?= $escape(ucfirst($userStatus)) ?></option><?php endforeach; ?>
										</select>
										<button class="btn btn-sm btn-outline-success text-nowrap" type="submit">Save</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ($totalPages > 1): ?>
				<nav aria-label="User pages" class="d-flex justify-content-between align-items-center">
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
