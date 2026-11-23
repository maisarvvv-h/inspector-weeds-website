<?php
session_start();

require_once "db_connect.php";

function safe_output($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function safe_image_path($path): string
{
    return safe_output(str_replace("\\", "/", (string) $path));
}

function short_image_id(string $imageFile): string
{
    $baseName = pathinfo($imageFile, PATHINFO_FILENAME);
    $parts = preg_split('/[_\-]/', $baseName);

    if (!empty($parts[0])) {
        return strtoupper(trim($parts[0]));
    }

    return strtoupper($baseName);
}

function severity_class($severity): string
{
    $severity = strtolower(trim((string) $severity));

    if ($severity === "moderate" || $severity === "medium") {
        return "moderate";
    }

    if ($severity === "high") {
        return "high";
    }

    return "low";
}

function default_recommendation($severity): string
{
    $severity = strtolower(trim((string) $severity));

    if ($severity === "high") {
        return "Prioritise this zone for immediate field inspection and weed treatment.";
    }

    if ($severity === "moderate" || $severity === "medium") {
        return "Schedule targeted weed management during the next inspection cycle.";
    }

    return "Continue routine plantation monitoring. No immediate treatment is required.";
}

$image_id = trim((string) ($_GET["image_id"] ?? ""));
$page_error = "";
$analysis = null;
$history_rows = [];
$latest_upload_batch_lookup = [];

if (
    isset($_SESSION["latest_analysis_batch_ids"]) &&
    is_array($_SESSION["latest_analysis_batch_ids"])
) {
    foreach ($_SESSION["latest_analysis_batch_ids"] as $batch_image_id) {
        $batch_image_id = trim((string) $batch_image_id);
        if ($batch_image_id !== "") {
            $latest_upload_batch_lookup[$batch_image_id] = true;
        }
    }
}

if ($image_id !== "") {
    $analysis_statement = mysqli_prepare(
        $conn,
        "
        SELECT
            i.image_id,
            i.image_file,
            i.original_image_path,
            i.analysis_status,
            i.analysis_date,
            d.zone_id,
            d.processed_image_path,
            d.mask_image_path,
            d.weed_coverage,
            d.total_pixels,
            d.weed_pixels,
            d.average_confidence,
            d.severity_level,
            d.manual_review_status,
            d.recommendation,
            d.model_used
        FROM images AS i
        INNER JOIN detection_results AS d
            ON i.image_id = d.image_id
        WHERE i.image_id = ?
        LIMIT 1
        "
    );

    if (!$analysis_statement) {
        $page_error =
            "Unable to prepare the selected analysis query: " .
            mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($analysis_statement, "s", $image_id);

        if (!mysqli_stmt_execute($analysis_statement)) {
            $page_error =
                "Unable to load the selected analysis: " .
                mysqli_stmt_error($analysis_statement);
        } else {
            $analysis_result = mysqli_stmt_get_result($analysis_statement);
            $analysis = mysqli_fetch_assoc($analysis_result);
        }

        mysqli_stmt_close($analysis_statement);
    }
}

$history_query = mysqli_query(
    $conn,
    "
    SELECT
        i.image_id,
        i.image_file,
        i.analysis_status,
        i.analysis_date,
        d.zone_id,
        d.weed_coverage,
        d.average_confidence,
        d.severity_level
    FROM images AS i
    INNER JOIN detection_results AS d
        ON i.image_id = d.image_id
    WHERE i.analysis_status = 'Completed'
    ORDER BY i.analysis_date DESC
    "
);

if (!$history_query) {
    $page_error = "Unable to load analysis results: " . mysqli_error($conn);
} else {
    while ($history_row = mysqli_fetch_assoc($history_query)) {
        $history_rows[] = $history_row;
    }

    usort(
        $history_rows,
        function ($left, $right) use ($latest_upload_batch_lookup) {
            $left_is_new = isset($latest_upload_batch_lookup[$left["image_id"]]);
            $right_is_new = isset($latest_upload_batch_lookup[$right["image_id"]]);

            if ($left_is_new !== $right_is_new) {
                return $left_is_new ? -1 : 1;
            }

            return strcmp(
                (string) $right["analysis_date"],
                (string) $left["analysis_date"]
            );
        }
    );
}

$new_batch_count = 0;
foreach ($history_rows as $history_row) {
    if (isset($latest_upload_batch_lookup[$history_row["image_id"]])) {
        $new_batch_count++;
    }
}

if ($analysis) {
    $analysis = array_merge(
        [
            "recommendation" => "",
            "severity_level" => "Low",
            "zone_id" => "Unknown",
            "weed_coverage" => 0,
            "average_confidence" => 0,
            "weed_pixels" => 0,
            "total_pixels" => 0,
            "analysis_status" => "",
            "manual_review_status" => "No",
            "processed_image_path" => "",
            "mask_image_path" => "",
            "original_image_path" => "",
            "image_file" => "",
            "image_id" => "",
            "model_used" => "",
            "analysis_date" => "",
        ],
        $analysis
    );

    $analysis_recommendation = trim((string) $analysis["recommendation"]);
    if ($analysis_recommendation === "") {
        $analysis_recommendation = default_recommendation($analysis["severity_level"]);
    }

    $display_image_id = short_image_id(
        (string) ($analysis["image_file"] ?: $analysis["image_id"])
    );
    $is_latest_batch_analysis = isset(
        $latest_upload_batch_lookup[$analysis["image_id"]]
    );
}

$style_path = __DIR__ . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "style.css";
$style_version = file_exists($style_path) ? filemtime($style_path) : time();
$results_style_path =
    __DIR__ . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "detection_results.css";
$results_style_version = file_exists($results_style_path)
    ? filemtime($results_style_path)
    : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analysis Results | Inspector Weeds</title>
    <link rel="stylesheet" href="./css/style.css?v=<?= $style_version ?>">
    <link rel="stylesheet" href="./css/detection_results.css?v=<?= $results_style_version ?>-saas-v16">
</head>
<body class="dashboard-body">
<?php require __DIR__ . "/includes/sidebar.php"; ?>

<main class="app-main results-main">
    <header class="results-page-header">
        <div class="results-heading">
            <?php if ($image_id !== "" && $analysis): ?>
                <h1>Analysis Result — <?= safe_output($display_image_id) ?></h1>
                <p>
                    <?= safe_output($analysis["zone_id"] ?: "Unknown zone") ?>
                    <?php if (!empty($analysis["analysis_date"])): ?>
                        · Analysed
                        <?= safe_output(date("d M Y, g:i A", strtotime($analysis["analysis_date"]))) ?>
                    <?php endif; ?>
                </p>
            <?php elseif ($image_id !== ""): ?>
                <h1>Analysis Result</h1>
                <p>Selected image analysis</p>
            <?php else: ?>
                <h1>Analysis Results</h1>
                <p>Review completed image analyses.</p>
            <?php endif; ?>
        </div>

    </header>

    <?php if ($page_error !== ""): ?>
        <div class="page-alert error" role="alert">
            <?= safe_output($page_error) ?>
        </div>
    <?php endif; ?>

    <?php if ($image_id !== ""): ?>
        <?php if ($analysis): ?>
            <section class="analysis-detail" aria-labelledby="analysis-detail-title">
                <div class="results-context-nav">
                    <a class="results-back-link" href="detection_results.php">
                        <span aria-hidden="true">←</span> All Results
                    </a>
                    <a class="results-next-link" href="dashboard.php">
                        View Dashboard <span aria-hidden="true">→</span>
                    </a>
                </div>

                <h2 class="visually-hidden" id="analysis-detail-title">
                    Analysis details for <?= safe_output($display_image_id) ?>
                </h2>

                <?php if ($is_latest_batch_analysis): ?>
                    <span class="results-new-badge detail-new-badge">NEW</span>
                <?php endif; ?>

                <div class="results-metrics">
                    <article class="result-metric">
                        <span>Weed Coverage</span>
                        <strong><?= number_format((float) $analysis["weed_coverage"], 2) ?>%</strong>
                    </article>
                    <article class="result-metric">
                        <span>Severity</span>
                        <strong>
                            <span class="severity-pill <?= safe_output(severity_class($analysis["severity_level"])) ?>">
                                <?= safe_output($analysis["severity_level"]) ?>
                            </span>
                        </strong>
                    </article>
                    <article class="result-metric">
                        <span>Confidence</span>
                        <strong><?= number_format((float) $analysis["average_confidence"], 2) ?>%</strong>
                    </article>
                    <article class="result-metric">
                        <span>Zone</span>
                        <strong><?= safe_output($analysis["zone_id"] ?: "Unknown") ?></strong>
                    </article>
                </div>

                <section class="segmentation-section" aria-labelledby="segmentation-title">
                    <div class="results-section-heading">
                        <h2 id="segmentation-title">Image Analysis</h2>
                    </div>
                    <div class="segmentation-images">
                        <?php
                        $analysis_images = [
                            "Original Image" => $analysis["original_image_path"],
                            "Weed Overlay" => $analysis["processed_image_path"],
                            "Weed Mask" => $analysis["mask_image_path"],
                        ];
                        foreach ($analysis_images as $image_label => $image_path):
                        ?>
                            <figure class="segmentation-image">
                                <figcaption><?= safe_output($image_label) ?></figcaption>
                                <?php if ($image_path !== ""): ?>
                                    <img
                                        src="<?= safe_image_path($image_path) ?>"
                                        alt="<?= safe_output($image_label . " for " . $display_image_id) ?>"
                                        loading="lazy"
                                    >
                                <?php else: ?>
                                    <div class="image-unavailable">Image unavailable</div>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </section>

                <details class="results-technical-details">
                    <summary>Technical details</summary>
                    <dl>
                        <div>
                            <dt>Weed Pixels</dt>
                            <dd><?= number_format((int) $analysis["weed_pixels"]) ?></dd>
                        </div>
                        <div>
                            <dt>Total Pixels</dt>
                            <dd><?= number_format((int) $analysis["total_pixels"]) ?></dd>
                        </div>
                        <div>
                            <dt>Model Used</dt>
                            <dd><?= safe_output($analysis["model_used"] ?: "Not available") ?></dd>
                        </div>
                        <div>
                            <dt>Analysis Status</dt>
                            <dd><?= safe_output($analysis["analysis_status"] ?: "Unknown") ?></dd>
                        </div>
                        <div>
                            <dt>Manual Review</dt>
                            <dd><?= safe_output($analysis["manual_review_status"] ?: "No") ?></dd>
                        </div>
                    </dl>
                    <p class="results-recommendation"><?= safe_output($analysis_recommendation) ?></p>
                </details>
            </section>
        <?php elseif ($page_error === ""): ?>
            <section class="results-empty-state">
                <h2>Analysis not found</h2>
                <p>This image does not have a completed analysis.</p>
                <a class="results-back-link" href="detection_results.php">
                    <span aria-hidden="true">←</span> All Results
                </a>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="results-table-section" aria-labelledby="results-table-title">
            <div class="results-table-heading">
                <h2 id="results-table-title">All analysed images</h2>
                <span><?= count($history_rows) ?> <?= count($history_rows) === 1 ? "result" : "results" ?></span>
            </div>

            <?php if ($new_batch_count > 0): ?>
                <p class="results-batch-notice">
                    <?= $new_batch_count ?>
                    new <?= $new_batch_count === 1 ? "analysis" : "analyses" ?> completed.
                    <span>Your latest uploads are shown first.</span>
                </p>
            <?php endif; ?>

            <?php if ($history_rows): ?>
                <div class="results-table-wrap">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th scope="col">Image</th>
                                <th scope="col">Zone</th>
                                <th scope="col">Weed Coverage</th>
                                <th scope="col">Confidence</th>
                                <th scope="col">Severity</th>
                                <th scope="col">Analysis Date</th>
                                <th scope="col"><span class="visually-hidden">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history_rows as $history): ?>
                                <?php
                                $history_image_id = short_image_id(
                                    (string) ($history["image_file"] ?: $history["image_id"])
                                );
                                $history_is_new = isset(
                                    $latest_upload_batch_lookup[$history["image_id"]]
                                );
                                ?>
                                <tr>
                                    <td>
                                        <span
                                            class="result-image-label"
                                            title="<?= safe_output($history["image_file"]) ?>"
                                        >
                                            <?= safe_output($history_image_id) ?>
                                        </span>
                                        <?php if ($history_is_new): ?>
                                            <span class="results-new-badge">NEW</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= safe_output($history["zone_id"] ?: "Unknown") ?></td>
                                    <td><?= number_format((float) $history["weed_coverage"], 2) ?>%</td>
                                    <td><?= number_format((float) $history["average_confidence"], 2) ?>%</td>
                                    <td>
                                        <span class="severity-pill <?= safe_output(severity_class($history["severity_level"])) ?>">
                                            <?= safe_output($history["severity_level"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= safe_output(date("d M Y, g:i A", strtotime($history["analysis_date"]))) ?>
                                    </td>
                                    <td class="results-action-cell">
                                        <a
                                            class="results-view-link"
                                            href="detection_results.php?image_id=<?= urlencode($history["image_id"]) ?>"
                                        >
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($page_error === ""): ?>
                <div class="results-empty-state">
                    <h3>No completed analyses yet</h3>
                    <p>Upload images to view their analysis results here.</p>
                    <a class="results-next-link" href="upload.php">Upload Images <span aria-hidden="true">→</span></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <footer class="app-footer">© 2026 Inspector Weeds. All rights reserved.</footer>
</main>
</body>
</html>
