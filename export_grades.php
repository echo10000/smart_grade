<?php
// export_grades.php – view and export final grades as CSV
// This page allows admins and teachers to filter final grades by subject and
// download the results as a CSV file.  When the `download` parameter is set in
// the query string, a CSV response is emitted; otherwise a DataTable with
// export button is shown.

session_start();
require_once 'config.php';

// Only admin or teacher may access
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin','teacher'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['user']['role'];
$userId = (int) $_SESSION['user']['id'];
$teacherId = null;
if ($role === 'teacher') {
    $teacherId = app_teacher_id($pdo, $userId);
    if (!$teacherId) {
        die('Teacher record not found');
    }
}

// Determine if a CSV download is requested
$isDownload = isset($_GET['download']);
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
if ($subject_id && $role === 'teacher') {
    require_teacher_subject($pdo, (int) $subject_id, (int) $teacherId);
}

// Build query to fetch grades
$gradeSql = 'SELECT s.student_no, s.first_name, s.last_name, sub.code AS subject_code, sub.name AS subject_name, fg.gwa, fg.weighted_grade, fg.remarks, fg.school_year, fg.semester
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
        AND e.archived_at IS NULL';
$gradeParams = [];
if ($subject_id) {
    $gradeSql .= ' AND fg.subject_id=?';
    $gradeParams[] = $subject_id;
}
if ($role === 'teacher') {
    $gradeSql .= ' AND sub.teacher_id=?';
    $gradeParams[] = $teacherId;
}
$gradeStmt = $pdo->prepare($gradeSql);
$gradeStmt->execute($gradeParams);
$grades = $gradeStmt->fetchAll();

// If download requested, output CSV and exit
if ($isDownload) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grades_export.csv"');
    $output = fopen('php://output', 'w');
    // header row
    fputcsv($output, ['Student No','First Name','Last Name','Subject Code','Subject Name','GWA Grade Point','Final Percentage','Remarks','School Year','Semester']);
    foreach ($grades as $row) {
        fputcsv($output, [$row['student_no'], $row['first_name'], $row['last_name'], $row['subject_code'], $row['subject_name'], $row['gwa'] ?? '-', $row['weighted_grade'], $row['remarks'], $row['school_year'], $row['semester']]);
    }
    fclose($output);
    exit;
}

// If not downloading, render UI
// Fetch subjects for filter dropdown.  Teachers should only see their own subjects.
if ($role === 'teacher') {
    $subStmt = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id=? AND archived_at IS NULL ORDER BY code');
    $subStmt->execute([$teacherId]);
    $subjects = $subStmt->fetchAll();
} else {
    $subjects = $pdo->query('SELECT id, code, name FROM subjects WHERE archived_at IS NULL ORDER BY code')->fetchAll();
}

// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#exportGradesTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php sg_page_header('Export Grades', 'Filter final grades and export the current view to CSV.'); ?>

<form method="get" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Filter by Subject', 'subject_id', 'bi-funnel', '<select id="subject_id" name="subject_id" class="form-select" onchange="this.form.submit()"><option value="">-- All Subjects --</option>' . implode('', array_map(static function ($s) use ($subject_id) { return '<option value="' . (int) $s['id'] . '"' . (($subject_id == $s['id']) ? ' selected' : '') . '>' . htmlspecialchars($s['code'] . ' - ' . $s['name']) . '</option>'; }, $subjects)) . '</select>') ?>
        <div class="col-auto">
            <a href="export_grades.php?download=1<?= $subject_id ? '&subject_id=' . $subject_id : '' ?>" class="btn btn-success btn-export"><i class="bi bi-download"></i> Download CSV</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-body">
        <table id="exportGradesTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Student No</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
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
                        <td><?= htmlspecialchars($g['first_name']) ?></td>
                        <td><?= htmlspecialchars($g['last_name']) ?></td>
                        <td><?= htmlspecialchars($g['subject_code']) ?></td>
                        <td><?= htmlspecialchars($g['subject_name']) ?></td>
                        <td><?= $g['gwa'] === null ? '&mdash;' : htmlspecialchars($g['gwa']) ?></td>
                        <td><?= htmlspecialchars($g['weighted_grade']) ?>%</td>
                        <td><span class="<?= sg_badge_class($g['remarks']) ?>">
                            <?= htmlspecialchars($g['remarks']) ?></span></td>
                        <td><?= htmlspecialchars($g['school_year']) ?></td>
                        <td><?= htmlspecialchars($g['semester']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require 'footer.php'; ?>
