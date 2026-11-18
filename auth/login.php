<?php

session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {

        $error = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {

            // Find user
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    name,
                    email,
                    phone,
                    password,
                    role,
                    status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();

            if (!$user) {

                $error = "Invalid email or password.";

            } elseif (!password_verify($password, $user['password'])) {

                $error = "Invalid email or password.";

            } elseif ($user['status'] !== 'active') {

                $error = "Your account is currently " . $user['status'] . ".";

            } else {

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Store user information
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_status'] = $user['status'];

                // Remember me
                if (isset($_POST['remember'])) {
                    $_SESSION['remember'] = true;
                }

                // Redirect according to role
                switch ($user['role']) {

                    case 'admin':
                        header("Location: ../admin/dashboard.php");
                        exit;

                    case 'farmer':
                        header("Location: ../farmer/dashboard.php");
                        exit;

                    case 'buyer':
                        header("Location: ../buyer/dashboard.php");
                        exit;

                    default:
                        $error = "Invalid account role.";
                        break;
                }
            }

        } catch (PDOException $e) {

            $error = "Login failed. Please try again.";
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

    <title>Login | Digital Livestock Auction</title>

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
                url('../assets/images/download (1).png')
                center/cover no-repeat;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

        }

        .login-card {

            width: 100%;

            max-width: 450px;

            background: white;

            border-radius: 18px;

            overflow: hidden;

            box-shadow:
                0 15px 40px rgba(0,0,0,.25);

        }

        .login-header {

            background: #198754;

            color: white;

            text-align: center;

            padding: 35px 25px;

        }

        .login-header i {

            font-size: 48px;

        }

        .login-header h2 {

            margin-top: 12px;

            font-weight: 700;

        }

        .login-body {

            padding: 35px;

        }

        .form-control {

            min-height: 50px;

            border-radius: 10px;

        }

        .input-group-text {

            border-radius: 10px 0 0 10px;

        }

        .form-control:focus {

            border-color: #198754;

            box-shadow:
                0 0 0 .2rem rgba(25,135,84,.15);

        }

        .btn-login {

            min-height: 50px;

            border-radius: 10px;

            font-weight: 600;

        }

        .register-link {

            text-decoration: none;

            font-weight: 600;

        }

    </style>

</head>

<body>

<div class="login-card">

    <!-- HEADER -->

    <div class="login-header">

        <i class="bi bi-person-circle"></i>

        <h2>Welcome Back</h2>

        <p class="mb-0">
            Login to your auction account
        </p>

    </div>


    <!-- BODY -->

    <div class="login-body">

        <?php if ($error): ?>

            <div class="alert alert-danger">

                <i class="bi bi-exclamation-circle me-2"></i>

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <form method="POST">

            <!-- EMAIL -->

            <div class="mb-3">

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
                        placeholder="Enter your email"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                    >

                </div>

            </div>


            <!-- PASSWORD -->

            <div class="mb-3">

                <div class="d-flex justify-content-between">

                    <label class="form-label fw-semibold">
                        Password
                    </label>

                    <a
                        href="forgot-password.php"
                        class="text-success text-decoration-none small"
                    >
                        Forgot password?
                    </a>

                </div>

                <div class="input-group">

                    <span class="input-group-text">

                        <i class="bi bi-lock"></i>

                    </span>

                    <input
                        type="password"
                        name="password"
                        id="loginPassword"
                        class="form-control"
                        placeholder="Enter your password"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        onclick="togglePassword()"
                    >

                        <i
                            class="bi bi-eye"
                            id="passwordIcon"
                        ></i>

                    </button>

                </div>

            </div>


            <!-- REMEMBER -->

            <div class="form-check mb-4">

                <input
                    class="form-check-input"
                    type="checkbox"
                    name="remember"
                    id="remember"
                >

                <label
                    class="form-check-label"
                    for="remember"
                >
                    Remember me
                </label>

            </div>


            <!-- LOGIN -->

            <button
                type="submit"
                class="btn btn-success btn-login w-100"
            >

                <i class="bi bi-box-arrow-in-right me-2"></i>

                Login

            </button>

        </form>


        <!-- REGISTER -->

        <div class="text-center mt-4">

            Don't have an account?

            <a
                href="register.php"
                class="register-link text-success"
            >
                Create Account
            </a>

        </div>


        <!-- HOME -->

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

function togglePassword() {

    const password =
        document.getElementById("loginPassword");

    const icon =
        document.getElementById("passwordIcon");

    if (password.type === "password") {

        password.type = "text";

        icon.classList.remove("bi-eye");

        icon.classList.add("bi-eye-slash");

    } else {

        password.type = "password";

        icon.classList.remove("bi-eye-slash");

        icon.classList.add("bi-eye");

    }

}

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>

</html>