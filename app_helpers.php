<?php

function app_current_user(): array
{
    return $_SESSION['user'] ?? [
        'id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'name' => $_SESSION['name'] ?? null,
    ];
}

function app_current_user_id(): int
{
    return (int) (app_current_user()['id'] ?? 0);
}

function app_current_role(): string
{
    return (string) (app_current_user()['role'] ?? '');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        exit('Invalid form token. Please go back and try again.');
    }
}

function redirect_with_flash(string $url, string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $url);
    exit;
}

function app_teacher_id(PDO $pdo, int $userId): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM teachers WHERE user_id=? AND archived_at IS NULL');
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();

    return $id ? (int) $id : null;
}

function teacher_owns_subject(PDO $pdo, int $subjectId, int $teacherId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE id=? AND teacher_id=? AND archived_at IS NULL');
    $stmt->execute([$subjectId, $teacherId]);

    return (int) $stmt->fetchColumn() > 0;
}

function require_teacher_subject(PDO $pdo, int $subjectId, int $teacherId): void
{
    if (!$subjectId || !teacher_owns_subject($pdo, $subjectId, $teacherId)) {
        http_response_code(403);
        exit('You are not allowed to access this subject.');
    }
}

function teacher_can_access_student(PDO $pdo, int $studentId, int $teacherId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM enrollments e
        JOIN subjects sub ON sub.id = e.subject_id
        JOIN students s ON s.id = e.student_id
        WHERE e.student_id=? AND sub.teacher_id=? AND e.status="Enrolled" AND e.archived_at IS NULL AND sub.archived_at IS NULL AND s.archived_at IS NULL');
    $stmt->execute([$studentId, $teacherId]);

    return (int) $stmt->fetchColumn() > 0;
}

function require_teacher_student(PDO $pdo, int $studentId, int $teacherId): void
{
    if (!$studentId || !teacher_can_access_student($pdo, $studentId, $teacherId)) {
        http_response_code(403);
        exit('You are not allowed to access this student.');
    }
}

function subject_component_weight(PDO $pdo, int $subjectId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(percentage_weight), 0) FROM grade_components WHERE subject_id=? AND archived_at IS NULL');
    $stmt->execute([$subjectId]);

    return (float) $stmt->fetchColumn();
}

function percentage_to_gwa(float $percentage): float
{
    if ($percentage >= 98) return 1.00;
    if ($percentage >= 95) return 1.25;
    if ($percentage >= 92) return 1.50;
    if ($percentage >= 89) return 1.75;
    if ($percentage >= 86) return 2.00;
    if ($percentage >= 83) return 2.25;
    if ($percentage >= 80) return 2.50;
    if ($percentage >= 77) return 2.75;
    if ($percentage >= 75) return 3.00;
    return 5.00;
}

function audit_grade_change(PDO $pdo, ?int $gradeId, int $studentId, int $subjectId, int $componentId, ?array $old, float $newScore, float $newTotal, int $changedBy, string $reason = 'Grade entry update'): void
{
    $stmt = $pdo->prepare('INSERT INTO grade_audit_logs
        (grade_id, student_id, subject_id, component_id, changed_by, old_score, old_total_score, new_score, new_total_score, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $gradeId,
        $studentId,
        $subjectId,
        $componentId,
        $changedBy,
        $old['score'] ?? null,
        $old['total_score'] ?? null,
        $newScore,
        $newTotal,
        $reason,
    ]);
}

function app_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function app_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $stmt->execute([$table, $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function app_duplicate_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function app_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function app_default_school_year(): string
{
    $year = (int) date('Y');
    $month = (int) date('n');
    $startYear = $month >= 6 ? $year : $year - 1;

    return $startYear . '-' . ($startYear + 1);
}

function app_current_school_year(PDO $pdo): string
{
    $setting = trim(app_setting($pdo, 'current_school_year', ''));

    return $setting !== '' ? $setting : app_default_school_year();
}

function app_year_level_options(): array
{
    return [
        '1st Year',
        '2nd Year',
        '3rd Year',
        '4th Year',
    ];
}

function app_semester_options(): array
{
    return [
        '1st Semester',
        '2nd Semester',
        'Summer',
    ];
}

function app_current_semester(PDO $pdo): string
{
    $setting = trim(app_setting($pdo, 'current_semester', ''));
    if ($setting !== '') {
        return $setting;
    }

    return app_semester_options()[0];
}

function app_department_options(): array
{
    return [
        'Computer Science',
        'Information Technology',
        'Mathematics',
        'Science',
        'English',
        'Education',
        'Business Administration',
        'Engineering',
    ];
}

function app_next_identifier(PDO $pdo, string $table, string $column, string $prefix): string
{
    $year = date('Y');
    $prefixWithYear = strtoupper($prefix) . '-' . $year;
    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ?");
    $stmt->execute([$prefixWithYear . '%']);

    $maxSequence = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingValue) {
        if (preg_match('/^' . preg_quote($prefixWithYear, '/') . '(\d{3,})$/', (string) $existingValue, $matches)) {
            $maxSequence = max($maxSequence, (int) $matches[1]);
        }
    }

    return sprintf('%s%03d', $prefixWithYear, $maxSequence + 1);
}

function app_reports_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'reports';
}

function app_safe_filename(string $value, string $fallback = 'report'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
        $value = $transliterated;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : $fallback;
}

function app_report_file_path(int $studentId, string $studentName, string $extension = 'pdf'): string
{
    $reportsDir = app_reports_dir();
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0777, true);
    }

    $timestamp = date('Ymd_His');
    $safeStudentName = app_safe_filename($studentName, 'student');
    $safeExtension = ltrim(strtolower($extension), '.');

    return $reportsDir . DIRECTORY_SEPARATOR . sprintf(
        'student_%d_%s_%s.%s',
        $studentId,
        $safeStudentName,
        $timestamp,
        $safeExtension
    );
}

function app_pdf_encode_text(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
    $text = preg_replace('/[^\P{C}\n]/u', '', $text) ?? $text;
    $encoded = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

    if ($encoded === false) {
        $encoded = preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? '';
    }

    return $encoded;
}

function app_pdf_escape_text(string $text): string
{
    return strtr(app_pdf_encode_text($text), [
        '\\' => '\\\\',
        '(' => '\\(',
        ')' => '\\)',
    ]);
}

function app_pdf_wrap_text(string $text, int $maxChars): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return [''];
    }

    $maxChars = max(12, $maxChars);
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }

        while (strlen($word) > $maxChars) {
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            $lines[] = substr($word, 0, $maxChars);
            $word = substr($word, $maxChars);
        }

        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (strlen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
        }
        $current = $word;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines === [] ? [''] : $lines;
}

function app_write_simple_pdf(string $path, array $items, string $title = 'Document'): void
{
    $pageWidth = 595.28;
    $pageHeight = 841.89;
    $margin = 48.0;
    $usableWidth = $pageWidth - ($margin * 2);
    $y = $pageHeight - $margin;
    $pages = [[]];
    $pageIndex = 0;

    $startPage = static function () use (&$pages, &$pageIndex, &$y, $pageHeight, $margin): void {
        $pageIndex++;
        $pages[$pageIndex] = [];
        $y = $pageHeight - $margin;
    };

    foreach ($items as $item) {
        $kind = $item['kind'] ?? 'text';

        if ($kind === 'rule') {
            $neededHeight = 12.0 + (float) ($item['gap_after'] ?? 8.0);
            if ($y - $neededHeight < $margin) {
                $startPage();
            }

            $pages[$pageIndex][] = sprintf(
                '0.85 w %.2F %.2F m %.2F %.2F l S',
                $margin,
                $y,
                $pageWidth - $margin,
                $y
            );
            $y -= $neededHeight;
            continue;
        }

        $text = (string) ($item['text'] ?? '');
        $fontKey = ($item['font'] ?? '') === 'bold' ? 'F2' : 'F1';
        $fontSize = max(8.0, (float) ($item['size'] ?? 11.0));
        $leading = max($fontSize + 3.0, (float) ($item['leading'] ?? ($fontSize + 4.0)));
        $gapAfter = (float) ($item['gap_after'] ?? 0.0);
        $maxChars = (int) floor($usableWidth / max(4.4, $fontSize * ($fontKey === 'F2' ? 0.57 : 0.53)));
        $wrappedLines = app_pdf_wrap_text($text, $maxChars);

        foreach ($wrappedLines as $line) {
            if ($y - $leading < $margin) {
                $startPage();
            }

            $pages[$pageIndex][] = sprintf(
                'BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                $fontKey,
                $fontSize,
                $margin,
                $y,
                app_pdf_escape_text($line)
            );
            $y -= $leading;
        }

        if ($gapAfter > 0) {
            if ($y - $gapAfter < $margin) {
                $startPage();
            } else {
                $y -= $gapAfter;
            }
        }
    }

    $pageCount = count($pages);
    $pageObjectIds = [];
    $contentObjectIds = [];
    $nextObjectId = 6;

    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjectIds[$i] = $nextObjectId++;
        $contentObjectIds[$i] = $nextObjectId++;
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', array_map(
            static fn (int $id): string => $id . ' 0 R',
            $pageObjectIds
        )) . '] >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        5 => '<< /Title (' . app_pdf_escape_text($title) . ') /Producer (SmartGrade Lightweight PDF) >>',
    ];

    foreach ($pages as $index => $operations) {
        $stream = implode("\n", $operations) . "\n";
        $contentId = $contentObjectIds[$index];
        $pageId = $pageObjectIds[$index];

        $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $objects[$pageId] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
            $pageWidth,
            $pageHeight,
            $contentId
        );
    }

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $objectId => $body) {
        $offsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefPosition = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($objectId = 1; $objectId <= count($objects); $objectId++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId] ?? 0);
    }

    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R /Info 5 0 R >>' . "\n";
    $pdf .= "startxref\n" . $xrefPosition . "\n%%EOF";

    file_put_contents($path, $pdf);
}

function app_write_grade_report_pdf(string $path, string $studentName, array $grades, string $generatedAtLabel): void
{
    $items = [
        ['text' => 'Grade Report', 'size' => 18, 'font' => 'bold', 'gap_after' => 6],
        ['text' => 'Student: ' . $studentName, 'size' => 12, 'font' => 'bold', 'gap_after' => 2],
        ['text' => 'Generated: ' . $generatedAtLabel, 'size' => 10, 'gap_after' => 10],
        ['kind' => 'rule', 'gap_after' => 12],
    ];

    if ($grades === []) {
        $items[] = [
            'text' => 'No final grades were found for the selected student under your current access scope.',
            'size' => 11,
            'gap_after' => 4,
        ];
    } else {
        foreach ($grades as $grade) {
            $items[] = [
                'text' => sprintf('Subject: %s - %s', $grade['code'], $grade['name']),
                'size' => 11,
                'font' => 'bold',
                'gap_after' => 2,
            ];
            $items[] = [
                'text' => sprintf(
                    'GWA: %s    Final Percentage: %s%%',
                    $grade['gwa'] ?? '-',
                    $grade['weighted_grade']
                ),
                'size' => 10,
                'gap_after' => 1,
            ];
            $items[] = [
                'text' => sprintf(
                    'Remarks: %s    School Year: %s    Semester: %s',
                    $grade['remarks'],
                    $grade['school_year'],
                    $grade['semester']
                ),
                'size' => 10,
                'gap_after' => 8,
            ];
            $items[] = ['kind' => 'rule', 'gap_after' => 10];
        }
    }

    app_write_simple_pdf($path, $items, 'SmartGrade Grade Report');
}

function app_resolve_report_path(string $path): ?string
{
    $trimmedPath = trim($path);
    if ($trimmedPath === '') {
        return null;
    }

    $reportsDir = realpath(app_reports_dir());
    $reportPath = realpath($trimmedPath);
    if ($reportsDir === false || $reportPath === false) {
        return null;
    }

    $reportsDir = rtrim($reportsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($reportPath, $reportsDir, strlen($reportsDir)) !== 0) {
        return null;
    }

    return $reportPath;
}

function app_report_is_pdf(string $path): bool
{
    return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

function ensure_app_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (['users', 'students', 'teachers', 'subjects', 'sections', 'enrollments', 'grade_components', 'grades', 'final_grades', 'grade_reports'] as $table) {
        if (!app_column_exists($pdo, $table, 'archived_at')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN archived_at DATETIME NULL");
        }
        if (!app_column_exists($pdo, $table, 'archived_by')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN archived_by INT NULL");
        }
    }

    if (!app_column_exists($pdo, 'users', 'status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'");
    }

    try {
        $pdo->exec('ALTER TABLE final_grades MODIFY gwa DECIMAL(6,2) NULL');
    } catch (Throwable $e) {
        // Some environments may not allow metadata changes here; existing
        // installations will still function with 0.00 for incomplete records.
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS grade_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_id INT NULL,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        component_id INT NOT NULL,
        changed_by INT NOT NULL,
        old_score DECIMAL(6,2) NULL,
        old_total_score DECIMAL(6,2) NULL,
        new_score DECIMAL(6,2) NOT NULL,
        new_total_score DECIMAL(6,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (component_id) REFERENCES grade_components(id) ON DELETE CASCADE,
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
    )');

    if (!app_index_exists($pdo, 'grades', 'uniq_grade_entry') &&
        !app_index_exists($pdo, 'grades', 'uq_grades_student_subject_component') &&
        app_duplicate_count($pdo, 'SELECT COUNT(*) FROM (SELECT student_id, subject_id, component_id FROM grades GROUP BY student_id, subject_id, component_id HAVING COUNT(*) > 1) d') === 0) {
        $pdo->exec('ALTER TABLE grades ADD UNIQUE KEY uq_grades_student_subject_component (student_id, subject_id, component_id)');
    }

    if (!app_index_exists($pdo, 'final_grades', 'uniq_final_grade_entry') &&
        !app_index_exists($pdo, 'final_grades', 'uq_final_grades_student_subject_term') &&
        app_duplicate_count($pdo, 'SELECT COUNT(*) FROM (SELECT student_id, subject_id, school_year, semester FROM final_grades GROUP BY student_id, subject_id, school_year, semester HAVING COUNT(*) > 1) d') === 0) {
        $pdo->exec('ALTER TABLE final_grades ADD UNIQUE KEY uq_final_grades_student_subject_term (student_id, subject_id, school_year, semester)');
    }

    if (!app_index_exists($pdo, 'enrollments', 'uniq_enrollment_entry') &&
        !app_index_exists($pdo, 'enrollments', 'uq_enrollments_student_subject_term') &&
        app_duplicate_count($pdo, 'SELECT COUNT(*) FROM (SELECT student_id, subject_id, school_year, semester FROM enrollments WHERE archived_at IS NULL GROUP BY student_id, subject_id, school_year, semester HAVING COUNT(*) > 1) d') === 0) {
        $pdo->exec('ALTER TABLE enrollments ADD UNIQUE KEY uq_enrollments_student_subject_term (student_id, subject_id, school_year, semester)');
    }
}
