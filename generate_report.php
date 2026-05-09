<?php
session_start();
require_once 'config.php';
require_once 'components.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'teacher'], true)) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$student_id = null;
$studentOptionsHtml = '';

if ($role === 'admin') {
    $students = $pdo->query('SELECT id, first_name, last_name FROM students WHERE archived_at IS NULL ORDER BY first_name, last_name')->fetchAll();
} else {
    $teacherId = app_teacher_id($pdo, (int) $userId);
    $stmt = $pdo->prepare('SELECT DISTINCT s.id, s.first_name, s.last_name
        FROM students s
        JOIN enrollments e ON e.student_id = s.id
        JOIN subjects sub ON sub.id = e.subject_id
        WHERE sub.teacher_id = ? AND e.status="Enrolled" AND e.archived_at IS NULL AND s.archived_at IS NULL AND sub.archived_at IS NULL
        ORDER BY s.first_name, s.last_name');
    $stmt->execute([$teacherId]);
    $students = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $student_id = $student_id ? (int) $student_id : null;
    if ($student_id) {
        if ($role === 'teacher') {
            require_teacher_student($pdo, (int) $student_id, (int) $teacherId);
        }
        $stuStmt = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id=? AND archived_at IS NULL');
        $stuStmt->execute([$student_id]);
        $stu = $stuStmt->fetch();

        if ($stu) {
            $gradesSql = 'SELECT fg.*, sub.code, sub.name FROM final_grades fg
                JOIN subjects sub ON sub.id=fg.subject_id
                JOIN enrollments e ON e.student_id=fg.student_id
                    AND e.subject_id=fg.subject_id
                    AND e.school_year=fg.school_year
                    AND e.semester=fg.semester
                WHERE fg.student_id=?
                    AND sub.archived_at IS NULL
                    AND e.status="Enrolled"
                    AND e.archived_at IS NULL';
            $gradeParams = [$student_id];
            if ($role === 'teacher') {
                $gradesSql .= ' AND sub.teacher_id=?';
                $gradeParams[] = $teacherId;
            }
            $gradesSql .= ' ORDER BY fg.school_year DESC, fg.semester DESC, sub.code ASC';
            $gradesStmt = $pdo->prepare($gradesSql);
            $gradesStmt->execute($gradeParams);
            $grades = $gradesStmt->fetchAll();

            $studentName = $stu['first_name'] . ' ' . $stu['last_name'];
            $filename = app_report_file_path((int) $student_id, $studentName, 'pdf');
            app_write_grade_report_pdf($filename, $studentName, $grades, date('M d, Y h:i A'));

            $stmtIns = $pdo->prepare('INSERT INTO grade_reports (student_id, generated_by, file_path) VALUES (?, ?, ?)');
            $stmtIns->execute([$student_id, $userId, $filename]);
            $message = 'Report generated: ' . basename($filename);
        } else {
            $error = 'Student not found.';
        }
    } else {
        $error = 'Please select a student.';
    }
}

$studentOptionsHtml = implode('', array_map(
    static function (array $student) use ($student_id): string {
        $isSelected = $student_id !== null && $student_id === (int) $student['id'];

        return '<option value="' . (int) $student['id'] . '"' . ($isSelected ? ' selected' : '') . '>' .
            htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) .
            '</option>';
    },
    $students
));

$pageTitle = 'Generate PDF Report | SmartGrade';
$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('Generate Grade Report', 'Create a reusable PDF report file for a selected student.'); ?>

<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">Report Builder</div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_input() ?>
            <div class="row g-3 align-items-end">
                <?= sg_field('Student', 'student_id', 'bi-person-lines-fill', '<select id="student_id" name="student_id" class="form-select" required><option value="">-- Select --</option>' . $studentOptionsHtml . '</select>', 'col-lg-8') ?>
                <div class="col-lg-auto">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Generate Report</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require 'footer.php'; ?>
