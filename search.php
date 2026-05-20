<?php
session_start();
require_once 'config.php';
require_once 'components.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'teacher'], true)) {
    header('Location: login.php');
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $query . '%';
$results = [];
$teacherId = null;

if ($role === 'teacher') {
    $teacherId = app_teacher_id($pdo, app_current_user_id());
    if (!$teacherId) {
        http_response_code(403);
        exit('Teacher record not found.');
    }
}

$addResult = static function (string $type, string $title, string $meta, string $href, string $icon, string $status = '') use (&$results): void {
    $results[] = [
        'type' => $type,
        'title' => $title,
        'meta' => $meta,
        'href' => $href,
        'icon' => $icon,
        'status' => $status,
    ];
};

$fetchAll = static function (PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
};

if ($query !== '') {
    if ($role === 'admin') {
        foreach ($fetchAll($pdo, 'SELECT s.id, s.student_no, s.first_name, s.last_name, s.year_level, sec.name AS section_name, u.email
            FROM students s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN sections sec ON sec.id = s.section_id
            WHERE s.archived_at IS NULL
                AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR u.email LIKE ? OR s.year_level LIKE ? OR sec.name LIKE ?)
            ORDER BY s.last_name, s.first_name
            LIMIT 10', [$like, $like, $like, $like, $like, $like]) as $student) {
            $addResult(
                'Student',
                trim($student['first_name'] . ' ' . $student['last_name']),
                $student['student_no'] . ' | ' . $student['email'] . ' | ' . ($student['section_name'] ?? 'No section'),
                'manage_students.php?edit=' . (int) $student['id'],
                'bi-people'
            );
        }

        foreach ($fetchAll($pdo, 'SELECT t.id, t.employee_no, t.first_name, t.last_name, t.department, u.email
            FROM teachers t
            JOIN users u ON u.id = t.user_id
            WHERE t.archived_at IS NULL
                AND (t.employee_no LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR t.department LIKE ? OR u.email LIKE ?)
            ORDER BY t.last_name, t.first_name
            LIMIT 10', [$like, $like, $like, $like, $like]) as $teacher) {
            $addResult(
                'Teacher',
                trim($teacher['first_name'] . ' ' . $teacher['last_name']),
                $teacher['employee_no'] . ' | ' . $teacher['department'] . ' | ' . $teacher['email'],
                'manage_teachers.php?edit=' . (int) $teacher['id'],
                'bi-person-badge'
            );
        }
    } else {
        foreach ($fetchAll($pdo, 'SELECT DISTINCT s.id, s.student_no, s.first_name, s.last_name, s.year_level, sec.name AS section_name
            FROM students s
            JOIN enrollments e ON e.student_id = s.id
            JOIN subjects sub ON sub.id = e.subject_id
            LEFT JOIN sections sec ON sec.id = s.section_id
            WHERE s.archived_at IS NULL
                AND e.archived_at IS NULL
                AND e.status = "Enrolled"
                AND sub.archived_at IS NULL
                AND sub.teacher_id = ?
                AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.year_level LIKE ? OR sec.name LIKE ?)
            ORDER BY s.last_name, s.first_name
            LIMIT 10', [$teacherId, $like, $like, $like, $like, $like]) as $student) {
            $addResult(
                'Student',
                trim($student['first_name'] . ' ' . $student['last_name']),
                $student['student_no'] . ' | ' . $student['year_level'] . ' | ' . ($student['section_name'] ?? 'No section'),
                'grade_report.php?student_id=' . (int) $student['id'],
                'bi-people'
            );
        }
    }

    $subjectSql = 'SELECT sub.id, sub.code, sub.name, sub.units, t.first_name, t.last_name
        FROM subjects sub
        LEFT JOIN teachers t ON t.id = sub.teacher_id
        WHERE sub.archived_at IS NULL
            AND (sub.code LIKE ? OR sub.name LIKE ? OR CAST(sub.units AS CHAR) LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ?)';
    $subjectParams = [$like, $like, $like, $like, $like];
    if ($role === 'teacher') {
        $subjectSql .= ' AND sub.teacher_id = ?';
        $subjectParams[] = $teacherId;
    }
    $subjectSql .= ' ORDER BY sub.code LIMIT 10';
    foreach ($fetchAll($pdo, $subjectSql, $subjectParams) as $subject) {
        $href = $role === 'admin' ? 'manage_subjects.php?edit=' . (int) $subject['id'] : 'manage_components.php?subject_id=' . (int) $subject['id'];
        $teacherName = trim((string) ($subject['first_name'] ?? '') . ' ' . (string) ($subject['last_name'] ?? ''));
        $addResult(
            'Subject',
            $subject['code'] . ' - ' . $subject['name'],
            'Units: ' . $subject['units'] . ($teacherName !== '' ? ' | Teacher: ' . $teacherName : ''),
            $href,
            'bi-book'
        );
    }

    $sectionSql = $role === 'teacher'
        ? 'SELECT sec.id, sec.name, sec.year_level, sec.school_year, sec.semester, MIN(sub.id) AS subject_id
        FROM sections sec
        JOIN enrollments e ON e.section_id = sec.id
        JOIN subjects sub ON sub.id = e.subject_id'
        : 'SELECT sec.id, sec.name, sec.year_level, sec.school_year, sec.semester, NULL AS subject_id
        FROM sections sec';
    $sectionParams = [$like, $like, $like, $like];
    $sectionSql .= ' WHERE sec.archived_at IS NULL
        AND (sec.name LIKE ? OR sec.year_level LIKE ? OR sec.school_year LIKE ? OR sec.semester LIKE ?)';
    if ($role === 'teacher') {
        $sectionSql .= ' AND e.archived_at IS NULL AND e.status = "Enrolled" AND sub.archived_at IS NULL AND sub.teacher_id = ?
            GROUP BY sec.id, sec.name, sec.year_level, sec.school_year, sec.semester';
        $sectionParams[] = $teacherId;
    }
    $sectionSql .= ' ORDER BY sec.name LIMIT 10';
    foreach ($fetchAll($pdo, $sectionSql, $sectionParams) as $section) {
        $href = $role === 'admin' ? 'manage_sections.php?edit=' . (int) $section['id'] : 'view_all_grades.php?subject_id=' . (int) $section['subject_id'];
        $addResult(
            'Section',
            $section['name'],
            $section['year_level'] . ' | ' . $section['school_year'] . ' | ' . $section['semester'],
            $href,
            'bi-grid'
        );
    }

    $enrollmentSql = 'SELECT e.id, e.school_year, e.semester, e.status,
            s.first_name, s.last_name, s.student_no,
            sub.id AS subject_id, sub.code AS subject_code, sub.name AS subject_name,
            sec.name AS section_name
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        JOIN subjects sub ON sub.id = e.subject_id
        JOIN sections sec ON sec.id = e.section_id
        WHERE e.archived_at IS NULL
            AND s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND sec.archived_at IS NULL
            AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR sub.code LIKE ? OR sub.name LIKE ? OR sec.name LIKE ? OR e.school_year LIKE ? OR e.semester LIKE ? OR e.status LIKE ?)';
    $enrollmentParams = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
    if ($role === 'teacher') {
        $enrollmentSql .= ' AND e.status = "Enrolled" AND sub.teacher_id = ?';
        $enrollmentParams[] = $teacherId;
    }
    $enrollmentSql .= ' ORDER BY e.id DESC LIMIT 10';
    foreach ($fetchAll($pdo, $enrollmentSql, $enrollmentParams) as $enrollment) {
        $href = $role === 'admin' ? 'manage_enrollments.php?edit=' . (int) $enrollment['id'] : 'view_all_grades.php?subject_id=' . (int) $enrollment['subject_id'];
        $addResult(
            'Enrollment',
            trim($enrollment['first_name'] . ' ' . $enrollment['last_name']) . ' in ' . $enrollment['subject_code'],
            $enrollment['section_name'] . ' | ' . $enrollment['school_year'] . ' | ' . $enrollment['semester'],
            $href,
            'bi-card-checklist',
            $enrollment['status']
        );
    }

    $componentSql = 'SELECT gc.id, gc.name, gc.percentage_weight, sub.id AS subject_id, sub.code, sub.name AS subject_name
            FROM grade_components gc
            JOIN subjects sub ON sub.id = gc.subject_id
            WHERE gc.archived_at IS NULL
                AND sub.archived_at IS NULL
                AND (gc.name LIKE ? OR CAST(gc.percentage_weight AS CHAR) LIKE ? OR sub.code LIKE ? OR sub.name LIKE ?)';
    $componentParams = [$like, $like, $like, $like];
    if ($role === 'teacher') {
        $componentSql .= ' AND sub.teacher_id = ?';
        $componentParams[] = $teacherId;
    }
    $componentSql .= ' ORDER BY sub.code, gc.name LIMIT 10';
    foreach ($fetchAll($pdo, $componentSql, $componentParams) as $component) {
        $addResult(
            'Component',
            $component['name'],
            $component['code'] . ' - ' . $component['subject_name'] . ' | ' . $component['percentage_weight'] . '%',
            $role === 'admin' ? 'manage_subjects.php?edit=' . (int) $component['subject_id'] : 'manage_components.php?subject_id=' . (int) $component['subject_id'],
            'bi-sliders'
        );
    }

    if ($role === 'admin') {
        foreach ($fetchAll($pdo, 'SELECT `key`, `value`
            FROM settings
            WHERE `key` LIKE ? OR `value` LIKE ?
            ORDER BY `key`
            LIMIT 10', [$like, $like]) as $setting) {
            $addResult(
                'Setting',
                $setting['key'],
                $setting['value'],
                'school_settings.php',
                'bi-gear'
            );
        }
    }

    $finalGradeSql = 'SELECT fg.id, fg.gwa, fg.weighted_grade, fg.remarks, fg.school_year, fg.semester,
            s.student_no, s.first_name, s.last_name,
            sub.id AS subject_id, sub.code AS subject_code, sub.name AS subject_name
        FROM final_grades fg
        JOIN students s ON s.id = fg.student_id
        JOIN subjects sub ON sub.id = fg.subject_id
        JOIN enrollments e ON e.student_id = fg.student_id
            AND e.subject_id = fg.subject_id
            AND e.school_year = fg.school_year
            AND e.semester = fg.semester
        WHERE fg.archived_at IS NULL
            AND s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND e.archived_at IS NULL
            AND e.status = "Enrolled"
            AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR sub.code LIKE ? OR sub.name LIKE ? OR fg.school_year LIKE ? OR fg.semester LIKE ? OR fg.remarks LIKE ? OR CAST(fg.weighted_grade AS CHAR) LIKE ? OR CAST(fg.gwa AS CHAR) LIKE ?)';
    $finalGradeParams = [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like];
    if ($role === 'teacher') {
        $finalGradeSql .= ' AND sub.teacher_id = ?';
        $finalGradeParams[] = $teacherId;
    }
    $finalGradeSql .= ' ORDER BY fg.updated_at DESC LIMIT 10';
    foreach ($fetchAll($pdo, $finalGradeSql, $finalGradeParams) as $grade) {
        $href = $role === 'admin' ? 'admin_grades.php?subject_id=' . (int) $grade['subject_id'] : 'view_all_grades.php?subject_id=' . (int) $grade['subject_id'];
        $addResult(
            'Final Grade',
            trim($grade['first_name'] . ' ' . $grade['last_name']) . ' - ' . $grade['subject_code'],
            'Final: ' . $grade['weighted_grade'] . '% | GWA: ' . ($grade['gwa'] ?? '-') . ' | ' . $grade['school_year'] . ' | ' . $grade['semester'],
            $href,
            'bi-file-earmark-spreadsheet',
            $grade['remarks']
        );
    }

    $scoreSql = 'SELECT g.id, g.score, g.total_score,
            s.first_name, s.last_name, s.student_no,
            sub.id AS subject_id, sub.code AS subject_code, sub.name AS subject_name,
            gc.name AS component_name
        FROM grades g
        JOIN students s ON s.id = g.student_id
        JOIN subjects sub ON sub.id = g.subject_id
        JOIN grade_components gc ON gc.id = g.component_id
        WHERE g.archived_at IS NULL
            AND s.archived_at IS NULL
            AND sub.archived_at IS NULL
            AND gc.archived_at IS NULL
            AND EXISTS (
                SELECT 1
                FROM enrollments e
                WHERE e.student_id = g.student_id
                    AND e.subject_id = g.subject_id
                    AND e.status = "Enrolled"
                    AND e.archived_at IS NULL
            )
            AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR sub.code LIKE ? OR sub.name LIKE ? OR gc.name LIKE ? OR CAST(g.score AS CHAR) LIKE ? OR CAST(g.total_score AS CHAR) LIKE ?)';
    $scoreParams = [$like, $like, $like, $like, $like, $like, $like, $like];
    if ($role === 'teacher') {
        $scoreSql .= ' AND sub.teacher_id = ?';
        $scoreParams[] = $teacherId;
    }
    $scoreSql .= ' ORDER BY g.updated_at DESC LIMIT 10';
    foreach ($fetchAll($pdo, $scoreSql, $scoreParams) as $score) {
        $href = $role === 'admin' ? 'admin_grades.php?subject_id=' . (int) $score['subject_id'] : 'input_grades.php?subject_id=' . (int) $score['subject_id'];
        $addResult(
            'Score',
            trim($score['first_name'] . ' ' . $score['last_name']) . ' - ' . $score['component_name'],
            $score['subject_code'] . ' - ' . $score['subject_name'] . ' | ' . $score['score'] . '/' . $score['total_score'],
            $href,
            'bi-pencil-square'
        );
    }

    $reportSql = 'SELECT gr.id, gr.file_path, gr.created_at,
            s.student_no, s.first_name, s.last_name,
            u.name AS generated_by_name
        FROM grade_reports gr
        JOIN students s ON s.id = gr.student_id
        LEFT JOIN users u ON u.id = gr.generated_by
        WHERE gr.archived_at IS NULL
            AND s.archived_at IS NULL
            AND (s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR gr.file_path LIKE ? OR u.name LIKE ?)';
    $reportParams = [$like, $like, $like, $like, $like];
    if ($role === 'teacher') {
        $reportSql .= ' AND EXISTS (
            SELECT 1
            FROM enrollments e
            JOIN subjects sub ON sub.id = e.subject_id
            WHERE e.student_id = s.id
                AND e.status = "Enrolled"
                AND e.archived_at IS NULL
                AND sub.archived_at IS NULL
                AND sub.teacher_id = ?
        )
        AND gr.generated_by = ?';
        $reportParams[] = $teacherId;
        $reportParams[] = app_current_user_id();
    }
    $reportSql .= ' ORDER BY gr.created_at DESC LIMIT 10';
    foreach ($fetchAll($pdo, $reportSql, $reportParams) as $report) {
        $isPdf = app_report_is_pdf((string) $report['file_path']);
        $resolvedPath = app_resolve_report_path((string) $report['file_path']);
        $isAvailable = $isPdf && $resolvedPath !== null && is_file($resolvedPath);
        $statusLabel = $isAvailable ? 'Ready' : ($isPdf ? 'Missing File' : 'Legacy Non-PDF');

        $addResult(
            'Report',
            basename((string) $report['file_path']),
            trim($report['first_name'] . ' ' . $report['last_name']) . ' | ' . date('M d, Y h:i A', strtotime((string) $report['created_at'])),
            $isAvailable ? 'open_generated_report.php?id=' . (int) $report['id'] : 'generated_reports.php',
            'bi-file-earmark-pdf',
            $statusLabel
        );
    }
}

$pageTitle = 'Search | SmartGrade';
require 'header.php';
?>

<?php sg_page_header('Search', 'Find records across SmartGrade.'); ?>

<form method="get" action="search.php" class="mb-4">
    <div class="input-group input-group-lg">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Search students, teachers, subjects, sections, grades, reports" autofocus>
        <button type="submit" class="btn btn-primary">Search</button>
    </div>
</form>

<?php if ($query === ''): ?>
    <div class="alert alert-info">Enter a name, ID number, subject, section, term, grade, status, or report filename.</div>
<?php elseif (empty($results)): ?>
    <div class="alert alert-warning">No matching records found for <strong><?= htmlspecialchars($query) ?></strong>.</div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?></h5>
        <span class="text-muted small">Showing up to 10 matches per entity.</span>
    </div>

    <div class="list-group mb-4">
        <?php foreach ($results as $result): ?>
            <a href="<?= htmlspecialchars($result['href']) ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-start" <?= $result['type'] === 'Report' && $result['status'] === 'Ready' ? 'target="_blank" rel="noopener noreferrer" data-no-loader' : '' ?>>
                <span class="fs-4 text-primary"><i class="bi <?= htmlspecialchars($result['icon']) ?>"></i></span>
                <span class="flex-grow-1">
                    <span class="d-flex flex-wrap gap-2 align-items-center">
                        <strong><?= htmlspecialchars($result['title']) ?></strong>
                        <span class="badge text-bg-light"><?= htmlspecialchars($result['type']) ?></span>
                        <?php if ($result['status'] !== ''): ?>
                            <span class="<?= htmlspecialchars(sg_badge_class($result['status'])) ?>"><?= htmlspecialchars($result['status']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="d-block text-muted small"><?= htmlspecialchars($result['meta']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require 'footer.php'; ?>
