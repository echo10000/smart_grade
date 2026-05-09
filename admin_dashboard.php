<?php
session_start();
require_once 'config.php';
// Only admin can access
$role = $_SESSION['user']['role'] ?? ($_SESSION['role'] ?? '');
if ($role !== 'admin') {
    header('Location: login.php');
    exit;
}

// Fetch summary counts
$totalStudents = $pdo->query('SELECT COUNT(*) FROM students WHERE archived_at IS NULL')->fetchColumn();
$totalTeachers = $pdo->query('SELECT COUNT(*) FROM teachers WHERE archived_at IS NULL')->fetchColumn();
$totalSubjects = $pdo->query('SELECT COUNT(*) FROM subjects WHERE archived_at IS NULL')->fetchColumn();
$totalSections = $pdo->query('SELECT COUNT(*) FROM sections WHERE archived_at IS NULL')->fetchColumn();

// Fetch recent activity from grade_reports (last 5)
$recentReports = $pdo->query('SELECT gr.*, u.name AS generated_by_name, s.first_name, s.last_name FROM grade_reports gr
    LEFT JOIN users u ON u.id = gr.generated_by
    LEFT JOIN students s ON s.id = gr.student_id
    WHERE gr.archived_at IS NULL AND s.archived_at IS NULL
    ORDER BY gr.created_at DESC LIMIT 5')->fetchAll();

// No custom scripts required for this page
$customScripts = '';
require 'header.php';
?>

<?php sg_page_header('Admin Dashboard', 'Overview of active records and recent grading activity.'); ?>
<div class="row row-cols-1 row-cols-md-4 g-4">
    <div class="col">
        <div class="card text-bg-primary dashboard-stat-card h-100">
            <div class="card-body">
                <div class="stat-icon">
                    <i class="bi bi-people"></i>
                </div>
                <div class="d-flex justify-content-between align-items-end gap-3">
                    <div>
                        <h5 class="card-title">Students</h5>
                        <p class="mb-0 text-white-50 small">Active student records</p>
                    </div>
                    <h1 class="display-4 mb-0"><?= $totalStudents ?></h1>
                </div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-bg-success dashboard-stat-card h-100">
            <div class="card-body">
                <div class="stat-icon">
                    <i class="bi bi-person-badge"></i>
                </div>
                <div class="d-flex justify-content-between align-items-end gap-3">
                    <div>
                        <h5 class="card-title">Teachers</h5>
                        <p class="mb-0 text-white-50 small">Faculty accounts available</p>
                    </div>
                    <h1 class="display-4 mb-0"><?= $totalTeachers ?></h1>
                </div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-bg-warning dashboard-stat-card h-100">
            <div class="card-body">
                <div class="stat-icon">
                    <i class="bi bi-book"></i>
                </div>
                <div class="d-flex justify-content-between align-items-end gap-3">
                    <div>
                        <h5 class="card-title">Subjects</h5>
                        <p class="mb-0 text-white-50 small">Configured learning areas</p>
                    </div>
                    <h1 class="display-4 mb-0"><?= $totalSubjects ?></h1>
                </div>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card text-bg-danger dashboard-stat-card h-100">
            <div class="card-body">
                <div class="stat-icon">
                    <i class="bi bi-grid"></i>
                </div>
                <div class="d-flex justify-content-between align-items-end gap-3">
                    <div>
                        <h5 class="card-title">Sections</h5>
                        <p class="mb-0 text-white-50 small">Class groups currently listed</p>
                    </div>
                    <h1 class="display-4 mb-0"><?= $totalSections ?></h1>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 card recent-activity-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Recent Activity</h5>
        <a href="generated_reports.php" class="btn btn-sm btn-outline-primary">View all reports</a>
    </div>
    <div class="card-body">
        <?php if ($recentReports): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentReports as $report): ?>
                    <?php
                    $reportHasPdf = app_report_is_pdf((string) $report['file_path']) && app_resolve_report_path((string) $report['file_path']) !== null;
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <div>
                                Report generated for <strong><?= htmlspecialchars($report['first_name'] . ' ' . $report['last_name']) ?></strong> by <?= htmlspecialchars($report['generated_by_name'] ?? 'Unknown User') ?>
                            </div>
                            <div class="text-muted small"><?= date('M d, Y H:i', strtotime($report['created_at'])) ?></div>
                        </div>
                        <div class="text-end">
                            <?php if ($reportHasPdf): ?>
                                <a href="open_generated_report.php?id=<?= (int) $report['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" data-no-loader>Open PDF</a>
                            <?php else: ?>
                                <span class="<?= htmlspecialchars(sg_badge_class('inactive')) ?>">No PDF</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No recent reports found.</p>
        <?php endif; ?>
    </div>
</div>

<?php require 'footer.php'; ?>
