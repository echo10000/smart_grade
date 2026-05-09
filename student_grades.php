<?php
session_start();
require_once 'config.php';
// Students only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'student') {
    header('Location: login.php');
    exit;
}
// Get subject_id from GET
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
if (!$subject_id) {
    header('Location: student_dashboard.php');
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
// Fetch subject details only for the student's active enrollment.
$stmt = $pdo->prepare('SELECT sub.code, sub.name, e.school_year, e.semester
    FROM enrollments e
    JOIN subjects sub ON sub.id = e.subject_id
    WHERE e.student_id=?
        AND e.subject_id=?
        AND e.status="Enrolled"
        AND e.archived_at IS NULL
        AND sub.archived_at IS NULL
    ORDER BY e.id DESC
    LIMIT 1');
$stmt->execute([$studentId, $subject_id]);
$subject = $stmt->fetch();
if (!$subject) {
    die('Subject not found');
}
// Fetch components and grades
$compStmt = $pdo->prepare('SELECT id, name, percentage_weight FROM grade_components WHERE subject_id=? AND archived_at IS NULL');
$compStmt->execute([$subject_id]);
$components = $compStmt->fetchAll();
// Fetch grades per component
$gradeData = [];
foreach ($components as $comp) {
    $gStmt = $pdo->prepare('SELECT score, total_score FROM grades WHERE student_id=? AND subject_id=? AND component_id=?');
    $gStmt->execute([$studentId, $subject_id, $comp['id']]);
    $g = $gStmt->fetch();
    $gradeData[] = [
        'name' => $comp['name'],
        'weight' => $comp['percentage_weight'],
        'score' => $g['score'] ?? null,
        'total' => $g['total_score'] ?? null
    ];
}
// Fetch final grade if computed
$finalStmt = $pdo->prepare('SELECT gwa, weighted_grade, remarks, school_year, semester
    FROM final_grades
    WHERE student_id=?
        AND subject_id=?
        AND school_year=?
        AND semester=?
    ORDER BY id DESC LIMIT 1');
$finalStmt->execute([$studentId, $subject_id, $subject['school_year'], $subject['semester']]);
$final = $finalStmt->fetch();

// Compute current final percentage if final not computed (preview)
$previewGwa = null;
if (!$final && !empty($gradeData)) {
    $sum = 0;
    foreach ($gradeData as $gd) {
        if (!empty($gd['score']) && !empty($gd['total']) && $gd['total'] > 0) {
            $ratio = $gd['score'] / $gd['total'];
            $sum += $ratio * $gd['weight'];
        }
    }
    $previewGwa = $sum;
}

// Set custom scripts none
$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('Subject Grades', $subject['code'] . ' - ' . $subject['name']); ?>

<div class="card mb-4">
    <div class="card-header">Grade Breakdown</div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Component</th>
                    <th>Weight (%)</th>
                    <th>Score</th>
                    <th>Total Score</th>
                    <th>Weighted Contribution</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gradeData as $gd): ?>
                    <?php
                        $contrib = null;
                        if (!empty($gd['score']) && !empty($gd['total']) && $gd['total'] > 0) {
                            $contrib = ($gd['score'] / $gd['total']) * $gd['weight'];
                        }
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($gd['name']) ?></td>
                        <td><?= htmlspecialchars($gd['weight']) ?></td>
                        <td><?= is_null($gd['score']) ? '&mdash;' : htmlspecialchars($gd['score']) ?></td>
                        <td><?= is_null($gd['total']) ? '&mdash;' : htmlspecialchars($gd['total']) ?></td>
                        <td><?= is_null($contrib) ? '&mdash;' : number_format($contrib, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($final): ?>
    <div class="alert alert-info"><strong>GWA Grade Point:</strong> <?= $final['gwa'] === null ? '&mdash;' : htmlspecialchars($final['gwa']) ?> | <strong>Final Percentage:</strong> <?= htmlspecialchars($final['weighted_grade']) ?>% | <strong>Remarks:</strong> <span class="<?= sg_badge_class($final['remarks']) ?>"><?= htmlspecialchars($final['remarks']) ?></span></div>
    <p><strong>School Year:</strong> <?= htmlspecialchars($final['school_year']) ?> | <strong>Semester:</strong> <?= htmlspecialchars($final['semester']) ?></p>
<?php elseif ($previewGwa !== null): ?>
    <div class="alert alert-warning"><strong>Current Final Percentage Preview:</strong> <?= number_format($previewGwa, 2) ?>%</div>
<?php else: ?>
    <div class="alert alert-secondary">No grade data available.</div>
<?php endif; ?>

<?php require 'footer.php'; ?>
