<?php
session_start();
require_once 'config.php';
// Teachers only
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'teacher') {
    header('Location: login.php');
    exit;
}

// Get teacher id
$teacherUserId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);
$teacherId = app_teacher_id($pdo, (int) $teacherUserId);
if (!$teacherId) {
    die('Teacher record not found');
}

// Fetch subjects taught
$subjects = $pdo->prepare('SELECT id, code, name FROM subjects WHERE teacher_id=? AND archived_at IS NULL ORDER BY code');
$subjects->execute([$teacherId]);
$subjects = $subjects->fetchAll();

// Get selected subject and component
// Selected subject id via GET or POST
$subject_id   = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
// We'll not use component_id here for multi-component entry

// When posting grades (multiple components)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    require_teacher_subject($pdo, (int) $subject_id, $teacherId);
    $scores     = $_POST['scores'] ?? [];
    $totals     = $_POST['totals'] ?? [];

    try {
        $pdo->beginTransaction();
        foreach ($scores as $studentId => $compScores) {
            $studentId = (int) $studentId;
            $enrolled = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=? AND subject_id=? AND status="Enrolled" AND archived_at IS NULL');
            $enrolled->execute([$studentId, $subject_id]);
            if ((int) $enrolled->fetchColumn() === 0) {
                throw new Exception('A submitted student is not enrolled in this subject.');
            }

            foreach ($compScores as $compId => $score) {
                $compId = (int) $compId;
                $component = $pdo->prepare('SELECT COUNT(*) FROM grade_components WHERE id=? AND subject_id=? AND archived_at IS NULL');
                $component->execute([$compId, $subject_id]);
                if ((int) $component->fetchColumn() === 0) {
                    throw new Exception('A submitted grade component is invalid for this subject.');
                }

                $total = isset($totals[$studentId][$compId]) ? (float) $totals[$studentId][$compId] : 0;
                $scoreVal = (float) $score;
                if ($total <= 0 || $scoreVal < 0 || $scoreVal > $total) {
                    throw new Exception('Scores must be non-negative and cannot exceed the total score.');
                }

                $stmt = $pdo->prepare('SELECT id, score, total_score FROM grades WHERE student_id=? AND subject_id=? AND component_id=?');
                $stmt->execute([$studentId, $subject_id, $compId]);
                $old = $stmt->fetch();

                if ($old) {
                    $stmt2 = $pdo->prepare('UPDATE grades SET score=?, total_score=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt2->execute([$scoreVal, $total, $old['id']]);
                    $gradeId = (int) $old['id'];
                } else {
                    $stmt2 = $pdo->prepare('INSERT INTO grades (student_id, subject_id, component_id, score, total_score) VALUES (?, ?, ?, ?, ?)');
                    $stmt2->execute([$studentId, $subject_id, $compId, $scoreVal, $total]);
                    $gradeId = (int) $pdo->lastInsertId();
                }

                audit_grade_change($pdo, $gradeId, $studentId, (int) $subject_id, $compId, $old ?: null, $scoreVal, $total, app_current_user_id());
            }
        }
        $pdo->commit();
        $message = 'Grades saved successfully';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// If subject selected, fetch components and students
if ($subject_id) {
    require_teacher_subject($pdo, (int) $subject_id, $teacherId);
    $componentsStmt = $pdo->prepare('SELECT id, name, percentage_weight FROM grade_components WHERE subject_id=? AND archived_at IS NULL');
    $componentsStmt->execute([$subject_id]);
    $components = $componentsStmt->fetchAll();
    // Fetch students enrolled in the subject
    $studentsStmt = $pdo->prepare('SELECT st.id, st.first_name, st.last_name FROM students st
        JOIN enrollments e ON e.student_id = st.id
        WHERE e.subject_id=? AND e.status="Enrolled" AND e.archived_at IS NULL AND st.archived_at IS NULL');
    $studentsStmt->execute([$subject_id]);
    $students = $studentsStmt->fetchAll();
} else {
    $components = [];
    $students   = [];
}

// Fetch passing grade threshold from settings
$passStmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="passing_grade"');
$passStmt->execute();
$passingGradeRow = $passStmt->fetch();
$passingGrade = $passingGradeRow ? (float)$passingGradeRow['value'] : 75;

// Load existing grades for this subject across all components
$existingGrades = [];
if ($subject_id && !empty($components)) {
    $ids = array_column($components, 'id');
    $in  = str_repeat('?,', count($ids) - 1) . '?';
    $sql = 'SELECT student_id, component_id, score, total_score FROM grades WHERE subject_id=? AND component_id IN (' . $in . ')';
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$subject_id], $ids);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $existingGrades[$r['student_id']][$r['component_id']] = ['score' => $r['score'], 'total_score' => $r['total_score']];
    }
}
?>
<?php
// Build an array of component weights for JS preview
$componentWeights = [];
foreach ($components as $c) {
    $componentWeights[$c['id']] = (float)$c['percentage_weight'];
}

// Prepare scripts for grade preview
$customScripts = '<script>
var componentWeights = ' . json_encode($componentWeights) . ';
function computeGWA() {
  var passing = parseFloat(document.getElementById("passingGradeHidden").value || 75);
  $(".student-row").each(function(){
    var sum = 0;
    var hasMissing = false;
    for (const compId in componentWeights) {
      var scoreInput = $(this).find(".score-" + compId).val();
      var totalInput = $(this).find(".total-" + compId).val();
      var weight = componentWeights[compId];
      var ratio = 0;
      if (totalInput && totalInput > 0 && scoreInput) { ratio = parseFloat(scoreInput) / parseFloat(totalInput); }
      else { hasMissing = true; }
      sum += ratio * weight;
    }
    var percentage = sum;
    $(this).find(".gwa-cell").text(percentage.toFixed(2) + "%");
    var remark = hasMissing ? "Pending" : (percentage >= passing ? "Passed" : "Failed");
    var classes = hasMissing ? "sg-badge sg-badge-pending" : (percentage >= passing ? "sg-badge sg-badge-passed" : "sg-badge sg-badge-failed");
    $(this).find(".remark-cell").text(remark);
    $(this).find(".remark-cell").removeClass("sg-badge sg-badge-passed sg-badge-failed sg-badge-incomplete sg-badge-pending sg-badge-neutral").addClass(classes);
  });
}
$(document).on("input", ".score-input, .total-input", computeGWA);
$(document).ready(function(){ computeGWA(); });
</script>';

// Include header
require 'header.php';
?>

<?php sg_page_header('Input Grades', 'Enter component scores for each enrolled student in your class.'); ?>
<!-- Hidden field to pass the current passing grade threshold to JavaScript -->
<input type="hidden" id="passingGradeHidden" value="<?= htmlspecialchars($passingGrade) ?>">
<?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Subject selection form -->
<form method="get" action="" class="mb-3">
    <div class="row g-3 align-items-end">
        <?= sg_field('Select Subject', 'subject_id', 'bi-book', '<select id="subject_id" name="subject_id" class="form-select" onchange="this.form.submit()"><option value="">-- Select --</option>' . implode('', array_map(static function ($sub) use ($subject_id) { return '<option value="' . (int) $sub['id'] . '"' . (($subject_id == $sub['id']) ? ' selected' : '') . '>' . htmlspecialchars($sub['code'] . ' - ' . $sub['name']) . '</option>'; }, $subjects)) . '</select>') ?>
    </div>
</form>

<?php if ($subject_id && !empty($components)): ?>
<form method="post" action="">
    <?= csrf_input() ?>
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Student</th>
                    <?php foreach ($components as $comp): ?>
                        <th colspan="2" class="text-center"><?= htmlspecialchars($comp['name']) ?> (<?= $comp['percentage_weight'] ?>%)</th>
                    <?php endforeach; ?>
                    <th>Current Percentage</th>
                    <th>Remarks</th>
                </tr>
                <tr>
                    <th></th>
                    <?php foreach ($components as $comp): ?>
                        <th>Score</th><th>Total</th>
                    <?php endforeach; ?>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $st): ?>
                    <tr class="student-row">
                        <td><?= htmlspecialchars($st['first_name'] . ' ' . $st['last_name']) ?></td>
                        <?php foreach ($components as $comp): ?>
                            <?php
                            $existing = $existingGrades[$st['id']][$comp['id']] ?? ['score' => '', 'total_score' => ''];
                            ?>
                            <td>
                                <input type="number" step="0.01" name="scores[<?= $st['id'] ?>][<?= $comp['id'] ?>]" value="<?= htmlspecialchars($existing['score']) ?>" class="form-control score-input score-<?= $comp['id'] ?>" required>
                            </td>
                            <td>
                                <input type="number" step="0.01" name="totals[<?= $st['id'] ?>][<?= $comp['id'] ?>]" value="<?= htmlspecialchars($existing['total_score']) ?>" class="form-control total-input total-<?= $comp['id'] ?>" required>
                            </td>
                        <?php endforeach; ?>
                        <td class="gwa-cell"></td>
                        <td><span class="remark-cell sg-badge sg-badge-pending">Pending</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button type="submit" class="btn btn-primary">Save Grades</button>
</form>
<?php endif; ?>

<?php require 'footer.php'; ?>
