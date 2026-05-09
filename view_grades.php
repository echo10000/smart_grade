<?php
session_start();
require_once 'config.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'student') {
    header('Location: login.php');
    exit;
}

$studentUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM students WHERE user_id=? AND archived_at IS NULL');
$stmt->execute([$studentUserId]);
$studentId = $stmt->fetchColumn();
if (!$studentId) {
    die('Student record not found');
}

$enrollments = $pdo->prepare('SELECT e.subject_id, sub.code, sub.name, e.school_year, e.semester
    FROM enrollments e
    JOIN subjects sub ON sub.id = e.subject_id
    WHERE e.student_id=?
        AND e.status="Enrolled"
        AND e.archived_at IS NULL
        AND sub.archived_at IS NULL
    ORDER BY sub.code');
$enrollments->execute([$studentId]);
$enrollments = $enrollments->fetchAll();

$subjects = [];
foreach ($enrollments as $en) {
    $compStmt = $pdo->prepare('SELECT id, name, percentage_weight FROM grade_components WHERE subject_id=? AND archived_at IS NULL ORDER BY id');
    $compStmt->execute([$en['subject_id']]);
    $components = $compStmt->fetchAll();

    $gradeData = [];
    foreach ($components as $comp) {
        $gStmt = $pdo->prepare('SELECT score, total_score FROM grades WHERE student_id=? AND subject_id=? AND component_id=?');
        $gStmt->execute([$studentId, $en['subject_id'], $comp['id']]);
        $g = $gStmt->fetch();
        $gradeData[] = [
            'name' => $comp['name'],
            'weight' => $comp['percentage_weight'],
            'score' => $g['score'] ?? null,
            'total' => $g['total_score'] ?? null,
        ];
    }

    $finalStmt = $pdo->prepare('SELECT gwa, weighted_grade, remarks
        FROM final_grades
        WHERE student_id=?
            AND subject_id=?
            AND school_year=?
            AND semester=?
        ORDER BY id DESC LIMIT 1');
    $finalStmt->execute([$studentId, $en['subject_id'], $en['school_year'], $en['semester']]);
    $final = $finalStmt->fetch();

    $subjects[] = [
        'enrollment' => $en,
        'grades' => $gradeData,
        'final' => $final,
    ];
}

$pageTitle = 'My Grades | SmartGrade';
$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('My Grades', 'Review all enrolled subjects and their current final standing.'); ?>

<?php if ($subjects): ?>
    <?php foreach ($subjects as $subject): ?>
        <?php $en = $subject['enrollment']; ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= htmlspecialchars($en['code'] . ' - ' . $en['name']) ?></span>
                <a href="student_grades.php?subject_id=<?= $en['subject_id'] ?>" class="btn btn-sm btn-outline-primary">View Details</a>
            </div>
            <div class="card-body">
                <div class="mb-3 text-muted"><?= htmlspecialchars($en['school_year'] . ' | ' . $en['semester']) ?></div>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Weight (%)</th>
                            <th>Score</th>
                            <th>Total Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subject['grades'] as $gd): ?>
                            <tr>
                                <td><?= htmlspecialchars($gd['name']) ?></td>
                                <td><?= htmlspecialchars($gd['weight']) ?></td>
                                <td><?= is_null($gd['score']) ? '&mdash;' : htmlspecialchars($gd['score']) ?></td>
                                <td><?= is_null($gd['total']) ? '&mdash;' : htmlspecialchars($gd['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($subject['final']): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <strong>GWA Grade Point:</strong> <?= $subject['final']['gwa'] === null ? '&mdash;' : htmlspecialchars($subject['final']['gwa']) ?>
                        <span class="ms-2"><strong>Final Percentage:</strong> <?= htmlspecialchars($subject['final']['weighted_grade']) ?>%</span>
                        <span class="ms-2 <?= sg_badge_class($subject['final']['remarks']) ?>">
                            <?= htmlspecialchars($subject['final']['remarks']) ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mt-3 mb-0">Final grade is not available yet.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">No enrolled subjects found.</div>
<?php endif; ?>

<?php require 'footer.php'; ?>
