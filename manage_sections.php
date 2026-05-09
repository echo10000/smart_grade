<?php
session_start();
require_once 'config.php';
// Only admin
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Soft archive section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $id = (int) ($_POST['section_id'] ?? 0);
    $pdo->prepare('UPDATE sections SET archived_at=NOW(), archived_by=? WHERE id=?')->execute([app_current_user_id(), $id]);
    redirect_with_flash('manage_sections.php', 'success', 'Section archived successfully.');
}

// Handle add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $section_id = filter_input(INPUT_POST, 'section_id', FILTER_SANITIZE_NUMBER_INT);
    $name       = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $year_level = filter_input(INPUT_POST, 'year_level', FILTER_SANITIZE_STRING);
    $school_year= filter_input(INPUT_POST, 'school_year', FILTER_SANITIZE_STRING);
    $semester   = filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_STRING);
    if ($section_id) {
        $stmt = $pdo->prepare('UPDATE sections SET name=?, year_level=?, school_year=?, semester=? WHERE id=?');
        $stmt->execute([$name, $year_level, $school_year, $semester, $section_id]);
        $message = 'Section updated successfully';
    } else {
        $restore = $pdo->prepare('SELECT id FROM sections WHERE name=? AND year_level=? AND school_year=? AND semester=? AND archived_at IS NOT NULL');
        $restore->execute([$name, $year_level, $school_year, $semester]);
        $restoreId = $restore->fetchColumn();
        if ($restoreId) {
            $stmt = $pdo->prepare('UPDATE sections SET archived_at=NULL, archived_by=NULL WHERE id=?');
            $stmt->execute([$restoreId]);
            $message = 'Archived section restored successfully';
        } else {
            $stmt = $pdo->prepare('INSERT INTO sections (name, year_level, school_year, semester) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $year_level, $school_year, $semester]);
            $message = 'Section added successfully';
        }
    }
}

// Fetch sections
$sections = $pdo->query('SELECT * FROM sections WHERE archived_at IS NULL ORDER BY id')->fetchAll();

// Editing data
$editData = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM sections WHERE id=? AND archived_at IS NULL');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
}

$defaultSchoolYear = app_current_school_year($pdo);
$yearLevelOptions = app_year_level_options();
$semesterOptions = app_semester_options();
$isEditing = is_array($editData);
$selectedYearLevel = $editData['year_level'] ?? '';
$selectedSchoolYear = $editData['school_year'] ?? $defaultSchoolYear;
$selectedSemester = $editData['semester'] ?? ($semesterOptions[0] ?? '');

$yearLevelOptionHtml = '';
foreach ($yearLevelOptions as $yearLevelOption) {
    $selected = $selectedYearLevel === $yearLevelOption ? ' selected' : '';
    $yearLevelOptionHtml .= '<option value="' . htmlspecialchars($yearLevelOption, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($yearLevelOption) . '</option>';
}

$semesterOptionHtml = '';
foreach ($semesterOptions as $semesterOption) {
    $selected = $selectedSemester === $semesterOption ? ' selected' : '';
    $semesterOptionHtml .= '<option value="' . htmlspecialchars($semesterOption, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($semesterOption) . '</option>';
}
?>
<?php
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#sectionsTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php
$sectionAction = '<a href="#addEditForm" class="btn btn-primary" data-bs-toggle="collapse" aria-expanded="' . ($isEditing ? 'true' : 'false') . '" aria-controls="addEditForm">' . ($isEditing ? 'Edit Section' : 'Add Section') . '</a>';
sg_page_header('Manage Sections', 'Set up academic sections, year levels, and term grouping.', $sectionAction);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Section List</div>
    <div class="card-body">
        <table id="sectionsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Year Level</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sections as $sec): ?>
                <tr>
                    <td><?= $sec['id'] ?></td>
                    <td><?= htmlspecialchars($sec['name']) ?></td>
                    <td><?= htmlspecialchars($sec['year_level']) ?></td>
                    <td><?= htmlspecialchars($sec['school_year']) ?></td>
                    <td><?= htmlspecialchars($sec['semester']) ?></td>
                    <td>
                        <a href="manage_sections.php?edit=<?= $sec['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this section?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
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
            <h5 class="card-title mb-3"><?= $isEditing ? 'Edit Section' : 'Add Section' ?></h5>
            <form method="post" action="">
                <?= csrf_input() ?>
                <input type="hidden" name="section_id" value="<?= $editData['id'] ?? '' ?>">
                <div class="row mb-3">
                    <?= sg_field('Section Name', 'name', 'bi-grid', '<input type="text" id="name" name="name" class="form-control" value="' . htmlspecialchars($editData['name'] ?? '', ENT_QUOTES) . '" required>', 'col-12') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Year Level', 'year_level', 'bi-mortarboard', '<select id="year_level" name="year_level" class="form-select" required><option value="">-- Select --</option>' . $yearLevelOptionHtml . '</select>') ?>
                    <?= sg_field('School Year', 'school_year', 'bi-calendar-range', '<input type="text" id="school_year" name="school_year" class="form-control" value="' . htmlspecialchars($selectedSchoolYear, ENT_QUOTES) . '" readonly required>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Semester', 'semester', 'bi-calendar3', '<select id="semester" name="semester" class="form-select" required>' . $semesterOptionHtml . '</select>', 'col-12') ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Update' : 'Add' ?> Section</button>
            </form>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
