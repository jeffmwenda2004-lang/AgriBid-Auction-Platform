<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('buyer');

$buyerId = getUserId();
$error = '';
$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!isValidCsrfToken($postedToken)) {
        $error = 'Your form session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($name === '' || $email === '' || $phone === '') {
                $error = 'Name, email, and phone number are required.';
            } elseif (strlen($name) > 100) {
                $error = 'Name cannot be longer than 100 characters.';
            } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address of 150 characters or fewer.';
            } elseif (strlen($phone) > 20) {
                $error = 'Phone number cannot be longer than 20 characters.';
            } else {
                $emailCheck = $pdo->prepare(
                    'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
                );
                $emailCheck->execute([$email, $buyerId]);

                if ($emailCheck->fetch()) {
                    $error = 'That email address is already associated with another account.';
                } else {
                    try {
                        $update = $pdo->prepare(
                            'UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ? AND role = ?'
                        );
                        $update->execute([$name, $phone, $email, $buyerId, 'buyer']);

                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['profile_success'] = 'Your account information has been updated.';

                        header('Location: profile.php');
                        exit;
                    } catch (PDOException $exception) {
                        $error = 'Unable to update your account information. Please try again.';
                    }
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = 'Complete all password fields to change your password.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Your new password must contain at least 6 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation do not match.';
            } else {
                $passwordQuery = $pdo->prepare(
                    'SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1'
                );
                $passwordQuery->execute([$buyerId, 'buyer']);
                $account = $passwordQuery->fetch();

                if (!$account || !password_verify($currentPassword, $account['password'])) {
                    $error = 'The current password is incorrect.';
                } else {
                    try {
                        $update = $pdo->prepare(
                            'UPDATE users SET password = ? WHERE id = ? AND role = ?'
                        );
                        $update->execute([
                            password_hash($newPassword, PASSWORD_DEFAULT),
                            $buyerId,
                            'buyer'
                        ]);

                        $_SESSION['profile_success'] = 'Your password has been changed.';
                        header('Location: profile.php');
                        exit;
                    } catch (PDOException $exception) {
                        $error = 'Unable to change your password. Please try again.';
                    }
                }
            }
        } else {
            $error = 'Invalid profile action.';
        }
    }
}

$accountQuery = $pdo->prepare(
    'SELECT name, email, phone, role, status, created_at FROM users WHERE id = ? AND role = ? LIMIT 1'
);
$accountQuery->execute([$buyerId, 'buyer']);
$account = $accountQuery->fetch();

if (!$account) {
    http_response_code(404);
    exit('Buyer account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $account['name'] = trim($_POST['name'] ?? $account['name']);
    $account['email'] = trim($_POST['email'] ?? $account['email']);
    $account['phone'] = trim($_POST['phone'] ?? $account['phone']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Digital Livestock Auction</title>
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
        .profile-card,
        .info-card {
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

        .profile-card,
        .info-card {
            padding: 24px;
            height: 100%;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .form-control {
            min-height: 46px;
            border-radius: 9px;
        }

        .info-row {
            padding: 13px 0;
            border-bottom: 1px solid #edf0f2;
        }

        .info-row:last-child {
            border-bottom: 0;
        }

        .info-label {
            display: block;
            color: #718096;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .info-value {
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
    <link href="../assets/css/buyer.css" rel="stylesheet">
</head>
<body>

<?php require_once __DIR__ . '/../includes/buyer-nav.php'; ?>

<main class="main">
    <div class="topbar">
        <h3>My Profile</h3>
        <p>Manage your buyer account information and password.</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="profile-card mb-4">
                <h4 class="section-title">Account Information</h4>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Full Name</label>
                        <input
                            type="text"
                            class="form-control"
                            id="name"
                            name="name"
                            maxlength="100"
                            value="<?= htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label fw-semibold">Phone Number</label>
                        <input
                            type="tel"
                            class="form-control"
                            id="phone"
                            name="phone"
                            maxlength="20"
                            value="<?= htmlspecialchars($account['phone'], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label fw-semibold">Email Address</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            maxlength="150"
                            value="<?= htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save me-1"></i> Save Changes
                    </button>
                </form>
            </section>

            <section class="profile-card">
                <h4 class="section-title">Change Password</h4>
                <form method="POST" action="profile.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label fw-semibold">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" autocomplete="new-password" required>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" data-match="new_password" minlength="6" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-shield-lock me-1"></i> Change Password
                    </button>
                </form>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="info-card">
                <h4 class="section-title">Account Details</h4>

                <div class="info-row">
                    <span class="info-label">Account Role</span>
                    <span class="info-value"><?= htmlspecialchars(ucfirst($account['role']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">Account Status</span>
                    <span class="badge <?= $account['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= htmlspecialchars(ucfirst($account['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Member Since</span>
                    <span class="info-value"><?= date('d M Y', strtotime($account['created_at'])) ?></span>
                </div>

                <div class="info-row">
                    <span class="info-label">Account ID</span>
                    <span class="info-value">#<?= (int) $buyerId ?></span>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>