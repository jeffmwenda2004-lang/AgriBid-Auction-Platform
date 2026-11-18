<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-check.php';

$adminId = getUserId();
$adminName = getUserName();
$escape = 'escapeHtml';

$csrfToken = getCsrfToken();

$flashSuccess = $_SESSION['admin_settings_success'] ?? '';
$flashError = $_SESSION['admin_settings_error'] ?? '';
unset($_SESSION['admin_settings_success'], $_SESSION['admin_settings_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedToken = $_POST['csrf_token'] ?? '';
	$action = $_POST['action'] ?? '';
	$error = '';
	$success = '';

	if (!isValidCsrfToken($postedToken)) {
		$error = 'Your form session expired. Please reload and try again.';
	} elseif (!is_string($action)) {
		$error = 'Choose a valid settings action.';
	} elseif ($action === 'profile') {
		$nameInput = $_POST['name'] ?? '';
		$emailInput = $_POST['email'] ?? '';
		$phoneInput = $_POST['phone'] ?? '';

		if (!is_string($nameInput) || !is_string($emailInput) || !is_string($phoneInput)) {
			$error = 'Enter valid profile information.';
		} else {
			$name = trim($nameInput);
			$email = trim($emailInput);
			$phone = trim($phoneInput);

			if ($name === '' || strlen($name) > 100) {
				$error = 'Name is required and must be 100 characters or fewer.';
			} elseif ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$error = 'Enter a valid email address of 150 characters or fewer.';
			} elseif ($phone === '' || strlen($phone) > 20) {
				$error = 'Phone number is required and must be 20 characters or fewer.';
			} else {
				try {
					$emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
					$emailStmt->execute([$email, $adminId]);

					if ($emailStmt->fetch()) {
						$error = 'That email address is already in use.';
					} else {
						$updateStmt = $pdo->prepare(
							'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = \'admin\''
						);
						$updateStmt->execute([$name, $email, $phone, $adminId]);
						$_SESSION['user_name'] = $name;
						$_SESSION['user_email'] = $email;
						$success = 'Profile details updated.';
					}
				} catch (PDOException $exception) {
					error_log('Admin profile update failed: ' . $exception->getMessage());
					$error = 'Unable to update your profile right now. Please try again.';
				}
			}
		}
	} elseif ($action === 'password') {
		$currentPassword = $_POST['current_password'] ?? '';
		$newPassword = $_POST['new_password'] ?? '';
		$confirmPassword = $_POST['confirm_password'] ?? '';

		if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)) {
			$error = 'Enter valid password information.';
		} elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
			$error = 'Complete all password fields.';
		} elseif (strlen($newPassword) < 6) {
			$error = 'New password must contain at least 6 characters.';
		} elseif ($newPassword !== $confirmPassword) {
			$error = 'New password and confirmation do not match.';
		} elseif ($currentPassword === $newPassword) {
			$error = 'Choose a new password different from your current password.';
		} else {
			try {
				$passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND role = \'admin\' LIMIT 1');
				$passwordStmt->execute([$adminId]);
				$storedPassword = $passwordStmt->fetchColumn();

				if (!is_string($storedPassword) || !password_verify($currentPassword, $storedPassword)) {
					$error = 'Current password is incorrect.';
				} else {
					$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
					$updateStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND role = \'admin\'');
					$updateStmt->execute([$newHash, $adminId]);
					$success = 'Password changed successfully.';
				}
			} catch (PDOException $exception) {
				error_log('Admin password update failed: ' . $exception->getMessage());
				$error = 'Unable to change your password right now. Please try again.';
			}
		}
	} else {
		$error = 'Choose a valid settings action.';
	}

	$_SESSION[$error === '' ? 'admin_settings_success' : 'admin_settings_error'] = $error === '' ? $success : $error;
	header('Location: settings.php');
	exit;
}

$profileStmt = $pdo->prepare('SELECT name, email, phone, created_at FROM users WHERE id = ? AND role = \'admin\' LIMIT 1');
$profileStmt->execute([$adminId]);
$profile = $profileStmt->fetch();

if (!$profile) {
	http_response_code(403);
	exit('Administrator account not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Account Settings | Digital Livestock Auction</title>
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
		.topbar, .settings-section { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
		.topbar { padding: 20px 25px; margin-bottom: 25px; }
		.topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
		.topbar p { margin: 5px 0 0; color: #718096; }
		.settings-section { padding: 24px; }
		.section-icon { color: #12372A; font-size: 21px; }
		@media (max-width: 991px) { .sidebar { width: 220px; } .main { margin-left: 220px; } }
		@media (max-width: 767px) { .sidebar { position: relative; width: 100%; min-height: auto; } .main { margin-left: 0; padding: 20px; } }
	</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main">
	<header class="topbar">
		<h1>Account Settings</h1>
		<p>Welcome, <?= $escape($adminName ?? 'Administrator') ?>. Manage your profile and account security.</p>
	</header>

	<?php if ($flashSuccess !== ''): ?><div class="alert alert-success" role="alert"><?= $escape($flashSuccess) ?></div><?php endif; ?>
	<?php if ($flashError !== ''): ?><div class="alert alert-danger" role="alert"><?= $escape($flashError) ?></div><?php endif; ?>

	<div class="row g-4">
		<div class="col-xl-7">
			<section class="settings-section h-100" aria-labelledby="profile-heading">
				<div class="d-flex align-items-center gap-2 mb-3">
					<i class="bi bi-person-circle section-icon"></i>
					<h2 id="profile-heading" class="h5 fw-bold mb-0">Profile details</h2>
				</div>
				<form method="POST" action="settings.php">
					<input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
					<input type="hidden" name="action" value="profile">
					<div class="mb-3">
						<label for="name" class="form-label">Name</label>
						<input id="name" name="name" type="text" class="form-control" maxlength="100" required value="<?= $escape($profile['name']) ?>" autocomplete="name">
					</div>
					<div class="mb-3">
						<label for="email" class="form-label">Email address</label>
						<input id="email" name="email" type="email" class="form-control" maxlength="150" required value="<?= $escape($profile['email']) ?>" autocomplete="email">
					</div>
					<div class="mb-4">
						<label for="phone" class="form-label">Phone number</label>
						<input id="phone" name="phone" type="tel" class="form-control" maxlength="20" required value="<?= $escape($profile['phone']) ?>" autocomplete="tel">
					</div>
					<button class="btn btn-success" type="submit"><i class="bi bi-check2 me-1"></i>Save profile</button>
				</form>
			</section>
		</div>

		<div class="col-xl-5">
			<section class="settings-section h-100" aria-labelledby="password-heading">
				<div class="d-flex align-items-center gap-2 mb-3">
					<i class="bi bi-lock section-icon"></i>
					<h2 id="password-heading" class="h5 fw-bold mb-0">Change password</h2>
				</div>
				<form method="POST" action="settings.php">
					<input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
					<input type="hidden" name="action" value="password">
					<div class="mb-3">
						<label for="current_password" class="form-label">Current password</label>
						<input id="current_password" name="current_password" type="password" class="form-control" required autocomplete="current-password">
					</div>
					<div class="mb-3">
						<label for="new_password" class="form-label">New password</label>
						<input id="new_password" name="new_password" type="password" class="form-control" minlength="6" required autocomplete="new-password">
						<div class="form-text">Use at least 6 characters.</div>
					</div>
					<div class="mb-4">
						<label for="confirm_password" class="form-label">Confirm new password</label>
						<input id="confirm_password" name="confirm_password" type="password" class="form-control" data-match="new_password" minlength="6" required autocomplete="new-password">
					</div>
					<button class="btn btn-success" type="submit"><i class="bi bi-key me-1"></i>Update password</button>
				</form>
			</section>
		</div>
	</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
