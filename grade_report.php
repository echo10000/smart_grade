<?php
// grade_report.php – printable grade report card per student
// This page allows an admin, teacher, or student to view a student's final grades
// in a print‑friendly format with a school header. It uses Bootstrap for styling
// and hides navigation elements during printing.  Admins and teachers can select
// any student from a drop‑down, while students can only view their own report.

session_start();
require_once 'config.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$userRole = $_SESSION['user']['role'];
$userId   = $_SESSION['user']['id'];
$teacherId = null;
if ($userRole === 'teacher') {
    $teacherId = app_teacher_id($pdo, (int) $userId);
    if (!$teacherId) {
        die('Teacher record not found');
    }
}

// Determine if user has permission; only admin, teacher, or student can view
if (!in_array($userRole, ['admin', 'teacher', 'student'])) {
    header('Location: login.php');
    exit;
}

// Fetch list of students for admin/teacher selection
$students = [];
if ($userRole === 'admin') {
    $students = $pdo->query('SELECT id, first_name, last_name FROM students WHERE archived_at IS NULL ORDER BY first_name, last_name')->fetchAll();
} elseif ($userRole === 'teacher') {
    // Teachers see only their enrolled students
    $stmt = $pdo->prepare('SELECT DISTINCT s.id, s.first_name, s.last_name
        FROM students s
        JOIN enrollments e ON e.student_id = s.id
        JOIN subjects sub ON sub.id = e.subject_id
        WHERE sub.teacher_id = ?
            AND e.status="Enrolled"
            AND s.archived_at IS NULL
            AND e.archived_at IS NULL
            AND sub.archived_at IS NULL
        ORDER BY s.first_name, s.last_name');
    $stmt->execute([$teacherId]);
    $students = $stmt->fetchAll();
} else {
    // Student role – fetch their own student record id
    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM students WHERE user_id=? AND archived_at IS NULL');
    $stmt->execute([$userId]);
    $self = $stmt->fetch();
    if ($self) {
        $students = [$self];
    }
}

// Determine selected student id
$selectedStudentId = null;
if ($userRole === 'student') {
    // Students can only view their own report
    $selectedStudentId = $students[0]['id'] ?? null;
} else {
    $selectedStudentId = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    if ($selectedStudentId === null || $selectedStudentId === 0) {
        $selectedStudentId = null;
    }
}

// Fetch selected student's name and final grades
$studentName = '';
$finalGrades = [];
if ($selectedStudentId) {
    if ($userRole === 'teacher') {
        require_teacher_student($pdo, (int) $selectedStudentId, (int) $teacherId);
    }

    $stmt = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id=? AND archived_at IS NULL');
    $stmt->execute([$selectedStudentId]);
    $stu = $stmt->fetch();
    if ($stu) {
        $studentName = $stu['first_name'] . ' ' . $stu['last_name'];
        $sql = 'SELECT fg.gwa, fg.weighted_grade, fg.remarks, fg.school_year, fg.semester, sub.code, sub.name
            FROM final_grades fg
            JOIN subjects sub ON sub.id = fg.subject_id
            JOIN enrollments e ON e.student_id = fg.student_id
                AND e.subject_id = fg.subject_id
                AND e.school_year = fg.school_year
                AND e.semester = fg.semester
            WHERE fg.student_id=?
                AND sub.archived_at IS NULL
                AND e.status="Enrolled"
                AND e.archived_at IS NULL';
        $params = [$selectedStudentId];
        if ($userRole === 'teacher') {
            $sql .= ' AND sub.teacher_id=?';
            $params[] = $teacherId;
        }
        $sql .= ' ORDER BY fg.school_year DESC, fg.semester DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $finalGrades = $stmt->fetchAll();
    }
}

// Prepare custom scripts for printing
$customScripts  = '<script>function printReport(){window.print();}</script>';
$bodyClass = 'sg-print-page';

require 'header.php';
?>

<?php sg_page_header('Grade Report', 'Printable view of computed student grades by term.'); ?>

<?php if ($userRole !== 'student'): ?>
<form method="get" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Select Student', 'student_id', 'bi-person-lines-fill', '<select name="student_id" id="student_id" class="form-select" onchange="this.form.submit()" required><option value="">-- Select --</option>' . implode('', array_map(static function ($s) use ($selectedStudentId) { return '<option value="' . (int) $s['id'] . '"' . (($selectedStudentId == $s['id']) ? ' selected' : '') . '>' . htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) . '</option>'; }, $students)) . '</select>') ?>
    </div>
</form>
<?php endif; ?>

<?php if ($selectedStudentId && $studentName): ?>
<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-3">
            <div>
                <h4 class="mb-0">SmartGrade University</h4>
                <small class="text-muted">Dumaguete, Philippines</small>
            </div>
            <div class="text-end">
                <button class="btn btn-outline-primary btn-print" onclick="printReport()"><i class="bi bi-printer"></i> Print</button>
            </div>
        </div>
        <hr>
        <h5 class="mb-3">Student: <?= htmlspecialchars($studentName) ?></h5>
        <?php if ($finalGrades): ?>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>GWA Grade Point</th>
                            <th>Final Percentage</th>
                            <th>Remarks</th>
                            <th>School Year</th>
                            <th>Semester</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($finalGrades as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['code'] . ' - ' . $g['name']) ?></td>
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
        <?php else: ?>
            <p>No final grades found for this student.</p>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($userRole === 'student'): ?>
    <p>You do not have any grades yet.</p>
<?php endif; ?>

<?php require 'footer.php'; ?>
