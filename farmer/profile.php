<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

$farmerId = getUserId();
$success = $_SESSION['farmer_profile_success'] ?? '';
$error = '';
unset($_SESSION['farmer_profile_success']);

$csrfToken = getCsrfToken();

$profileQuery = $pdo->prepare(
    'SELECT name, email, phone, role, status, created_at FROM users WHERE id = ? AND role = ? LIMIT 1'
);
$profileQuery->execute([$farmerId, 'farmer']);
$farmer = $profileQuery->fetch();

if (!$farmer) {
    http_response_code(404);
    exit('Farmer account not found.');
}

$formName = $farmer['name'];
$formEmail = $farmer['email'];
$formPhone = $farmer['phone'];
$profilePhotoDirectory = __DIR__ . '/../uploads/profiles';
$profilePhotoUrl = null;
$photoExtensions = ['jpg', 'png', 'webp'];
foreach ($photoExtensions as $extension) {
    $candidate = $profilePhotoDirectory . DIRECTORY_SEPARATOR . 'farmer_' . $farmerId . '.' . $extension;
    if (is_file($candidate)) {
        $profilePhotoUrl = '../uploads/profiles/farmer_' . $farmerId . '.' . $extension;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!isValidCsrfToken($postedToken)) {
        $error = 'Your form session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $formName = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
            $formEmail = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
            $formPhone = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');

            if ($formName === '' || $formEmail === '' || $formPhone === '') {
                $error = 'Name, email address, and phone number are required.';
            } elseif (strlen($formName) > 100) {
                $error = 'Name cannot be longer than 100 characters.';
            } elseif (strlen($formEmail) > 150 || !filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address of 150 characters or fewer.';
            } elseif (strlen($formPhone) > 20) {
                $error = 'Phone number cannot be longer than 20 characters.';
            } else {
                $emailCheck = $pdo->prepare(
                    'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
                );
                $emailCheck->execute([$formEmail, $farmerId]);

                if ($emailCheck->fetch()) {
                    $error = 'That email address is already associated with another account.';
                } else {
                    try {
                        $updateProfile = $pdo->prepare(
                            'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ? AND role = ?'
                        );
                        $updateProfile->execute([
                            $formName,
                            $formEmail,
                            $formPhone,
                            $farmerId,
                            'farmer',
                        ]);

                        $_SESSION['user_name'] = $formName;
                        $_SESSION['user_email'] = $formEmail;
                        $_SESSION['farmer_profile_success'] = 'Your profile information has been updated.';
                        header('Location: profile.php');
                        exit;
                    } catch (PDOException $exception) {
                        error_log('Farmer profile update failed: ' . $exception->getMessage());
                        $error = 'Unable to update your profile right now. Please try again.';
                    }
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)
                || $currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = 'Complete all password fields to change your password.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'The new password must contain at least 6 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'The new password and confirmation do not match.';
            } else {
                $passwordQuery = $pdo->prepare(
                    'SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1'
                );
                $passwordQuery->execute([$farmerId, 'farmer']);
                $account = $passwordQuery->fetch();

                if (!$account || !password_verify($currentPassword, $account['password'])) {
                    $error = 'The current password is incorrect.';
                } else {
                    try {
                        $updatePassword = $pdo->prepare(
                            'UPDATE users SET password = ? WHERE id = ? AND role = ?'
                        );
                        $updatePassword->execute([
                            password_hash($newPassword, PASSWORD_DEFAULT),
                            $farmerId,
                            'farmer',
                        ]);

                        $_SESSION['farmer_profile_success'] = 'Your password has been changed.';
                        header('Location: profile.php');
                        exit;
                    } catch (PDOException $exception) {
                        error_log('Farmer password change failed: ' . $exception->getMessage());
                        $error = 'Unable to change your password right now. Please try again.';
                    }
                }
            }
        } elseif ($action === 'update_photo') {
            $upload = $_FILES['profile_photo'] ?? null;
            if (!is_array($upload)) {
                $error = 'Choose a profile photo to upload.';
            } elseif (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                    ? 'Choose a profile photo to upload.'
                    : 'The photo upload failed. Please try again.';
            } elseif (
                !isset($upload['tmp_name'], $upload['size'])
                || !is_string($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])
            ) {
                $error = 'The uploaded photo could not be verified.';
            } elseif ((int) $upload['size'] <= 0 || (int) $upload['size'] > 5 * 1024 * 1024) {
                $error = 'Profile photos must be no larger than 5 MB.';
            } else {
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($upload['tmp_name']);
                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
                    $error = 'Upload a valid JPEG, PNG, or WebP photo.';
                } elseif (
                    !is_dir($profilePhotoDirectory)
                    && !mkdir($profilePhotoDirectory, 0755, true)
                    && !is_dir($profilePhotoDirectory)
                ) {
                    error_log('Unable to create farmer profile photo directory.');
                    $error = 'The profile photo could not be saved. Please try again.';
                } elseif (!is_writable($profilePhotoDirectory)) {
                    error_log('Farmer profile photo directory is not writable.');
                    $error = 'The profile photo could not be saved. Please try again.';
                } else {
                    $extension = $allowedTypes[$mimeType];
                    $newPhotoPath = $profilePhotoDirectory . DIRECTORY_SEPARATOR . 'farmer_' . $farmerId . '.' . $extension;

                    if (!move_uploaded_file($upload['tmp_name'], $newPhotoPath)) {
                        $error = 'The profile photo could not be saved. Please try again.';
                    } else {
                        foreach ($photoExtensions as $oldExtension) {
                            if ($oldExtension !== $extension) {
                                $oldPhotoPath = $profilePhotoDirectory . DIRECTORY_SEPARATOR . 'farmer_' . $farmerId . '.' . $oldExtension;
                                if (is_file($oldPhotoPath) && !unlink($oldPhotoPath)) {
                                    error_log('Unable to remove replaced farmer profile photo for user ' . $farmerId);
                                }
                            }
                        }

                        $_SESSION['farmer_profile_success'] = 'Your profile photo has been updated.';
                        header('Location: profile.php');
                        exit;
                    }
                }
            }
        } else {
            $error = 'Invalid profile action.';
        }
    }
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
        .topbar, .profile-card, .account-card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .topbar { padding: 20px 25px; margin-bottom: 25px; }
        .topbar h1 { margin: 0; font-size: 25px; font-weight: 700; }
        .topbar p { margin: 5px 0 0; color: #718096; }
        .profile-card, .account-card { padding: 24px; height: 100%; }
        .section-title { margin-bottom: 20px; font-size: 18px; font-weight: 700; }
        .form-control { min-height: 46px; border-radius: 9px; }
        .account-row { padding: 13px 0; border-bottom: 1px solid #edf0f2; }
        .account-row:last-child { border-bottom: 0; }
        .account-label { display: block; color: #718096; font-size: 13px; margin-bottom: 4px; }
        .account-value { font-weight: 600; }
        .profile-photo {
            width: 130px; height: 130px; object-fit: cover; border-radius: 50%;
            border: 4px solid #e6f1ec; background: #eef1f0;
        }
        .photo-placeholder {
            display: flex; align-items: center; justify-content: center;
            width: 130px; height: 130px; border-radius: 50%;
            border: 4px solid #e6f1ec; background: #f7faf8; color: #718096; font-size: 54px;
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
    <header class="topbar">
        <h1>My Profile</h1>
        <p>Manage your farmer account details, profile photo, and password.</p>
    </header>

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
                <h2 class="section-title">Profile Information</h2>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" maxlength="100" required value="<?= htmlspecialchars($formName, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" maxlength="150" required value="<?= htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="mb-4">
                        <label for="phone" class="form-label fw-semibold">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" maxlength="20" required value="<?= htmlspecialchars($formPhone, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save me-1"></i> Save Profile
                    </button>
                </form>
            </section>

            <section class="profile-card">
                <h2 class="section-title">Change Password</h2>
                <form method="POST" action="profile.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label for="current_password" class="form-label fw-semibold">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label fw-semibold">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" minlength="6" autocomplete="new-password" required>
                    </div>
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" data-match="new_password" minlength="6" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-shield-lock me-1"></i> Change Password
                    </button>
                </form>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="profile-card mb-4">
                <h2 class="section-title">Profile Photo</h2>
                <div class="text-center mb-3">
                    <?php if ($profilePhotoUrl !== null): ?>
                        <img src="<?= htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Farmer profile photo" class="profile-photo">
                    <?php else: ?>
                        <div class="photo-placeholder mx-auto" aria-label="No profile photo"><i class="bi bi-person"></i></div>
                    <?php endif; ?>
                </div>
                <form method="POST" action="profile.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_photo">
                    <label for="profile_photo" class="form-label fw-semibold">Upload or replace photo</label>
                    <input type="file" id="profile_photo" name="profile_photo" class="form-control mb-2" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                    <div class="form-text mb-3">JPEG, PNG, or WebP; maximum file size 5 MB.</div>
                    <button type="submit" class="btn btn-outline-success">
                        <i class="bi bi-upload me-1"></i> Update Photo
                    </button>
                </form>
            </section>

            <section class="account-card">
                <h2 class="section-title">Account Information</h2>
                <div class="account-row">
                    <span class="account-label">Account Role</span>
                    <span class="account-value"><?= htmlspecialchars(ucfirst($farmer['role']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="account-row">
                    <span class="account-label">Account Status</span>
                    <span class="badge <?= $farmer['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= htmlspecialchars(ucfirst($farmer['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="account-row">
                    <span class="account-label">Registered</span>
                    <span class="account-value"><?= date('d M Y', strtotime($farmer['created_at'])) ?></span>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>