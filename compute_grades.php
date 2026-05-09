<?php
session_start();
require_once 'config.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'teacher'], true)) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$subject_id = null;
$school_year = app_current_school_year($pdo);
$semesterOptions = app_semester_options();
$semester = $semesterOptions[0] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $subject_id  = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $school_year = filter_input(INPUT_POST, 'school_year', FILTER_SANITIZE_STRING);
    $semester    = filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_STRING);

    if ($subject_id && $school_year && $semester) {
        if ($role === 'teacher') {
            $teacherId = app_teacher_id($pdo, $userId);
            require_teacher_subject($pdo, (int) $subject_id, (int) $teacherId);
        }

        $totalWeight = subject_component_weight($pdo, (int) $subject_id);
        if (abs($totalWeight - 100.0) > 0.01) {
            $error = 'Grade components must total exactly 100% before final grades can be computed. Current total: ' . number_format($totalWeight, 2) . '%.';
        } else {
        $stmtStudents = $pdo->prepare('SELECT student_id FROM enrollments WHERE subject_id=? AND school_year=? AND semester=? AND status="Enrolled" AND archived_at IS NULL');
        $stmtStudents->execute([$subject_id, $school_year, $semester]);
        $studentIds = $stmtStudents->fetchAll(PDO::FETCH_COLUMN);

        $components = $pdo->prepare('SELECT id, percentage_weight FROM grade_components WHERE subject_id=? AND archived_at IS NULL');
        $components->execute([$subject_id]);
        $components = $components->fetchAll();

        $passStmt = $pdo->prepare('SELECT value FROM settings WHERE `key`="passing_grade"');
        $passStmt->execute();
        $passRow = $passStmt->fetch();
        $passingGrade = $passRow ? (float) $passRow['value'] : 75;

        foreach ($studentIds as $sid) {
            $weighted_grade = 0;
            $complete = true;
            foreach ($components as $comp) {
                $gradeStmt = $pdo->prepare('SELECT score, total_score FROM grades WHERE student_id=? AND subject_id=? AND component_id=?');
                $gradeStmt->execute([$sid, $subject_id, $comp['id']]);
                $grade = $gradeStmt->fetch();
                if ($grade && $grade['total_score'] > 0) {
                    $weighted_grade += ($grade['score'] / $grade['total_score']) * $comp['percentage_weight'];
                } else {
                    $complete = false;
                }
            }

            $gwa = $complete ? percentage_to_gwa((float) $weighted_grade) : null;
            $remarks = $complete ? (($weighted_grade >= $passingGrade) ? 'Passed' : 'Failed') : 'Incomplete';
            $check = $pdo->prepare('SELECT id FROM final_grades WHERE student_id=? AND subject_id=? AND school_year=? AND semester=?');
            $check->execute([$sid, $subject_id, $school_year, $semester]);
            $fid = $check->fetchColumn();

            if ($fid) {
                $update = $pdo->prepare('UPDATE final_grades SET gwa=?, weighted_grade=?, remarks=? WHERE id=?');
                $update->execute([$gwa, $weighted_grade, $remarks, $fid]);
            } else {
                $insert = $pdo->prepare('INSERT INTO final_grades (student_id, subject_id, gwa, weighted_grade, remarks, school_year, semester) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$sid, $subject_id, $gwa, $weighted_grade, $remarks, $school_year, $semester]);
            }
        }

        $message = 'Grades computed successfully for ' . count($studentIds) . ' enrolled student(s).';
        }
    } else {
        $error = 'Please select a subject, school year, and semester.';
    }
}

if ($role === 'admin') {
    $subjects = $pdo->query('SELECT id, code, name FROM subjects WHERE archived_at IS NULL ORDER BY code')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id = (SELECT id FROM teachers WHERE user_id=? AND archived_at IS NULL) AND archived_at IS NULL ORDER BY code');
    $stmt->execute([$userId]);
    $subjects = $stmt->fetchAll();
}

$subjectOptionHtml = '';
foreach ($subjects as $subject) {
    $selected = ((int) $subject_id === (int) $subject['id']) ? ' selected' : '';
    $subjectOptionHtml .= '<option value="' . (int) $subject['id'] . '"' . $selected . '>' . htmlspecialchars($subject['code'] . ' - ' . $subject['name']) . '</option>';
}

$semesterOptionHtml = '';
foreach ($semesterOptions as $semesterOption) {
    $selected = $semester === $semesterOption ? ' selected' : '';
    $semesterOptionHtml .= '<option value="' . htmlspecialchars($semesterOption, ENT_QUOTES) . '"' . $selected . '>' . htmlspecialchars($semesterOption) . '</option>';
}

$pageTitle = 'Compute Grades | SmartGrade';
$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('Compute Final Grades', 'Calculate final percentage, grade point, and remarks for enrolled students.'); ?>

<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">Grade Computation</div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_input() ?>
            <div class="row g-3">
                <?= sg_field('Subject', 'subject_id', 'bi-book', '<select id="subject_id" name="subject_id" class="form-select" required><option value="">-- Select --</option>' . $subjectOptionHtml . '</select>', 'col-lg-6') ?>
                <?= sg_field('School Year', 'school_year', 'bi-calendar-range', '<input type="text" id="school_year" name="school_year" class="form-control" required readonly value="' . htmlspecialchars($school_year, ENT_QUOTES) . '">', 'col-md-3') ?>
                <?= sg_field('Semester', 'semester', 'bi-calendar3', '<select id="semester" name="semester" class="form-select" required>' . $semesterOptionHtml . '</select>', 'col-md-3') ?>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-calculator"></i> Compute Final Grades</button>
            </div>
        </form>
    </div>
</div>

<?php require 'footer.php'; ?>
