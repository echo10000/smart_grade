<?php
session_start();
require_once 'config.php';
// Students only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'student') {
    header('Location: login.php');
    exit;
}
// Get student ID
$studentUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM students WHERE user_id=? AND archived_at IS NULL');
$stmt->execute([$studentUserId]);
$studentId = $stmt->fetchColumn();
if (!$studentId) {
    die('Student record not found');
}
// Fetch enrollments with subject info
$enrollmentsStmt = $pdo->prepare('SELECT e.subject_id, sub.code, sub.name, e.school_year, e.semester FROM enrollments e
    JOIN subjects sub ON sub.id = e.subject_id
    WHERE e.student_id=?
        AND e.status="Enrolled"
        AND e.archived_at IS NULL
        AND sub.archived_at IS NULL');
$enrollmentsStmt->execute([$studentId]);
$subjects = $enrollmentsStmt->fetchAll();
// For each subject, fetch latest final grade if exists
$subjectStatuses = [];
foreach ($subjects as $sub) {
    $fgStmt = $pdo->prepare('SELECT gwa, remarks
        FROM final_grades
        WHERE student_id=?
            AND subject_id=?
            AND school_year=?
            AND semester=?
        ORDER BY id DESC LIMIT 1');
    $fgStmt->execute([$studentId, $sub['subject_id'], $sub['school_year'], $sub['semester']]);
    $final = $fgStmt->fetch();
    $subjectStatuses[] = [
        'subject_id' => $sub['subject_id'],
        'code' => $sub['code'],
        'name' => $sub['name'],
        'school_year' => $sub['school_year'],
        'semester' => $sub['semester'],
        'gwa' => $final['gwa'] ?? null,
        'remarks' => $final['remarks'] ?? null
    ];
}

// Prepare DataTables scripts
$customScripts = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">';
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js\"></script>";
$customScripts .= "\n<script src=\"https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js\"></script>";
$customScripts .= "\n<script>\n$(document).ready(function() {\n    $('#studentSubjectsTable').DataTable();\n});\n</script>";

require 'header.php';
?>

<?php sg_page_header('My Subjects', 'Check your enrolled subjects and current final grade status.'); ?>

<div class="card">
    <div class="card-body">
        <table id="studentSubjectsTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>School Year</th>
                    <th>Semester</th>
                    <th>GWA Grade Point</th>
                    <th>Remarks</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjectStatuses as $stat): ?>
                    <tr>
                        <td><?= htmlspecialchars($stat['code'] . ' - ' . $stat['name']) ?></td>
                        <td><?= htmlspecialchars($stat['school_year']) ?></td>
                        <td><?= htmlspecialchars($stat['semester']) ?></td>
                        <td><?= $stat['gwa'] === null ? '&mdash;' : htmlspecialchars($stat['gwa']) ?></td>
                        <td>
                            <?php if ($stat['remarks']): ?>
                                <span class="<?= sg_badge_class($stat['remarks']) ?>">
                                    <?= htmlspecialchars($stat['remarks']) ?>
                                </span>
                            <?php else: ?>
                                <span class="<?= sg_badge_class('Pending') ?>">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="student_grades.php?subject_id=<?= $stat['subject_id'] ?>" class="btn btn-sm btn-primary">View Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require 'footer.php'; ?>
