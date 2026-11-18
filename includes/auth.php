<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

/*
|--------------------------------------------------------------------------
| Check if user is logged in
|--------------------------------------------------------------------------
*/

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}


/*
|--------------------------------------------------------------------------
| Get logged-in user ID
|--------------------------------------------------------------------------
*/

function getUserId(): ?int
{
    return isset($_SESSION['user_id'])
        ? (int) $_SESSION['user_id']
        : null;
}


/*
|--------------------------------------------------------------------------
| Get user role
|--------------------------------------------------------------------------
*/

function getUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}


/*
|--------------------------------------------------------------------------
| Get user name
|--------------------------------------------------------------------------
*/

function getUserName(): ?string
{
    return $_SESSION['user_name'] ?? null;
}


/*
|--------------------------------------------------------------------------
| Get user email
|--------------------------------------------------------------------------
*/

function getUserEmail(): ?string
{
    return $_SESSION['user_email'] ?? null;
}


/*
|--------------------------------------------------------------------------
| Require Login
|--------------------------------------------------------------------------
*/

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: ../auth/login.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Require Specific Role
|--------------------------------------------------------------------------
*/

function requireRole(string $role): void
{
    requireLogin();

    if (getUserRole() !== $role) {
        header("Location: ../index.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Require Multiple Roles
|--------------------------------------------------------------------------
*/

function requireRoles(array $roles): void
{
    requireLogin();

    if (!in_array(getUserRole(), $roles, true)) {
        header("Location: ../index.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Login User
|--------------------------------------------------------------------------
*/

function loginUser(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_status'] = $user['status'];
}


/*
|--------------------------------------------------------------------------
| Logout User
|--------------------------------------------------------------------------
*/

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}