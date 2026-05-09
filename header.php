<?php
// header.php: shared authenticated shell and navigation for SmartGrade
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'components.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$sessionUserId = (int) ($_SESSION['user']['id'] ?? 0);
$sessionStatusStmt = $pdo->prepare('SELECT status FROM users WHERE id=?');
$sessionStatusStmt->execute([$sessionUserId]);
$sessionStatus = $sessionStatusStmt->fetchColumn();
if ($sessionStatus !== 'active') {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

$userRole = $_SESSION['user']['role'];
$userName = $_SESSION['user']['name'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? 'SmartGrade';
$bodyClass = $bodyClass ?? '';

// Backward compatibility: older pages place stylesheet links in $customScripts.
// Move those links into the head, leaving only executable scripts for footer.php.
$customHead = $customHead ?? '';
if (isset($customScripts) && preg_match_all('/<link\b[^>]*>/i', $customScripts, $matches)) {
    $customHead .= "\n" . implode("\n", $matches[0]);
    $customScripts = preg_replace('/<link\b[^>]*>\s*/i', '', $customScripts);
}

$navItems = sg_nav_items();
$homeHref = sg_home_href($userRole, $navItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="smartgrade-styles.css" rel="stylesheet">
    <?= $customHead ?>
</head>
<?php sg_render_shell_start($pageTitle, $userName, $userRole, $currentPage, $navItems, $homeHref, $bodyClass); ?>
