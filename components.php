<?php

function sg_nav_items(): array
{
    return [
        'admin' => [
            ['href' => 'admin_dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['href' => 'manage_students.php', 'icon' => 'bi-people', 'label' => 'Students'],
            ['href' => 'manage_teachers.php', 'icon' => 'bi-person-badge', 'label' => 'Teachers'],
            ['href' => 'manage_subjects.php', 'icon' => 'bi-book', 'label' => 'Subjects'],
            ['href' => 'manage_sections.php', 'icon' => 'bi-grid', 'label' => 'Sections'],
            ['href' => 'manage_enrollments.php', 'icon' => 'bi-card-checklist', 'label' => 'Enrollments'],
            ['href' => 'admin_grades.php', 'icon' => 'bi-file-earmark-spreadsheet', 'label' => 'All Grades'],
            ['href' => 'compute_grades.php', 'icon' => 'bi-calculator', 'label' => 'Compute Grades'],
            ['href' => 'grade_report.php', 'icon' => 'bi-printer', 'label' => 'Grade Report'],
            ['href' => 'generate_report.php', 'icon' => 'bi-file-earmark-plus', 'label' => 'Generate Report'],
            ['href' => 'generated_reports.php', 'icon' => 'bi-file-earmark-pdf', 'label' => 'Generated Reports'],
            ['href' => 'export_grades.php', 'icon' => 'bi-download', 'label' => 'Export Grades'],
            ['href' => 'school_settings.php', 'icon' => 'bi-gear', 'label' => 'Settings'],
        ],
        'teacher' => [
            ['href' => 'teacher_dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['href' => 'manage_components.php', 'icon' => 'bi-sliders', 'label' => 'Components'],
            ['href' => 'input_grades.php', 'icon' => 'bi-pencil-square', 'label' => 'Input Grades'],
            ['href' => 'compute_grades.php', 'icon' => 'bi-calculator', 'label' => 'Compute Grades'],
            ['href' => 'view_all_grades.php', 'icon' => 'bi-card-list', 'label' => 'View Grades'],
            ['href' => 'grade_report.php', 'icon' => 'bi-printer', 'label' => 'Grade Report'],
            ['href' => 'generate_report.php', 'icon' => 'bi-file-earmark-plus', 'label' => 'Generate Report'],
            ['href' => 'generated_reports.php', 'icon' => 'bi-file-earmark-pdf', 'label' => 'Generated Reports'],
            ['href' => 'export_grades.php', 'icon' => 'bi-download', 'label' => 'Export Grades'],
        ],
        'student' => [
            ['href' => 'student_dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['href' => 'view_grades.php', 'icon' => 'bi-card-list', 'label' => 'Grades'],
            ['href' => 'grade_report.php', 'icon' => 'bi-printer', 'label' => 'Grade Report'],
        ],
    ];
}

function sg_home_href(string $userRole, array $navItems): string
{
    return $navItems[$userRole][0]['href'] ?? 'index.php';
}

function sg_badge_class(?string $value): string
{
    $normalized = strtolower(trim((string) $value));

    return match ($normalized) {
        'passed', 'active', 'success', 'enrolled' => 'sg-badge sg-badge-passed',
        'failed', 'danger', 'inactive', 'dropped' => 'sg-badge sg-badge-failed',
        'incomplete', 'warning' => 'sg-badge sg-badge-incomplete',
        'pending', '' => 'sg-badge sg-badge-pending',
        default => 'sg-badge sg-badge-neutral',
    };
}

function sg_render_shell_start(
    string $pageTitle,
    string $userName,
    string $userRole,
    string $currentPage,
    array $navItems,
    string $homeHref,
    string $bodyClass = ''
): void {
    $bodyClass = trim('sg-app ' . $bodyClass);
    ?>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="page-progress" aria-hidden="true"><div class="page-progress__bar"></div></div>
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top sg-navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= htmlspecialchars($homeHref) ?>">SmartGrade</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($userName) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <aside class="sidebar">
        <?php foreach (($navItems[$userRole] ?? []) as $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $currentPage === $item['href'] ? 'active' : '' ?>">
                <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </aside>

    <main class="content">
        <?php sg_render_flash(); ?>
    <?php
}

function sg_render_flash(): void
{
    if (!isset($_SESSION['flash'])) {
        return;
    }

    $type = htmlspecialchars($_SESSION['flash']['type'] ?? 'info');
    $message = htmlspecialchars($_SESSION['flash']['message'] ?? '');
    echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">';
    echo $message;
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    echo '</div>';
    unset($_SESSION['flash']);
}

function sg_page_header(string $title, string $subtitle = '', string $actionsHtml = ''): void
{
    ?>
    <section class="sg-page-header">
        <div>
            <h2 class="mb-1"><?= htmlspecialchars($title) ?></h2>
            <?php if ($subtitle !== ''): ?>
                <p class="sg-page-subtitle mb-0"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actionsHtml !== ''): ?>
            <div class="sg-page-actions"><?= $actionsHtml ?></div>
        <?php endif; ?>
    </section>
    <?php
}

function sg_field(string $label, string $id, string $icon, string $controlHtml, string $colClass = 'col-md-6'): string
{
    return '<div class="' . htmlspecialchars($colClass) . '">' .
        '<label for="' . htmlspecialchars($id) . '" class="form-label">' . htmlspecialchars($label) . '</label>' .
        '<div class="sg-input">' .
        '<span class="sg-input__icon"><i class="bi ' . htmlspecialchars($icon) . '"></i></span>' .
        $controlHtml .
        '</div>' .
        '</div>';
}
