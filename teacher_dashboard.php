<?php
session_start();
require_once 'config.php';
// Only teacher role
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'teacher') {
    header('Location: login.php');
    exit;
}
// Get teacher ID
$teacherUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM teachers WHERE user_id=? AND archived_at IS NULL');
$stmt->execute([$teacherUserId]);
$teacherRow = $stmt->fetch();
if (!$teacherRow) {
    die('Teacher record not found');
}
$teacherId = $teacherRow['id'];

// Fetch subjects assigned to this teacher
$subjects = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id=? AND archived_at IS NULL ORDER BY code');
$subjects->execute([$teacherId]);
$subjects = $subjects->fetchAll();

// Compute student count per subject
$subjectStats = [];
foreach ($subjects as $sub) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM enrollments e JOIN students s ON s.id=e.student_id WHERE e.subject_id=? AND e.status="Enrolled" AND e.archived_at IS NULL AND s.archived_at IS NULL');
    $count->execute([$sub['id']]);
    $studentsCount = $count->fetchColumn();
    // Determine if there are pending grades: check if final_grades exist for all enrolled students
    $pending = $pdo->prepare('SELECT COUNT(*) FROM enrollments e
        LEFT JOIN final_grades fg ON fg.student_id=e.student_id
            AND fg.subject_id=e.subject_id
            AND fg.school_year=e.school_year
            AND fg.semester=e.semester
        JOIN students s ON s.id=e.student_id
        WHERE e.subject_id=? AND e.status="Enrolled" AND e.archived_at IS NULL AND s.archived_at IS NULL AND fg.id IS NULL');
    $pending->execute([$sub['id']]);
    $pendingCount = $pending->fetchColumn();
    $subjectStats[] = [
        'subject' => $sub,
        'studentsCount' => $studentsCount,
        'pending' => $pendingCount
    ];
}

$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('Teacher Dashboard', 'Track your assigned subjects, enrollment load, and pending grade work.'); ?>

<div class="row">
    <?php if ($subjectStats): ?>
        <?php foreach ($subjectStats as $stat): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <?= htmlspecialchars($stat['subject']['code']) ?> - <?= htmlspecialchars($stat['subject']['name']) ?>
                        </h5>
                        <p class="card-text mb-2">Students Enrolled: <strong><?= $stat['studentsCount'] ?></strong></p>
                        <p class="card-text mb-3">Pending Grade Inputs: <span class="<?= sg_badge_class($stat['pending'] > 0 ? 'Pending' : 'Passed') ?>"><?= $stat['pending'] ?></span></p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="input_grades.php?subject_id=<?= $stat['subject']['id'] ?>" class="btn btn-primary btn-sm">Input Grades</a>
                            <a href="view_all_grades.php?subject_id=<?= $stat['subject']['id'] ?>" class="btn btn-outline-primary btn-sm">View Grades</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No subjects assigned.</p>
    <?php endif; ?>
</div>

<?php require 'footer.php'; ?>
