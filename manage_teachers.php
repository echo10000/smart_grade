<?php
session_start();
require_once 'config.php';
// Only admin can manage teachers
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Soft archive teacher record and deactivate associated user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT user_id FROM teachers WHERE id=?');
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();
    if ($teacher) {
        $pdo->prepare('UPDATE teachers SET archived_at=NOW(), archived_by=? WHERE id=?')->execute([app_current_user_id(), $teacherId]);
        $pdo->prepare('UPDATE users SET status="inactive" WHERE id=?')->execute([$teacher['user_id']]);
        redirect_with_flash('manage_teachers.php', 'success', 'Teacher archived successfully.');
    }
}

// Handle add/update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_SANITIZE_NUMBER_INT);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password   = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
    $employee_no= filter_input(INPUT_POST, 'employee_no', FILTER_SANITIZE_STRING);
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $last_name  = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $department = filter_input(INPUT_POST, 'department', FILTER_SANITIZE_STRING);

    try {
        $pdo->beginTransaction();
        if ($teacher_id) {
            // Update existing teacher/user
            if ($password) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET name=?, email=?, password=? WHERE id=(SELECT user_id FROM teachers WHERE id=?)')
                    ->execute([$name, $email, $hashed, $teacher_id]);
            } else {
                $pdo->prepare('UPDATE users SET name=?, email=? WHERE id=(SELECT user_id FROM teachers WHERE id=?)')
                    ->execute([$name, $email, $teacher_id]);
            }
            $pdo->prepare('UPDATE teachers SET employee_no=?, first_name=?, last_name=?, department=? WHERE id=?')
                ->execute([$employee_no, $first_name, $last_name, $department, $teacher_id]);
            $message = 'Teacher updated successfully';
        } else {
            // Create new teacher
            $restore = $pdo->prepare('SELECT t.id, t.user_id FROM teachers t WHERE t.employee_no=? AND t.archived_at IS NOT NULL');
            $restore->execute([$employee_no]);
            $restoreRow = $restore->fetch();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            if ($restoreRow) {
                $pdo->prepare('UPDATE users SET name=?, email=?, password=?, status="active" WHERE id=?')
                    ->execute([$name, $email, $hashed, $restoreRow['user_id']]);
                $pdo->prepare('UPDATE teachers SET first_name=?, last_name=?, department=?, archived_at=NULL, archived_by=NULL WHERE id=?')
                    ->execute([$first_name, $last_name, $department, $restoreRow['id']]);
                $message = 'Archived teacher restored successfully';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hashed, 'teacher']);
                $userId = $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO teachers (user_id, employee_no, first_name, last_name, department) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $employee_no, $first_name, $last_name, $department]);
                $message = 'Teacher added successfully';
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error saving teacher: ' . $e->getMessage();
    }
}

// Fetch teachers
$teachers = $pdo->query('SELECT t.id, t.employee_no, t.first_name, t.last_name, t.department, u.email, u.name AS user_name
    FROM teachers t JOIN users u ON u.id = t.user_id
    WHERE t.archived_at IS NULL
    ORDER BY t.id ASC')->fetchAll();

// Fetch editing data
$editData = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT t.*, u.name AS user_name, u.email FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.archived_at IS NULL');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch() ?: null;
}

$isEditing = $editData !== null;
$employeeNumberValue = $isEditing ? (string) $editData['employee_no'] : app_next_identifier($pdo, 'teachers', 'employee_no', 'T');
$selectedDepartment = $editData['department'] ?? '';

$departmentOptionsHtml = '<option value="">-- Select --</option>';
foreach (app_department_options() as $departmentOption) {
    $departmentOptionsHtml .= '<option value="' . htmlspecialchars($departmentOption, ENT_QUOTES) . '"' . ($selectedDepartment === $departmentOption ? ' selected' : '') . '>' . htmlspecialchars($departmentOption) . '</option>';
}
?>
<?php
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#teachersTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php
$teacherAction = '<a href="#addEditForm" class="btn btn-primary" data-bs-toggle="collapse" aria-expanded="' . ($isEditing ? 'true' : 'false') . '" aria-controls="addEditForm">' . ($isEditing ? 'Edit Teacher' : 'Add Teacher') . '</a>';
sg_page_header('Manage Teachers', 'Maintain faculty profiles and account access.', $teacherAction);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Teacher List</div>
    <div class="card-body">
        <table id="teachersTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Employee No</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
                <tr>
                    <td><?= $t['id'] ?></td>
                    <td><?= htmlspecialchars($t['employee_no']) ?></td>
                    <td><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></td>
                    <td><?= htmlspecialchars($t['email']) ?></td>
                    <td><?= htmlspecialchars($t['department']) ?></td>
                    <td>
                        <a href="manage_teachers.php?edit=<?= $t['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this teacher?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Archive"><i class="bi bi-archive"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="collapse <?= $isEditing ? 'show' : '' ?> mb-4" id="addEditForm">
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3"><?= $isEditing ? 'Edit Teacher' : 'Add Teacher' ?></h5>
            <form method="post" action="">
                <?= csrf_input() ?>
                <input type="hidden" name="teacher_id" value="<?= $editData['id'] ?? '' ?>">
                <div class="row mb-3">
                    <?= sg_field('Full Name', 'name', 'bi-person', '<input type="text" id="name" name="name" class="form-control" value="' . htmlspecialchars($editData['user_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                    <?= sg_field('Email', 'email', 'bi-envelope', '<input type="email" id="email" name="email" class="form-control" value="' . htmlspecialchars($editData['email'] ?? '', ENT_QUOTES) . '" required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Password' . ($isEditing ? ' (leave blank to keep unchanged)' : ''), 'password', 'bi-shield-lock', '<input type="password" id="password" name="password" class="form-control">') ?>
                    <?= sg_field('Employee No', 'employee_no', 'bi-upc-scan', '<input type="text" id="employee_no" name="employee_no" class="form-control" value="' . htmlspecialchars($employeeNumberValue, ENT_QUOTES) . '" readonly required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('First Name', 'first_name', 'bi-person-vcard', '<input type="text" id="first_name" name="first_name" class="form-control" value="' . htmlspecialchars($editData['first_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                    <?= sg_field('Last Name', 'last_name', 'bi-person-vcard-fill', '<input type="text" id="last_name" name="last_name" class="form-control" value="' . htmlspecialchars($editData['last_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                </div>
                <div class="mb-3">
                    <?= sg_field('Department', 'department', 'bi-building', '<select id="department" name="department" class="form-select" required>' . $departmentOptionsHtml . '</select>', 'col-12') ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Update' : 'Add' ?> Teacher</button>
            </form>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
