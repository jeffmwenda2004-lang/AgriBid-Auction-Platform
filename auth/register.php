<?php

session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate required fields
    if (
        empty($name) ||
        empty($email) ||
        empty($phone) ||
        empty($role) ||
        empty($password) ||
        empty($confirm_password)
    ) {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (!in_array($role, ['farmer', 'buyer'], true)) {

        $error = "Invalid account type.";

    } elseif (strlen($password) < 6) {

        $error = "Password must contain at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } elseif (!isset($_POST['terms'])) {

        $error = "Please accept the terms and conditions.";

    } else {

        try {

            // Check whether email already exists
            $checkEmail = $pdo->prepare(
                "SELECT id FROM users WHERE email = ? LIMIT 1"
            );

            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {

                $error = "An account with this email already exists.";

            } else {

                // Hash password
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Insert user
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (
                        name,
                        email,
                        phone,
                        password,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'active'
                    )
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $hashedPassword,
                    $role
                ]);

                $success = "Account created successfully. You can now login.";

            }

        } catch (PDOException $e) {

            $error = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Create Account | Digital Livestock Auction</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;

            background:
                linear-gradient(
                    rgba(0,0,0,.65),
                    rgba(0,0,0,.65)
                ),
                url('../assets/images/nbn.png') center/cover no-repeat;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 30px 15px;
        }

        .register-card {
            width: 100%;
            max-width: 650px;

            background: #fff;

            border-radius: 18px;

            box-shadow:
                0 15px 40px rgba(0,0,0,.25);

            overflow: hidden;
        }

        .register-header {
            background: #198754;
            color: white;

            padding: 30px;

            text-align: center;
        }

        .register-header i {
            font-size: 45px;
        }

        .register-header h2 {
            margin-top: 10px;
            font-weight: 700;
        }

        .register-body {
            padding: 35px;
        }

        .form-control,
        .form-select {
            min-height: 48px;
            border-radius: 10px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #198754;

            box-shadow:
                0 0 0 .2rem rgba(25,135,84,.15);
        }

        .btn-register {
            min-height: 50px;
            border-radius: 10px;
            font-weight: 600;
        }

        .login-link {
            text-decoration: none;
            font-weight: 600;
        }


    </style>

</head>

<body>

<div class="register-card">

    <div class="register-header">

        <i class="bi bi-person-plus-fill"></i>

        <h2>Create Your Account</h2>

        <p class="mb-0">
            Join the Digital Livestock Auction platform
        </p>

    </div>

    <div class="register-body">

        <?php if ($error): ?>

            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <?php if ($success): ?>

            <div class="alert alert-success">

                <i class="bi bi-check-circle me-2"></i>

                <?= htmlspecialchars($success) ?>

                <div class="mt-2">

                    <a
                        href="login.php"
                        class="btn btn-success btn-sm"
                    >
                        Login Now
                    </a>

                </div>

            </div>

        <?php endif; ?>


        <form method="POST">

            <div class="row g-3">

                <!-- Name -->

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Full Name
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            placeholder="Enter your full name"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            required
                        >

                    </div>

                </div>


                <!-- Phone -->

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Phone Number
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-telephone"></i>
                        </span>

                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            placeholder="07XXXXXXXX"
                            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                            required
                        >

                    </div>

                </div>


                <!-- Email -->

                <div class="col-12">

                    <label class="form-label fw-semibold">
                        Email Address
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="example@email.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            required
                        >

                    </div>

                </div>


                <!-- Role -->

                <div class="col-12">

                    <label class="form-label fw-semibold">
                        Account Type
                    </label>

                    <select
                        name="role"
                        class="form-select"
                        required
                    >

                        <option value="">
                            Select account type
                        </option>

                        <option
                            value="farmer"
                            <?= (($_POST['role'] ?? '') === 'farmer') ? 'selected' : '' ?>
                        >
                            Farmer
                        </option>

                        <option
                            value="buyer"
                            <?= (($_POST['role'] ?? '') === 'buyer') ? 'selected' : '' ?>
                        >
                            Buyer
                        </option>

                    </select>

                </div>


                <!-- Password -->

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            placeholder="Create password"
                            minlength="6"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="togglePassword('password', this)"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>


                <!-- Confirm Password -->

                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Confirm Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-shield-lock"></i>
                        </span>

                        <input
                            type="password"
                            name="confirm_password"
                            id="confirm_password"
                            data-match="password"
                            class="form-control"
                            placeholder="Confirm password"
                            minlength="6"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="togglePassword('confirm_password', this)"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>


                <!-- Terms -->

                <div class="col-12">

                    <div class="form-check">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="terms"
                            id="terms"
                            required
                        >

                        <label
                            class="form-check-label"
                            for="terms"
                        >
                            I agree to the platform terms and conditions.
                        </label>

                    </div>

                </div>


                <!-- Submit -->

                <div class="col-12">

                    <button
                        type="submit"
                        class="btn btn-success btn-register w-100"
                    >

                        <i class="bi bi-person-plus me-2"></i>

                        Create Account

                    </button>

                </div>

            </div>

        </form>


        <div class="text-center mt-4">

            Already have an account?

            <a
                href="login.php"
                class="login-link text-success"
            >
                Login here
            </a>

        </div>


        <div class="text-center mt-3">

            <a
                href="../index.php"
                class="text-muted text-decoration-none"
            >

                <i class="bi bi-arrow-left"></i>

                Back to Home

            </a>

        </div>

    </div>

</div>


<script>

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    const icon = button.querySelector("i");

    if (input.type === "password") {

        input.type = "text";

        icon.classList.remove("bi-eye");

        icon.classList.add("bi-eye-slash");

    } else {

        input.type = "password";

        icon.classList.remove("bi-eye-slash");

        icon.classList.add("bi-eye");
    }
}

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>

</html>