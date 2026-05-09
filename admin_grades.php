<?php
session_start();
require_once 'config.php';
// Admin only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Optionally filter by subject
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);

// Fetch subjects for filter dropdown
$subjects = $pdo->query('SELECT id, code, name FROM subjects WHERE archived_at IS NULL ORDER BY code')->fetchAll();

// Fetch all final grades
if ($subject_id) {
    $stmt = $pdo->prepare('SELECT fg.*, s.student_no, s.first_name, s.last_name, sub.code, sub.name AS subject_name
        FROM final_grades fg
        JOIN students s ON s.id = fg.student_id
        JOIN subjects sub ON sub.id = fg.subject_id
        JOIN enrollments e ON e.student_id = fg.student_id
            AND e.subject_id = fg.subject_id
            AND e.school_year = fg.school_year
            AND e.semester = fg.semester
        WHERE fg.subject_id=?
            AND s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND e.status="Enrolled"
            AND e.archived_at IS NULL');
    $stmt->execute([$subject_id]);
    $grades = $stmt->fetchAll();
} else {
    $stmt = $pdo->query('SELECT fg.*, s.student_no, s.first_name, s.last_name, sub.code, sub.name AS subject_name
        FROM final_grades fg
        JOIN students s ON s.id = fg.student_id
        JOIN subjects sub ON sub.id = fg.subject_id
        JOIN enrollments e ON e.student_id = fg.student_id
            AND e.subject_id = fg.subject_id
            AND e.school_year = fg.school_year
            AND e.semester = fg.semester
        WHERE s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND e.status="Enrolled"
            AND e.archived_at IS NULL');
    $grades = $stmt->fetchAll();
}
// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#adminGradesTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php sg_page_header('All Final Grades', 'Review computed grades across all subjects and students.'); ?>

<form method="get" action="" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Filter by Subject', 'subject_id', 'bi-funnel', '<select id="subject_id" name="subject_id" class="form-select" onchange="this.form.submit()"><option value="">-- All --</option>' . implode('', array_map(static function ($s) use ($subject_id) { return '<option value="' . (int) $s['id'] . '"' . (($subject_id == $s['id']) ? ' selected' : '') . '>' . htmlspecialchars($s['code'] . ' - ' . $s['name']) . '</option>'; }, $subjects)) . '</select>') ?>
    </div>
</form>

<div class="card">
    <div class="card-body">
        <table id="adminGradesTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Student No</th>
                    <th>Name</th>
                    <th>Subject</th>
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
                        <td><?= htmlspecialchars($g['code'] . ' - ' . $g['subject_name']) ?></td>
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

<?php require 'footer.php'; ?>
