<?php
session_start();
require_once 'config.php';
// Restrict to admin users; support both legacy and new session key
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle deletion as a soft archive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT user_id FROM students WHERE id = ?');
    $stmt->execute([$studentId]);
    $stu = $stmt->fetch();
    if ($stu) {
        $pdo->prepare('UPDATE students SET archived_at=NOW(), archived_by=? WHERE id = ?')->execute([app_current_user_id(), $studentId]);
        $pdo->prepare('UPDATE users SET status="inactive" WHERE id = ?')->execute([$stu['user_id']]);
        redirect_with_flash('manage_students.php', 'success', 'Student archived successfully.');
    }
}

// Handle create/update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password   = filter_input(INPUT_POST, 'password', FILTER_UNSAFE_RAW);
    $student_no = filter_input(INPUT_POST, 'student_no', FILTER_SANITIZE_STRING);
    $first_name = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $last_name  = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $year_level = filter_input(INPUT_POST, 'year_level', FILTER_SANITIZE_STRING);
    $section_id = filter_input(INPUT_POST, 'section_id', FILTER_SANITIZE_NUMBER_INT);

    try {
        $pdo->beginTransaction();
        if ($student_id) {
            // Update existing student and user record
            if ($password) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET name=?, email=?, password=? WHERE id=(SELECT user_id FROM students WHERE id=?)')
                    ->execute([$name, $email, $hashed, $student_id]);
            } else {
                $pdo->prepare('UPDATE users SET name=?, email=? WHERE id=(SELECT user_id FROM students WHERE id=?)')
                    ->execute([$name, $email, $student_id]);
            }
            $pdo->prepare('UPDATE students SET student_no=?, first_name=?, last_name=?, year_level=?, section_id=? WHERE id=?')
                ->execute([$student_no, $first_name, $last_name, $year_level, $section_id ?: null, $student_id]);
            $message = 'Student updated successfully';
        } else {
            // Create new user and student
            $restore = $pdo->prepare('SELECT s.id, s.user_id FROM students s WHERE s.student_no=? AND s.archived_at IS NOT NULL');
            $restore->execute([$student_no]);
            $restoreRow = $restore->fetch();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            if ($restoreRow) {
                $pdo->prepare('UPDATE users SET name=?, email=?, password=?, status="active" WHERE id=?')
                    ->execute([$name, $email, $hashed, $restoreRow['user_id']]);
                $pdo->prepare('UPDATE students SET first_name=?, last_name=?, year_level=?, section_id=?, archived_at=NULL, archived_by=NULL WHERE id=?')
                    ->execute([$first_name, $last_name, $year_level, $section_id ?: null, $restoreRow['id']]);
                $message = 'Archived student restored successfully';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hashed, 'student']);
                $userId = $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, last_name, year_level, section_id) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $student_no, $first_name, $last_name, $year_level, $section_id ?: null]);
                $message = 'Student added successfully';
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error saving student: ' . $e->getMessage();
    }
}

// Fetch existing students for listing
$students = $pdo->query('SELECT s.id, s.student_no, s.first_name, s.last_name, s.year_level, sec.name AS section_name, u.email, u.name AS user_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN sections sec ON sec.id = s.section_id
    WHERE s.archived_at IS NULL
    ORDER BY s.id ASC')->fetchAll();

// Fetch sections for dropdown
$sections = $pdo->query('SELECT id, name FROM sections WHERE archived_at IS NULL ORDER BY name')->fetchAll();

// If editing, fetch the student data
$editData = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt   = $pdo->prepare('SELECT s.*, u.name AS user_name, u.email FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.archived_at IS NULL');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch() ?: null;
}

$isEditing = $editData !== null;
$studentNumberValue = $isEditing ? (string) $editData['student_no'] : app_next_identifier($pdo, 'students', 'student_no', 'S');
$selectedYearLevel = $editData['year_level'] ?? '';
$selectedSectionId = (string) ($editData['section_id'] ?? '');

$yearLevelOptionsHtml = '<option value="">-- Select --</option>';
foreach (app_year_level_options() as $yearOption) {
    $yearLevelOptionsHtml .= '<option value="' . htmlspecialchars($yearOption, ENT_QUOTES) . '"' . ($selectedYearLevel === $yearOption ? ' selected' : '') . '>' . htmlspecialchars($yearOption) . '</option>';
}

$sectionOptionsHtml = '<option value="">-- Select --</option>';
foreach ($sections as $sec) {
    $sectionId = (string) $sec['id'];
    $sectionOptionsHtml .= '<option value="' . (int) $sec['id'] . '"' . ($selectedSectionId === $sectionId ? ' selected' : '') . '>' . htmlspecialchars($sec['name']) . '</option>';
}

// Prepare custom scripts for DataTables initialization
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#studentsTable').DataTable();\n});\n</script>";

// Include header
require 'header.php';
?>

<?php
$studentAction = '<a href="#addEditForm" class="btn btn-primary" data-bs-toggle="collapse" aria-expanded="' . ($isEditing ? 'true' : 'false') . '" aria-controls="addEditForm">' . ($isEditing ? 'Edit Student' : 'Add Student') . '</a>';
sg_page_header('Manage Students', 'Maintain student records, access, and section assignments.', $studentAction);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Student List</div>
    <div class="card-body">
        <table id="studentsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student No</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Year Level</th>
                    <th>Section</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><?= $s['id'] ?></td>
                    <td><?= htmlspecialchars($s['student_no']) ?></td>
                    <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td><?= htmlspecialchars($s['year_level']) ?></td>
                    <td><?= htmlspecialchars($s['section_name'] ?? '') ?></td>
                    <td>
                        <a href="manage_students.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this student?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Archive"><i class="bi bi-archive"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Student Form -->
<div class="collapse <?= $isEditing ? 'show' : '' ?> mb-4" id="addEditForm">
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3"><?= $isEditing ? 'Edit Student' : 'Add Student' ?></h5>
            <form method="post" action="">
                <?= csrf_input() ?>
                <input type="hidden" name="student_id" value="<?= $editData['id'] ?? '' ?>">
                <div class="row mb-3">
                    <?= sg_field('Full Name', 'name', 'bi-person', '<input type="text" id="name" name="name" class="form-control" value="' . htmlspecialchars($editData['user_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                    <?= sg_field('Email', 'email', 'bi-envelope', '<input type="email" id="email" name="email" class="form-control" value="' . htmlspecialchars($editData['email'] ?? '', ENT_QUOTES) . '" required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Password' . ($isEditing ? ' (leave blank to keep unchanged)' : ''), 'password', 'bi-shield-lock', '<input type="password" id="password" name="password" class="form-control">') ?>
                    <?= sg_field('Student No', 'student_no', 'bi-upc-scan', '<input type="text" id="student_no" name="student_no" class="form-control" value="' . htmlspecialchars($studentNumberValue, ENT_QUOTES) . '" readonly required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('First Name', 'first_name', 'bi-person-vcard', '<input type="text" id="first_name" name="first_name" class="form-control" value="' . htmlspecialchars($editData['first_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                    <?= sg_field('Last Name', 'last_name', 'bi-person-vcard-fill', '<input type="text" id="last_name" name="last_name" class="form-control" value="' . htmlspecialchars($editData['last_name'] ?? '', ENT_QUOTES) . '" required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Year Level', 'year_level', 'bi-mortarboard', '<select id="year_level" name="year_level" class="form-select">' . $yearLevelOptionsHtml . '</select>') ?>
                    <?php if ($isEditing): ?>
                        <?= sg_field('Section', 'section_id', 'bi-diagram-3', '<select id="section_id" name="section_id" class="form-select">' . $sectionOptionsHtml . '</select>') ?>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Update' : 'Add' ?> Student</button>
            </form>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
