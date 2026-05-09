<?php
session_start();
require_once 'config.php';
// Admin only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Soft archive subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $id = (int) ($_POST['subject_id'] ?? 0);
    $pdo->prepare('UPDATE subjects SET archived_at=NOW(), archived_by=? WHERE id=?')->execute([app_current_user_id(), $id]);
    redirect_with_flash('manage_subjects.php', 'success', 'Subject archived successfully.');
}

// Handle add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $code       = filter_input(INPUT_POST, 'code', FILTER_SANITIZE_STRING);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $units      = filter_input(INPUT_POST, 'units', FILTER_SANITIZE_NUMBER_INT);
    $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_SANITIZE_NUMBER_INT);
    if ($subject_id) {
        $stmt = $pdo->prepare('UPDATE subjects SET code=?, name=?, units=?, teacher_id=? WHERE id=?');
        $stmt->execute([$code, $name, $units, $teacher_id ?: null, $subject_id]);
        $message = 'Subject updated successfully';
    } else {
        $restore = $pdo->prepare('SELECT id FROM subjects WHERE code=? AND archived_at IS NOT NULL');
        $restore->execute([$code]);
        $restoreId = $restore->fetchColumn();
        if ($restoreId) {
            $stmt = $pdo->prepare('UPDATE subjects SET name=?, units=?, teacher_id=?, archived_at=NULL, archived_by=NULL WHERE id=?');
            $stmt->execute([$name, $units, $teacher_id ?: null, $restoreId]);
            $message = 'Archived subject restored successfully';
        } else {
            $stmt = $pdo->prepare('INSERT INTO subjects (code, name, units, teacher_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$code, $name, $units, $teacher_id ?: null]);
            $message = 'Subject added successfully';
        }
    }
}

// Fetch subjects
$subjects = $pdo->query('SELECT s.*, t.first_name, t.last_name FROM subjects s LEFT JOIN teachers t ON t.id = s.teacher_id WHERE s.archived_at IS NULL ORDER BY s.id')->fetchAll();

// Fetch teachers
$teachers = $pdo->query('SELECT id, first_name, last_name FROM teachers WHERE archived_at IS NULL ORDER BY first_name')->fetchAll();

// Editing data
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id=? AND archived_at IS NULL');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
}
?>
<?php
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#subjectsTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php
$subjectAction = '<a href="#addEditForm" class="btn btn-primary" data-bs-toggle="collapse" aria-expanded="' . (isset($editData) ? 'true' : 'false') . '" aria-controls="addEditForm">' . (isset($editData) ? 'Edit Subject' : 'Add Subject') . '</a>';
sg_page_header('Manage Subjects', 'Configure course codes, units, and faculty assignments.', $subjectAction);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Subject List</div>
    <div class="card-body">
        <table id="subjectsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Units</th>
                    <th>Teacher</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subjects as $sub): ?>
                <tr>
                    <td><?= $sub['id'] ?></td>
                    <td><?= htmlspecialchars($sub['code']) ?></td>
                    <td><?= htmlspecialchars($sub['name']) ?></td>
                    <td><?= htmlspecialchars($sub['units']) ?></td>
                    <td><?= $sub['teacher_id'] ? htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name']) : '&mdash;' ?></td>
                    <td>
                        <a href="manage_subjects.php?edit=<?= $sub['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this subject?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Archive"><i class="bi bi-archive"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="collapse <?= isset($editData) ? 'show' : '' ?> mb-4" id="addEditForm">
    <div class="card">
        <div class="card-body">
            <h5 class="card-title mb-3"><?= isset($editData) ? 'Edit Subject' : 'Add Subject' ?></h5>
            <form method="post" action="">
                <?= csrf_input() ?>
                <input type="hidden" name="subject_id" value="<?= $editData['id'] ?? '' ?>">
                <div class="row mb-3">
                    <?= sg_field('Subject Code', 'code', 'bi-hash', '<input type="text" id="code" name="code" class="form-control" value="' . htmlspecialchars($editData['code'] ?? '', ENT_QUOTES) . '" required>') ?>
                    <?= sg_field('Subject Name', 'name', 'bi-book', '<input type="text" id="name" name="name" class="form-control" value="' . htmlspecialchars($editData['name'] ?? '', ENT_QUOTES) . '" required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Units', 'units', 'bi-123', '<input type="number" id="units" name="units" class="form-control" value="' . htmlspecialchars($editData['units'] ?? '', ENT_QUOTES) . '" required>', 'col-md-4') ?>
                    <?= sg_field('Teacher', 'teacher_id', 'bi-person-badge', '<select id="teacher_id" name="teacher_id" class="form-select"><option value="">-- None --</option>' . implode('', array_map(static function ($t) use ($editData) { return '<option value="' . (int) $t['id'] . '"' . ((!empty($editData) && $editData['teacher_id'] == $t['id']) ? ' selected' : '') . '>' . htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) . '</option>'; }, $teachers)) . '</select>', 'col-md-8') ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= isset($editData) ? 'Update' : 'Add' ?> Subject</button>
            </form>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
