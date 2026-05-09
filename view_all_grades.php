<?php
session_start();
require_once 'config.php';
// Only teachers
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'teacher') {
    header('Location: login.php');
    exit;
}

// Get teacher id
$teacherUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$teacherId = app_teacher_id($pdo, (int) $teacherUserId);
if (!$teacherId) {
    die('Teacher record not found');
}

// Fetch subjects taught
$subjects = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id=? AND archived_at IS NULL ORDER BY code');
$subjects->execute([$teacherId]);
$subjects = $subjects->fetchAll();

// Select subject
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);

// Fetch final grades if subject selected
$grades = [];
if ($subject_id) {
    require_teacher_subject($pdo, (int) $subject_id, (int) $teacherId);

    // join students
    $stmt = $pdo->prepare('SELECT s.student_no, s.first_name, s.last_name, fg.gwa, fg.weighted_grade, fg.remarks, fg.school_year, fg.semester
        FROM final_grades fg
        JOIN students s ON s.id = fg.student_id
        JOIN subjects sub ON sub.id = fg.subject_id
        JOIN enrollments e ON e.student_id = fg.student_id
            AND e.subject_id = fg.subject_id
            AND e.school_year = fg.school_year
            AND e.semester = fg.semester
        WHERE fg.subject_id=?
            AND sub.teacher_id=?
            AND s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND e.status="Enrolled"
            AND e.archived_at IS NULL');
    $stmt->execute([$subject_id, $teacherId]);
    $grades = $stmt->fetchAll();
}

// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#gradesTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php sg_page_header('View Student Grades', 'Review computed grades for each taught subject.'); ?>

<form method="get" action="" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Select Subject', 'subject_id', 'bi-book', '<select id="subject_id" name="subject_id" class="form-select" onchange="this.form.submit()"><option value="">-- Select --</option>' . implode('', array_map(static function ($s) use ($subject_id) { return '<option value="' . (int) $s['id'] . '"' . (($subject_id == $s['id']) ? ' selected' : '') . '>' . htmlspecialchars($s['code'] . ' - ' . $s['name']) . '</option>'; }, $subjects)) . '</select>') ?>
    </div>
</form>

<?php if ($subject_id): ?>
<div class="card">
    <div class="card-header">Grades for Selected Subject</div>
    <div class="card-body">
        <table id="gradesTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Student No</th>
                    <th>Name</th>
                    <th>GWA Grade Point</th>
                    <th>Final Percentage</th>
                    <th>Remarks</th>
                    <th>School Year</th>
                    <th>Semester</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grades as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['student_no']) ?></td>
                        <td><?= htmlspecialchars($g['first_name'] . ' ' . $g['last_name']) ?></td>
                        <td><?= $g['gwa'] === null ? '&mdash;' : htmlspecialchars($g['gwa']) ?></td>
                        <td><?= htmlspecialchars($g['weighted_grade']) ?>%</td>
                        <td>
                            <span class="<?= sg_badge_class($g['remarks']) ?>">
                                <?= htmlspecialchars($g['remarks']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($g['school_year']) ?></td>
                        <td><?= htmlspecialchars($g['semester']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require 'footer.php'; ?>
