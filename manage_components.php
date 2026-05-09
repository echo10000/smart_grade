<?php
session_start();
require_once 'config.php';
// Only teachers can manage components
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'teacher') {
    header('Location: login.php');
    exit;
}

// Get teacher's ID in teachers table
$teacherUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$teacherId = app_teacher_id($pdo, (int) $teacherUserId);
if (!$teacherId) {
    die('Teacher record not found');
}

// Handle soft archive of component
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $id = (int) ($_POST['component_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT subject_id FROM grade_components WHERE id=? AND archived_at IS NULL');
    $stmt->execute([$id]);
    $componentSubject = (int) $stmt->fetchColumn();
    require_teacher_subject($pdo, $componentSubject, $teacherId);
    $pdo->prepare('UPDATE grade_components SET archived_at=NOW(), archived_by=? WHERE id=?')->execute([app_current_user_id(), $id]);
    redirect_with_flash('manage_components.php?subject_id=' . $componentSubject, 'success', 'Component archived successfully.');
}

// Handle addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $weight     = filter_input(INPUT_POST, 'weight', FILTER_VALIDATE_FLOAT);
    if ($subject_id && $name && $weight >= 0) {
        require_teacher_subject($pdo, (int) $subject_id, $teacherId);
        // Check existing total weight for this subject
        $stmt = $pdo->prepare('SELECT SUM(percentage_weight) AS total FROM grade_components WHERE subject_id=? AND archived_at IS NULL');
        $stmt->execute([$subject_id]);
        $sum = $stmt->fetch()['total'] ?? 0;
        if ($sum + $weight > 100) {
            $error = 'Total percentage weight exceeds 100%';
        } else {
            $restore = $pdo->prepare('SELECT id FROM grade_components WHERE subject_id=? AND name=? AND archived_at IS NOT NULL');
            $restore->execute([$subject_id, $name]);
            $restoreId = $restore->fetchColumn();
            if ($restoreId) {
                $stmt = $pdo->prepare('UPDATE grade_components SET percentage_weight=?, archived_at=NULL, archived_by=NULL WHERE id=?');
                $stmt->execute([$weight, $restoreId]);
                $message = 'Archived component restored successfully';
            } else {
                $stmt = $pdo->prepare('INSERT INTO grade_components (subject_id, name, percentage_weight) VALUES (?, ?, ?)');
                $stmt->execute([$subject_id, $name, $weight]);
                $message = 'Component added successfully';
            }
        }
    } else {
        $error = 'Please fill all fields correctly';
    }
}

// Get subjects taught by this teacher
$subjects = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id=? AND archived_at IS NULL ORDER BY code');
$subjects->execute([$teacherId]);
$subjects = $subjects->fetchAll();

// Get components for selected subject (optionally)
$selectedSubject = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
if (!$selectedSubject && !empty($subjects)) {
    $selectedSubject = $subjects[0]['id'];
}
if ($selectedSubject) {
    require_teacher_subject($pdo, (int) $selectedSubject, $teacherId);
    $compStmt = $pdo->prepare('SELECT * FROM grade_components WHERE subject_id=? AND archived_at IS NULL ORDER BY id');
    $compStmt->execute([$selectedSubject]);
    $components = $compStmt->fetchAll();
} else {
    $components = [];
}
?>
<?php
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#componentsTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php sg_page_header('Manage Grade Components', 'Define grading weights for each subject before computing final grades.'); ?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Subject selection -->
<form method="get" action="" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Select Subject', 'subject_id', 'bi-book', '<select id="subject_id" name="subject_id" class="form-select" onchange="this.form.submit()">' . implode('', array_map(static function ($sub) use ($selectedSubject) { return '<option value="' . (int) $sub['id'] . '"' . (($selectedSubject == $sub['id']) ? ' selected' : '') . '>' . htmlspecialchars($sub['code'] . ' - ' . $sub['name']) . '</option>'; }, $subjects)) . '</select>', 'col-md-8') ?>
    </div>
</form>

<!-- Display total weight -->
<?php
$totalWeight = 0;
foreach ($components as $c) { $totalWeight += (float)$c['percentage_weight']; }
?>
<div class="mb-3">
    <div class="alert alert-info">Total Weight: <?= number_format($totalWeight, 2) ?>% (must equal 100%)</div>
</div>

<div class="card mb-4">
    <div class="card-header">Components for Selected Subject</div>
    <div class="card-body">
        <table id="componentsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Weight (%)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($components as $c): ?>
                    <tr>
                        <td><?= $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['name']) ?></td>
                        <td><?= htmlspecialchars($c['percentage_weight']) ?></td>
                        <td>
                            <form method="post" class="d-inline" onsubmit="return confirm('Archive this component?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="component_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Archive"><i class="bi bi-archive"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add new component -->
<div class="card mb-4">
    <div class="card-header">Add New Component</div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_input() ?>
            <input type="hidden" name="subject_id" value="<?= $selectedSubject ?>">
            <div class="row mb-3">
                <?= sg_field('Component Name', 'name', 'bi-list-check', '<input type="text" id="name" name="name" class="form-control" required>') ?>
                <?= sg_field('Percentage Weight', 'weight', 'bi-percent', '<input type="number" step="0.01" id="weight" name="weight" class="form-control" required>') ?>
            </div>
            <button type="submit" class="btn btn-primary">Add Component</button>
        </form>
    </div>
</div>

<?php require 'footer.php'; ?>
