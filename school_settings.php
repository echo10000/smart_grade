<?php
// school_settings.php: manage school year, semester, and passing percentage

session_start();
require_once 'config.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN ("current_school_year", "current_semester", "passing_grade")');
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$settings['current_school_year'] = $settings['current_school_year'] ?? '';
$settings['current_semester'] = $settings['current_semester'] ?? '';
$settings['passing_grade'] = $settings['passing_grade'] ?? '75';

if ($settings['current_school_year'] === '') {
    $settings['current_school_year'] = app_default_school_year();
}
if ($settings['current_semester'] === '') {
    $settings['current_semester'] = app_semester_options()[0];
}

$semesterOptions = app_semester_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $school_year = trim(filter_input(INPUT_POST, 'school_year', FILTER_SANITIZE_STRING));
    $semester = trim(filter_input(INPUT_POST, 'semester', FILTER_SANITIZE_STRING));
    $passing = trim(filter_input(INPUT_POST, 'passing', FILTER_SANITIZE_NUMBER_INT));

    if ($school_year && $semester && $passing !== '') {
        $updateSetting = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $updateSetting->execute(['current_school_year', $school_year]);
        $updateSetting->execute(['current_semester', $semester]);
        $updateSetting->execute(['passing_grade', $passing]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Settings updated successfully.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please fill in all fields.'];
    }

    header('Location: school_settings.php');
    exit;
}

$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('School Settings', 'Manage the active academic term and grading threshold.'); ?>
<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrf_input() ?>
            <div class="row g-3 mb-3">
                <?= sg_field('Current School Year', 'school_year', 'bi-calendar-range', '<input type="text" class="form-control" id="school_year" name="school_year" value="' . htmlspecialchars($settings['current_school_year'], ENT_QUOTES) . '" readonly required>', 'col-12') ?>
                <?= sg_field('Current Semester', 'semester', 'bi-calendar3', '<select class="form-select" id="semester" name="semester" required><option value="">-- Select --</option>' . implode('', array_map(static function ($semesterOption) use ($settings) { return '<option value="' . htmlspecialchars($semesterOption, ENT_QUOTES) . '"' . ($settings['current_semester'] === $semesterOption ? ' selected' : '') . '>' . htmlspecialchars($semesterOption) . '</option>'; }, $semesterOptions)) . '</select>', 'col-12') ?>
                <?= sg_field('Passing Final Percentage', 'passing', 'bi-percent', '<input type="number" class="form-control" id="passing" name="passing" value="' . htmlspecialchars($settings['passing_grade'], ENT_QUOTES) . '" min="50" max="100" required>', 'col-12') ?>
            </div>
            <small class="text-muted d-block mb-3">Minimum final percentage to be considered "Passed".</small>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>

<?php require 'footer.php'; ?>
