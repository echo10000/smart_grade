<?php
session_start();
require_once 'config.php';
// Admin only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Soft archive enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $id = (int) ($_POST['enroll_id'] ?? 0);
    $pdo->prepare('UPDATE enrollments SET status="Dropped", archived_at=NOW(), archived_by=? WHERE id=?')->execute([app_current_user_id(), $id]);
    redirect_with_flash('manage_enrollments.php', 'success', 'Enrollment archived successfully.');
}

// Handle add/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    require_csrf();
    $enroll_id   = filter_input(INPUT_POST, 'enroll_id', FILTER_SANITIZE_NUMBER_INT);
    $student_id  = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $subject_id  = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $section_id  = filter_input(INPUT_POST, 'section_id', FILTER_SANITIZE_NUMBER_INT);
    $school_year = filter_input(INPUT_POST, 'school_year', FILTER_SANITIZE_STRING);
    $semester    = filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_STRING);
    $status      = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    if ($enroll_id) {
        $stmt = $pdo->prepare('UPDATE enrollments SET student_id=?, subject_id=?, section_id=?, school_year=?, semester=?, status=? WHERE id=?');
        $stmt->execute([$student_id, $subject_id, $section_id, $school_year, $semester, $status, $enroll_id]);
        $message = 'Enrollment updated successfully';
    } else {
        $restore = $pdo->prepare('SELECT id FROM enrollments WHERE student_id=? AND subject_id=? AND school_year=? AND semester=? AND archived_at IS NOT NULL');
        $restore->execute([$student_id, $subject_id, $school_year, $semester]);
        $restoreId = $restore->fetchColumn();
        if ($restoreId) {
            $stmt = $pdo->prepare('UPDATE enrollments SET section_id=?, status=?, archived_at=NULL, archived_by=NULL WHERE id=?');
            $stmt->execute([$section_id, $status ?: 'Enrolled', $restoreId]);
            $message = 'Archived enrollment restored successfully';
        } else {
            $stmt = $pdo->prepare('INSERT INTO enrollments (student_id, subject_id, section_id, school_year, semester, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$student_id, $subject_id, $section_id, $school_year, $semester, $status ?: 'Enrolled']);
            $message = 'Enrollment added successfully';
        }
    }
}

// Fetch lists
$students  = $pdo->query('SELECT id, first_name, last_name FROM students WHERE archived_at IS NULL ORDER BY first_name')->fetchAll();
$subjects  = $pdo->query('SELECT id, code, name FROM subjects WHERE archived_at IS NULL ORDER BY code')->fetchAll();
$sections  = $pdo->query('SELECT id, name FROM sections WHERE archived_at IS NULL ORDER BY name')->fetchAll();

// Fetch enrollments
$enrollments = $pdo->query('SELECT e.*, s.first_name, s.last_name, sub.code AS subject_code, sec.name AS section_name FROM enrollments e
    JOIN students s ON s.id=e.student_id
    JOIN subjects sub ON sub.id=e.subject_id
    JOIN sections sec ON sec.id=e.section_id
    WHERE e.archived_at IS NULL
    ORDER BY e.id')->fetchAll();

// Editing data
$editData = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE id=? AND archived_at IS NULL');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
}

$defaultSchoolYear = app_current_school_year($pdo);
$semesterOptions = app_semester_options();
$isEditing = is_array($editData);
$selectedStudentId = (string) ($editData['student_id'] ?? '');
$selectedSubjectId = (string) ($editData['subject_id'] ?? '');
$selectedSectionId = (string) ($editData['section_id'] ?? '');
$selectedSchoolYear = $editData['school_year'] ?? $defaultSchoolYear;
$selectedSemester = $editData['semester'] ?? ($semesterOptions[0] ?? '');
$selectedStatus = $editData['status'] ?? 'Enrolled';

$studentOptionHtml = '';
foreach ($students as $student) {
    $studentId = (string) $student['id'];
    $selected = $selectedStudentId === $studentId ? ' selected' : '';
    $studentOptionHtml .= '<option value="' . (int) $student['id'] . '"' . $selected . '>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</option>';
}

$subjectOptionHtml = '';
foreach ($subjects as $subject) {
    $subjectId = (string) $subject['id'];
    $selected = $selectedSubjectId === $subjectId ? ' selected' : '';
    $subjectOptionHtml .= '<option value="' . (int) $subject['id'] . '"' . $selected . '>' . htmlspecialchars($subject['code'] . ' - ' . $subject['name']) . '</option>';
}

$sectionOptionHtml = '';
foreach ($sections as $section) {
    $sectionId = (string) $section['id'];
    $selected = $selectedSectionId === $sectionId ? ' selected' : '';
    $sectionOptionHtml .= '<option value="' . (int) $section['id'] . '"' . $selected . '>' . htmlspecialchars($section['name']) . '</option>';
}

$semesterOptionHtml = '';
foreach ($semesterOptions as $semesterOption) {
    $selected = $selectedSemester === $semesterOption ? ' selected' : '';
    $semesterOptionHtml .= '<option value="' . htmlspecialchars($semesterOption, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($semesterOption) . '</option>';
}
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#enrollmentsTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php
$enrollmentAction = '<a href="#addEditForm" class="btn btn-primary" data-bs-toggle="collapse" aria-expanded="' . ($isEditing ? 'true' : 'false') . '" aria-controls="addEditForm">' . ($isEditing ? 'Edit Enrollment' : 'Add Enrollment') . '</a>';
sg_page_header('Manage Enrollments', 'Assign students to subjects, sections, and academic terms.', $enrollmentAction);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Enrollment List</div>
    <div class="card-body">
        <table id="enrollmentsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Subject</th>
                    <th>Section</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($enrollments as $e): ?>
                <tr>
                    <td><?= $e['id'] ?></td>
                    <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
                    <td><?= htmlspecialchars($e['subject_code']) ?></td>
                    <td><?= htmlspecialchars($e['section_name']) ?></td>
                    <td><?= htmlspecialchars($e['school_year']) ?></td>
                    <td><?= htmlspecialchars($e['semester']) ?></td>
                    <td><span class="<?= sg_badge_class($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span></td>
                    <td>
                        <a href="manage_enrollments.php?edit=<?= $e['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Archive this enrollment?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="enroll_id" value="<?= $e['id'] ?>">
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
            <h5 class="card-title mb-3"><?= $isEditing ? 'Edit Enrollment' : 'Add Enrollment' ?></h5>
            <form method="post" action="">
                <?= csrf_input() ?>
                <input type="hidden" name="enroll_id" value="<?= $editData['id'] ?? '' ?>">
                <div class="row mb-3">
                    <?= sg_field('Student', 'student_id', 'bi-people', '<select id="student_id" name="student_id" class="form-select" required><option value="">-- Select --</option>' . $studentOptionHtml . '</select>') ?>
                    <?= sg_field('Subject', 'subject_id', 'bi-book', '<select id="subject_id" name="subject_id" class="form-select" required><option value="">-- Select --</option>' . $subjectOptionHtml . '</select>') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Section', 'section_id', 'bi-diagram-3', '<select id="section_id" name="section_id" class="form-select" required><option value="">-- Select --</option>' . $sectionOptionHtml . '</select>', 'col-md-4') ?>
                    <?= sg_field('School Year', 'school_year', 'bi-calendar-range', '<input type="text" id="school_year" name="school_year" class="form-control" value="' . htmlspecialchars($selectedSchoolYear, ENT_QUOTES) . '" readonly required>', 'col-md-4') ?>
                    <?= sg_field('Semester', 'semester', 'bi-calendar3', '<select id="semester" name="semester" class="form-select" required>' . $semesterOptionHtml . '</select>', 'col-md-4') ?>
                </div>
                <div class="row mb-3">
                    <?= sg_field('Status', 'status', 'bi-flag', '<select id="status" name="status" class="form-select"><option value="Enrolled"' . ($selectedStatus === 'Enrolled' ? ' selected' : '') . '>Enrolled</option><option value="Dropped"' . ($selectedStatus === 'Dropped' ? ' selected' : '') . '>Dropped</option></select>', 'col-12') ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Update' : 'Add' ?> Enrollment</button>
            </form>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
