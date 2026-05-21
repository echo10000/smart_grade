<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'components.php';

if (isset($_SESSION['user'])) {
    $r = $_SESSION['user']['role'];
    if ($r === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($r === 'teacher') {
        header('Location: teacher_dashboard.php');
    } else {
        header('Location: student_dashboard.php');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);

    if (empty($error) && $email && $password) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status="active"');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['user'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ];

            if ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($user['role'] === 'teacher') {
                header('Location: teacher_dashboard.php');
            } else {
                header('Location: student_dashboard.php');
            }
            exit;
        }

        $error = 'Invalid email or password.';
    } elseif (empty($error)) {
        $error = 'Please enter a valid email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartGrade Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="smartgrade-styles.css" rel="stylesheet">
</head>
<body class="sg-login-page">
    <main class="login-shell">
        <section class="brand-panel">
            <div>
                <div class="brand-mark">SG</div>
                <h1>SmartGrade</h1>
                <p>Online grading workspace for class records, grade computation, reports, and student grade access.</p>
            </div>
        </section>

        <section class="login-card">
            <div class="mb-4">
                <h2>Sign in</h2>
                <p class="text-muted mb-0">Use your SmartGrade account to continue.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (($_GET['registered'] ?? '') === '1'): ?>
                <div class="alert alert-success">Registration successful. You can now sign in.</div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <div class="sg-input">
                        <span class="sg-input__icon"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" placeholder="name@school.edu" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="sg-input">
                        <span class="sg-input__icon"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
                <button type="button" class="forgot-link" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Forgot Password?</button>
                <p class="auth-switch mb-0">No student account yet? <a href="student_register.php">Register here</a></p>
            </form>
        </section>
    </main>

    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">Password assistance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Please contact your school administrator or registrar to reset your SmartGrade password.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
