<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($role === 'teacher') {
        header('Location: teacher_dashboard.php');
    } else {
        header('Location: student_dashboard.php');
    }
    exit;
}

$yearLevels = app_year_level_options();
$form = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'year_level' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $form['first_name'] = trim((string) filter_input(INPUT_POST, 'first_name', FILTER_UNSAFE_RAW));
    $form['last_name'] = trim((string) filter_input(INPUT_POST, 'last_name', FILTER_UNSAFE_RAW));
    $form['email'] = trim((string) filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL));
    $form['year_level'] = trim((string) filter_input(INPUT_POST, 'year_level', FILTER_UNSAFE_RAW));
    $password = (string) filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
    $confirmPassword = (string) filter_input(INPUT_POST, 'confirm_password', FILTER_UNSAFE_RAW);

    if ($form['first_name'] === '' || $form['last_name'] === '' || $form['email'] === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!in_array($form['year_level'], $yearLevels, true)) {
        $error = 'Please choose a valid year level.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $existing = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $existing->execute([$form['email']]);

            if ((int) $existing->fetchColumn() > 0) {
                $error = 'An account with that email already exists.';
            } else {
                $pdo->beginTransaction();

                $name = trim($form['first_name'] . ' ' . $form['last_name']);
                $studentNo = app_next_identifier($pdo, 'students', 'student_no', 'S');
                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $form['email'], $hashed, 'student']);
                $userId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, year_level, section_id) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $studentNo, $form['first_name'], $form['last_name'], $form['year_level'], null]);

                $pdo->commit();

                header('Location: login.php?registered=1');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration | SmartGrade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="smartgrade-styles.css" rel="stylesheet">
</head>
<body class="sg-login-page">
    <main class="login-shell register-shell">
        <section class="brand-panel">
            <div>
                <div class="brand-mark">SG</div>
                <h1>SmartGrade</h1>
                <p>Create your student account to access enrolled subjects, grade breakdowns, and printable grade reports.</p>
            </div>
        </section>

        <section class="login-card">
            <div class="mb-4">
                <h2>Student registration</h2>
                <p class="text-muted mb-0">Use your school email and a secure password.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_input() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="first_name" class="form-label">First name</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($form['first_name'], ENT_QUOTES) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label">Last name</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-person-vcard"></i></span>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($form['last_name'], ENT_QUOTES) ?>" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="email" class="form-label">Email address</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($form['email'], ENT_QUOTES) ?>" placeholder="name@school.edu" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="year_level" class="form-label">Year level</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-mortarboard"></i></span>
                            <select class="form-select" id="year_level" name="year_level" required>
                                <option value="">Select year level</option>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <option value="<?= htmlspecialchars($yearLevel, ENT_QUOTES) ?>" <?= $form['year_level'] === $yearLevel ? 'selected' : '' ?>><?= htmlspecialchars($yearLevel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm password</label>
                        <div class="sg-input">
                            <span class="sg-input__icon"><i class="bi bi-shield-check"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 mt-4">
                    <i class="bi bi-person-plus me-1"></i> Create student account
                </button>
                <p class="auth-switch mb-0">Already registered? <a href="login.php">Sign in</a></p>
            </form>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
