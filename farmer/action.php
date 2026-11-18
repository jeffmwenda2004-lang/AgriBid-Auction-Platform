<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('farmer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: livestock.php');
    exit;
}

$farmerId = getUserId();
$redirectWithMessage = static function (string $key, string $message): void {
    $_SESSION[$key] = $message;
    header('Location: livestock.php');
    exit;
};

$postedToken = $_POST['csrf_token'] ?? '';
if (!isValidCsrfToken($postedToken)) {
    $redirectWithMessage('farmer_livestock_error', 'Your form session expired. Please reload the page and try again.');
}

$readText = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid form data was submitted.');
    }

    return trim($value);
};

$action = $_POST['action'] ?? '';
if (!is_string($action)) {
    $redirectWithMessage('farmer_livestock_error', 'Invalid livestock action.');
}

$action = strtolower(trim($action));
$actionAliases = [
    'add_livestock' => 'add',
    'update_livestock' => 'update',
    'delete_livestock' => 'delete',
];
$action = $actionAliases[$action] ?? $action;

if (!in_array($action, ['add', 'update', 'delete'], true)) {
    $redirectWithMessage('farmer_livestock_error', 'Invalid livestock action.');
}

$uploadDirectory = __DIR__ . '/../uploads/livestock';
$maximumImageSize = 5 * 1024 * 1024;
$newImagePath = null;
$oldImageName = null;
$livestockId = 0;

try {
    if ($action === 'delete') {
        $rawId = $_POST['livestock_id'] ?? $_POST['id'] ?? '';
        $livestockId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($livestockId === 0) {
            throw new InvalidArgumentException('Choose a valid livestock record to delete.');
        }

        $pdo->beginTransaction();

        $recordStmt = $pdo->prepare(
            'SELECT id, image, status FROM livestock WHERE id = ? AND farmer_id = ? FOR UPDATE'
        );
        $recordStmt->execute([$livestockId, $farmerId]);
        $record = $recordStmt->fetch();

        if (!$record) {
            throw new InvalidArgumentException('Livestock record not found or you do not have permission to delete it.');
        }

        if (!in_array($record['status'], ['available', 'inactive'], true)) {
            throw new InvalidArgumentException('Only available or inactive livestock without auction activity can be deleted.');
        }

        $auctionStmt = $pdo->prepare('SELECT COUNT(*) FROM auctions WHERE livestock_id = ?');
        $auctionStmt->execute([$livestockId]);
        if ((int) $auctionStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('This livestock has auction history and cannot be deleted.');
        }

        $deleteStmt = $pdo->prepare(
            'DELETE FROM livestock WHERE id = ? AND farmer_id = ?'
        );
        $deleteStmt->execute([$livestockId, $farmerId]);

        $pdo->commit();

        $oldImageName = $record['image'];
        if (is_string($oldImageName) && $oldImageName !== '' && basename($oldImageName) === $oldImageName) {
            $oldImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $oldImageName;
            if (is_file($oldImagePath) && !unlink($oldImagePath)) {
                error_log('Unable to remove livestock image after deleting livestock record ' . $livestockId);
            }
        }

        $redirectWithMessage('farmer_livestock_success', 'Livestock record deleted successfully.');
    }

    $animalType = $readText('animal_type');
    $breed = $readText('breed');
    $gender = strtolower($readText('gender'));
    $ageInput = $readText('age');
    $weightInput = $readText('weight');
    $healthStatus = $readText('health_status');
    $description = $readText('description');
    $location = $readText('location');

    if ($animalType === '' || strlen($animalType) > 50) {
        throw new InvalidArgumentException('Animal type is required and must be 50 characters or fewer.');
    }
    if (strlen($breed) > 100) {
        throw new InvalidArgumentException('Breed must be 100 characters or fewer.');
    }
    if (!in_array($gender, ['male', 'female'], true)) {
        throw new InvalidArgumentException('Select a valid gender.');
    }
    if ($ageInput !== '' && (!ctype_digit($ageInput) || (int) $ageInput > 2000)) {
        throw new InvalidArgumentException('Age must be a whole number between 0 and 2000 months.');
    }
    if (
        $weightInput !== ''
        && (!is_numeric($weightInput) || (float) $weightInput <= 0 || (float) $weightInput > 99999999.99)
    ) {
        throw new InvalidArgumentException('Weight must be a number greater than zero.');
    }
    if (strlen($healthStatus) > 100) {
        throw new InvalidArgumentException('Health status must be 100 characters or fewer.');
    }
    if (strlen($location) > 150) {
        throw new InvalidArgumentException('Location must be 150 characters or fewer.');
    }

    $age = $ageInput === '' ? null : (int) $ageInput;
    $weight = $weightInput === '' ? null : (float) $weightInput;
    $breed = $breed === '' ? null : $breed;
    $healthStatus = $healthStatus === '' ? null : $healthStatus;
    $description = $description === '' ? null : $description;
    $location = $location === '' ? null : $location;

    if ($action === 'update') {
        $rawId = $_POST['livestock_id'] ?? $_POST['id'] ?? '';
        $livestockId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($livestockId === 0) {
            throw new InvalidArgumentException('Choose a valid livestock record to update.');
        }

        $pdo->beginTransaction();

        $recordStmt = $pdo->prepare(
            'SELECT id, image, status FROM livestock WHERE id = ? AND farmer_id = ? FOR UPDATE'
        );
        $recordStmt->execute([$livestockId, $farmerId]);
        $record = $recordStmt->fetch();

        if (!$record) {
            throw new InvalidArgumentException('Livestock record not found or you do not have permission to update it.');
        }
        if (!in_array($record['status'], ['available', 'inactive'], true)) {
            throw new InvalidArgumentException('Livestock with an active auction or completed sale cannot be edited.');
        }

        $liveAuctionStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM auctions
            WHERE livestock_id = ?
              AND farmer_id = ?
              AND status IN ('pending', 'active')
              AND end_time > NOW()
        ");
        $liveAuctionStmt->execute([$livestockId, $farmerId]);
        if ((int) $liveAuctionStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Livestock cannot be edited while it has a pending or active auction.');
        }

        $oldImageName = $record['image'];
    }

    $imageName = $oldImageName;
    if (isset($_FILES['image']) && is_array($_FILES['image'])) {
        $upload = $_FILES['image'];
        $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('The image upload failed. Please choose the image again.');
            }
            if (
                !isset($upload['tmp_name'], $upload['size'])
                || !is_string($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])
            ) {
                throw new InvalidArgumentException('The uploaded image could not be verified.');
            }
            if ((int) $upload['size'] <= 0 || (int) $upload['size'] > $maximumImageSize) {
                throw new InvalidArgumentException('Livestock photos must be no larger than 5 MB.');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($upload['tmp_name']);
            $allowedImageTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!is_string($mimeType) || !isset($allowedImageTypes[$mimeType])) {
                throw new InvalidArgumentException('Upload a valid JPEG, PNG, or WebP image.');
            }

            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException('The livestock image directory could not be created.');
            }
            if (!is_writable($uploadDirectory)) {
                throw new RuntimeException('The livestock image directory is not writable.');
            }

            $imageName = bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mimeType];
            $newImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $imageName;
            if (!move_uploaded_file($upload['tmp_name'], $newImagePath)) {
                $newImagePath = null;
                throw new RuntimeException('The livestock image could not be saved.');
            }
        }
    }

    if ($action === 'add') {
        $insertStmt = $pdo->prepare("
            INSERT INTO livestock
                (farmer_id, animal_type, breed, gender, age, weight, health_status, description, location, image, status, moderation_status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', 'pending')
        ");
        $insertStmt->execute([
            $farmerId,
            $animalType,
            $breed,
            $gender,
            $age,
            $weight,
            $healthStatus,
            $description,
            $location,
            $imageName,
        ]);

        $redirectWithMessage('farmer_livestock_success', 'Livestock submitted for administrator review.');
    }

    $updateStmt = $pdo->prepare("
        UPDATE livestock
        SET animal_type = ?, breed = ?, gender = ?, age = ?, weight = ?,
            health_status = ?, description = ?, location = ?, image = ?,
            moderation_status = 'pending', moderation_reason = NULL
        WHERE id = ? AND farmer_id = ?
    ");
    $updateStmt->execute([
        $animalType,
        $breed,
        $gender,
        $age,
        $weight,
        $healthStatus,
        $description,
        $location,
        $imageName,
        $livestockId,
        $farmerId,
    ]);

    $pdo->commit();

    if (
        $newImagePath !== null
        && is_string($oldImageName)
        && $oldImageName !== ''
        && basename($oldImageName) === $oldImageName
    ) {
        $oldImagePath = $uploadDirectory . DIRECTORY_SEPARATOR . $oldImageName;
        if (is_file($oldImagePath) && !unlink($oldImagePath)) {
            error_log('Unable to remove replaced livestock image for record ' . $livestockId);
        }
    }

    $redirectWithMessage('farmer_livestock_success', 'Livestock details updated and submitted for administrator review.');
} catch (InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newImagePath !== null && is_file($newImagePath)) {
        unlink($newImagePath);
    }
    $redirectWithMessage('farmer_livestock_error', $exception->getMessage());
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newImagePath !== null && is_file($newImagePath)) {
        unlink($newImagePath);
    }
    error_log('Farmer livestock action upload failure: ' . $exception->getMessage());
    $redirectWithMessage('farmer_livestock_error', 'The livestock photo could not be saved. Please try again.');
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newImagePath !== null && is_file($newImagePath)) {
        unlink($newImagePath);
    }
    error_log('Farmer livestock database action failed: ' . $exception->getMessage());
    $redirectWithMessage('farmer_livestock_error', 'The livestock change could not be saved. Please try again.');
}
