<?php
$sidebarCurrentPage = basename($_SERVER["PHP_SELF"] ?? "");
$sidebarNavigationItems = [
    ["file" => "upload.php", "label" => "Upload Images", "icon" => "upload"],
    ["file" => "detection_results.php", "label" => "Analysis Results", "icon" => "layers"],
    ["file" => "dashboard.php", "label" => "Dashboard", "icon" => "chart"],
    ["file" => "treatment_planning.php", "label" => "Treatment Planning", "icon" => "checklist"],
];
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <img src="images/logo.png" class="sidebar-logo" alt="Inspector Weeds">
        <div class="brand-text">
            <h2>Inspector<br>Weeds</h2>
            <p>Plantation Intelligence</p>
        </div>
    </div>

    <nav class="app-navigation" aria-label="Main navigation">
        <?php foreach ($sidebarNavigationItems as $sidebarItem): ?>
            <?php $sidebarItemIsActive = $sidebarCurrentPage === $sidebarItem["file"]; ?>
            <a
                href="<?= htmlspecialchars($sidebarItem["file"], ENT_QUOTES, "UTF-8") ?>"
                <?= $sidebarItemIsActive ? 'class="active" aria-current="page"' : "" ?>
            >
                <span class="nav-icon" aria-hidden="true">
                    <?php if ($sidebarItem["icon"] === "upload"): ?>
                        <svg viewBox="0 0 24 24" role="img"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/></svg>
                    <?php elseif ($sidebarItem["icon"] === "layers"): ?>
                        <svg viewBox="0 0 24 24" role="img"><path d="m12 3-9 5 9 5 9-5-9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg>
                    <?php elseif ($sidebarItem["icon"] === "chart"): ?>
                        <svg viewBox="0 0 24 24" role="img"><path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" role="img"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                    <?php endif ?>
                </span>
                <?= htmlspecialchars($sidebarItem["label"], ENT_QUOTES, "UTF-8") ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
