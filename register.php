<?php
session_start();
require_once 'config.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

$sections = $pdo->query('SELECT id, name FROM sections WHERE archived_at IS NULL ORDER BY name')->fetchAll();
$yearLevels = app_year_level_options();
$departments = app_department_options();
$studentIdentifier = app_next_identifier($pdo, 'students', 'student_no', 'S');
$teacherIdentifier = app_next_identifier($pdo, 'teachers', 'employee_no', 'T');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $newRole    = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password   = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $last_name  = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $identifier = trim((string) filter_input(INPUT_POST, 'identifier', FILTER_SANITIZE_STRING));
    $year_level = filter_input(INPUT_POST, 'year_level', FILTER_SANITIZE_STRING);
    $department = filter_input(INPUT_POST, 'department', FILTER_SANITIZE_STRING);

    if ($newRole === 'student') {
        $identifier = app_next_identifier($pdo, 'students', 'student_no', 'S');
    } elseif ($newRole === 'teacher') {
        $identifier = app_next_identifier($pdo, 'teachers', 'employee_no', 'T');
    }

    if (empty($error) && $newRole && $name && $email && $password && $first_name && $last_name) {
        try {
            $pdo->beginTransaction();
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $email, $hashed, $newRole]);
            $userId = $pdo->lastInsertId();

            if ($newRole === 'student') {
                $stmt = $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, year_level, section_id) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $identifier, $first_name, $last_name, $year_level, null]);
            } elseif ($newRole === 'teacher') {
                $stmt = $pdo->prepare('INSERT INTO teachers (user_id, employee_no, first_name, last_name, department) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $identifier, $first_name, $last_name, $department]);
            }

            $pdo->commit();
            $message = ucfirst($newRole) . ' registered successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error registering user: ' . $e->getMessage();
        }
    } elseif (empty($error)) {
        $error = 'Please fill in all required fields.';
    }
}

$pageTitle = 'Register User | SmartGrade';
$customScripts = '<script>
function toggleFields() {
    var role = document.getElementById("role").value;
    var identifierLabel = document.getElementById("identifierLabel");
    var identifierInput = document.getElementById("identifier");
    var studentFields = document.getElementById("studentFields");
    var teacherFields = document.getElementById("teacherFields");
    if (role === "student") {
        identifierLabel.textContent = "Student No";
        identifierInput.value = ' . json_encode($studentIdentifier) . ';
        studentFields.style.display = "block";
        teacherFields.style.display = "none";
    } else {
        identifierLabel.textContent = "Employee No";
        identifierInput.value = ' . json_encode($teacherIdentifier) . ';
        studentFields.style.display = "none";
        teacherFields.style.display = "block";
    }
}
</script>';
require 'header.php';
?>

<h2 class="mb-4">Register New User</h2>

<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">Account Details</div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_input() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="role" class="form-label">User Role</label>
                    <select id="role" name="role" class="form-select" required onchange="toggleFields()">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="name" class="form-label">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="first_name" class="form-label">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label id="identifierLabel" for="identifier" class="form-label">Student No</label>
                    <input type="text" id="identifier" name="identifier" class="form-control" value="<?= htmlspecialchars($studentIdentifier, ENT_QUOTES) ?>" readonly required>
                </div>
            </div>

            <div id="studentFields" class="row g-3 mt-1">
                <div class="col-md-4">
                    <label for="year_level" class="form-label">Year Level</label>
                    <select id="year_level" name="year_level" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach ($yearLevels as $yearLevel): ?>
                            <option value="<?= htmlspecialchars($yearLevel) ?>"><?= htmlspecialchars($yearLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="teacherFields" class="row g-3 mt-1" style="display:none;">
                <div class="col-md-6">
                    <label for="department" class="form-label">Department</label>
                    <select id="department" name="department" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach ($departments as $departmentOption): ?>
                            <option value="<?= htmlspecialchars($departmentOption) ?>"><?= htmlspecialchars($departmentOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus"></i> Register</button>
            </div>
        </form>
    </div>
</div>

<script>toggleFields();</script>

<?php require 'footer.php'; ?>
