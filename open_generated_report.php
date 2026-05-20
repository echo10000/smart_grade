<?php
session_start();
require_once 'config.php';

$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'teacher'], true)) {
    header('Location: login.php');
    exit;
}

$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$reportId) {
    http_response_code(400);
    exit('Invalid report ID.');
}

$stmt = $pdo->prepare('SELECT gr.id, gr.student_id, gr.generated_by, gr.file_path
    FROM grade_reports gr
    JOIN students s ON s.id = gr.student_id
    WHERE gr.id = ?
        AND gr.archived_at IS NULL
        AND s.archived_at IS NULL');
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

if ($role === 'teacher') {
    $teacherId = app_teacher_id($pdo, app_current_user_id());
    if (
        !$teacherId
        || (int) $report['generated_by'] !== app_current_user_id()
        || !teacher_can_access_student($pdo, (int) $report['student_id'], $teacherId)
    ) {
        http_response_code(403);
        exit('You are not allowed to access this report.');
    }
}

$resolvedPath = app_resolve_report_path((string) $report['file_path']);
if (!app_report_is_pdf((string) $report['file_path']) || $resolvedPath === null || !is_file($resolvedPath)) {
    http_response_code(404);
    exit('PDF file not available.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($resolvedPath) . '"');
header('Content-Length: ' . filesize($resolvedPath));
header('X-Content-Type-Options: nosniff');
readfile($resolvedPath);
exit;
