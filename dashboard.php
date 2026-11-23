<?php
require_once "db_connect.php";
require_once "treatment_rules.php";


/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function safe_output($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $table);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (int)($row["total"] ?? 0) > 0;
}


/* =========================================================
   DASHBOARD SUMMARY
========================================================= */

$summary = [
    "total_images" => 0,
    "average_coverage" => 0,
    "high_risk_images" => 0
];

$summary_query = mysqli_query(
    $conn,
    "
    SELECT
        COUNT(*) AS total_images,

        COALESCE(
            AVG(d.weed_coverage),
            0
        ) AS average_coverage,

        COALESCE(
            SUM(
                CASE
                    WHEN d.weed_coverage >= " . TREATMENT_LOW_THRESHOLD . "
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS high_risk_images

    FROM images i

    INNER JOIN detection_results d
        ON i.image_id = d.image_id

    WHERE i.analysis_status = 'Completed'
    "
);

if ($summary_query) {
    $summary_row = mysqli_fetch_assoc(
        $summary_query
    );

    if ($summary_row) {
        $summary = $summary_row;
    }
}


/* =========================================================
   SEVERITY DISTRIBUTION
   New business rule:
   - Below 5% = Low
   - 5% and above = High
========================================================= */

$severity_counts = [
    "Low" => 0,
    "High" => 0
];

$severity_query = mysqli_query(
    $conn,
    "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN image_summary.max_weed_coverage < " . TREATMENT_LOW_THRESHOLD . "
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_count,

        COALESCE(
            SUM(
                CASE
                    WHEN image_summary.max_weed_coverage >= " . TREATMENT_LOW_THRESHOLD . "
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS high_count

    FROM (
        SELECT
            i.image_id,
            MAX(d.weed_coverage) AS max_weed_coverage

        FROM images AS i

        INNER JOIN detection_results AS d
            ON i.image_id = d.image_id

        WHERE i.analysis_status = 'Completed'

        GROUP BY i.image_id
    ) AS image_summary
    "
);

if ($severity_query) {

    $severity_row =
        mysqli_fetch_assoc(
            $severity_query
        );

    if ($severity_row) {

        $severity_counts["Low"] =
            (int)(
                $severity_row[
                    "low_count"
                ] ?? 0
            );

        $severity_counts["High"] =
            (int)(
                $severity_row[
                    "high_count"
                ] ?? 0
            );
    }
}


/* =========================================================
   ZONE ANALYTICS
========================================================= */

$zone_results = [];
$latest_completed_zone = null;

$zone_names = [
    "Zone A",
    "Zone B",
    "Zone C",
    "Zone D",
    "Zone E"
];

$zone_query = mysqli_query(
    $conn,
    "
    SELECT
        i.zone_id,

        COUNT(*) AS image_count,

        COALESCE(
            AVG(d.weed_coverage),
            0
        ) AS average_coverage,

        MAX(i.analysis_date) AS last_inspection,

        COALESCE(
            SUM(
                CASE
                    WHEN d.weed_coverage >= " . TREATMENT_LOW_THRESHOLD . "
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS high_images

    FROM images i

    INNER JOIN detection_results d
        ON i.image_id = d.image_id

    WHERE i.analysis_status = 'Completed'
      AND i.zone_id IS NOT NULL
      AND i.zone_id <> ''

    GROUP BY i.zone_id

    ORDER BY i.zone_id ASC
    "
);

if ($zone_query) {
    while (
        $zone_row =
        mysqli_fetch_assoc($zone_query)
    ) {
        $treatment_trees = (int)$zone_row["high_images"];
        $analysed_trees = (int)$zone_row["image_count"];

        $zone_row["treatment_rate"] = $analysed_trees > 0
            ? ($treatment_trees / $analysed_trees) * 100
            : 0;
        $zone_row["estimated_opex"] = 0.0;
        $zone_row["recommendation"] = $treatment_trees > 0
            ? "Prioritise {$treatment_trees} identified trees for treatment."
            : "No immediate treatment required based on current analyses.";

        $zone_results[$zone_row["zone_id"]] = $zone_row;
    }
}

$maximum_treatment_trees = 0;

foreach ($zone_results as $zone_row) {
    if (in_array((string)$zone_row["zone_id"], $zone_names, true)) {
        $maximum_treatment_trees = max(
            $maximum_treatment_trees,
            (int)$zone_row["high_images"]
        );
    }
}

foreach ($zone_results as &$zone_row) {
    $treatment_trees = (int)$zone_row["high_images"];
    $workload_ratio = $maximum_treatment_trees > 0
        ? $treatment_trees / $maximum_treatment_trees
        : 0;

    $zone_row["workload_ratio"] = $workload_ratio;
    $zone_row["workload_class"] = $treatment_trees === 0
        ? "none"
        : ($workload_ratio <= 1 / 3
            ? "low"
            : ($workload_ratio <= 2 / 3 ? "medium" : "high"));
}
unset($zone_row);


/* =========================================================
   TREATMENT PLANNING OVERVIEW — LIVE OPEX
========================================================= */

$treatment_summary = [
    "estimated_opex" => 0
];

if (table_exists($conn, "treatment_plans") && !empty($zone_results)) {
    foreach ($zone_results as $zone_id => $zone) {
        $treatment_trees = (int)($zone["high_images"] ?? 0);

        if ($treatment_trees <= 0) {
            continue;
        }

        $selected_herbicide = TREATMENT_DEFAULT_HERBICIDE;
        $selected_rate = TREATMENT_DEFAULT_APPLICATION_RATE;
        $selection_query = mysqli_prepare(
            $conn,
            "
            SELECT
                recommended_herbicide,
                application_rate
            FROM treatment_plans
            WHERE zone_id = ?
            LIMIT 1
            "
        );

        if ($selection_query) {

            mysqli_stmt_bind_param(
                $selection_query,
                "s",
                $zone_id
            );

            mysqli_stmt_execute(
                $selection_query
            );

            $selection_result =
                mysqli_stmt_get_result(
                    $selection_query
                );

            $selection_row =
                $selection_result
                    ? mysqli_fetch_assoc(
                        $selection_result
                    )
                    : null;

            if ($selection_row) {

                if (
                    !empty(
                        $selection_row[
                            "recommended_herbicide"
                        ]
                    )
                ) {
                    $selected_herbicide =
                        $selection_row[
                            "recommended_herbicide"
                        ];
                }

                if (
                    !empty(
                        $selection_row[
                            "application_rate"
                        ]
                    )
                ) {
                    $selected_rate =
                        $selection_row[
                            "application_rate"
                        ];
                }
            }

            mysqli_stmt_close(
                $selection_query
            );
        }


        $live_plan = generateTreeBasedTreatmentPlan(
            $treatment_trees,
            $selected_herbicide,
            $selected_rate
        );
        $zone_cost = (float)$live_plan["total_cost"];

        $zone_results[$zone_id]["estimated_opex"] = $zone_cost;
        $treatment_summary["estimated_opex"] += $zone_cost;
    }
}


/* =========================================================
   PREPARE DISPLAY VALUES
========================================================= */

$total_images =
    (int)
    $summary["total_images"];

$average_coverage =
    (float)
    $summary["average_coverage"];

$high_risk_images =
    (int)
    $summary["high_risk_images"];

$treatment_trees = $high_risk_images;
$estimated_opex =
    (float)$treatment_summary["estimated_opex"];

$zones_requiring_treatment = 0;

foreach ($zone_results as $zone) {
    if ((int)$zone["high_images"] > 0) {
        $zones_requiring_treatment++;
    }
}


$total_severity_images =
    $severity_counts["Low"] +
    $severity_counts["High"];

$low_percentage =
    $total_severity_images > 0
        ? (
            $severity_counts["Low"] /
            $total_severity_images
        ) * 100
        : 0;

$high_percentage =
    $total_severity_images > 0
        ? (
            $severity_counts["High"] /
            $total_severity_images
        ) * 100
        : 0;


/* =========================================================
   DEFAULT ZONE DETAILS
========================================================= */

$default_zone_name =
    "Zone A";

$latest_zone_timestamp = -1;

foreach ($zone_names as $zone_name) {
    if (!isset($zone_results[$zone_name])) {
        continue;
    }

    $inspection_date =
        $zone_results[$zone_name]["last_inspection"] ?? null;

    $inspection_timestamp =
        $inspection_date
            ? strtotime((string)$inspection_date)
            : 0;

    if ($inspection_timestamp > $latest_zone_timestamp) {
        $latest_zone_timestamp = $inspection_timestamp;
        $default_zone_name = $zone_name;
    }
}

/* Select the zone from the newest completed upload/analysis. */
$latest_zone_query = mysqli_query(
    $conn,
    "
    SELECT zone_id
    FROM images
    WHERE analysis_status = 'Completed'
      AND zone_id IS NOT NULL
      AND zone_id <> ''
    ORDER BY analysis_date DESC, id DESC
    LIMIT 1
    "
);

if ($latest_zone_query) {
    $latest_zone_row = mysqli_fetch_assoc($latest_zone_query);

    if (!empty($latest_zone_row["zone_id"])) {
        $latest_completed_zone =
            (string)$latest_zone_row["zone_id"];
    }
}

if (
    $latest_completed_zone !== null
    && isset($zone_results[$latest_completed_zone])
) {
    $default_zone_name = $latest_completed_zone;
}

$default_zone =
    $zone_results[
        $default_zone_name
    ] ?? [
        "image_count" => 0,
        "high_images" => 0,
        "average_coverage" => 0,
        "treatment_rate" => 0,
        "estimated_opex" => 0,
        "workload_ratio" => 0,
        "workload_class" => "none",
        "last_inspection" => null,
        "recommendation" => "No immediate treatment required based on current analyses."
    ];


/* =========================================================
   PREPARE JS ZONE DATA
========================================================= */

$zone_js_data = [];

foreach ($zone_names as $zone_name) {
    $zone =
        $zone_results[$zone_name] ?? [
            "image_count" => 0,
            "high_images" => 0,
            "average_coverage" => 0,
            "treatment_rate" => 0,
            "estimated_opex" => 0,
            "workload_ratio" => 0,
            "workload_class" => "none",
            "last_inspection" => null,
            "recommendation" => "No immediate treatment required based on current analyses."
        ];

    $zone_js_data[$zone_name] = [
        "zone" =>
            $zone_name,

        "image_count" =>
            (int)
            $zone["image_count"],

        "high_images" =>
            (int)
            $zone["high_images"],

        "treatment_rate" => round((float)$zone["treatment_rate"], 1),

        "average_coverage" =>
            round(
                (float)
                $zone["average_coverage"],
                2
            ),

        "estimated_opex" => round((float)$zone["estimated_opex"], 2),
        "workload_ratio" => round((float)$zone["workload_ratio"], 4),
        "workload_class" => (string)$zone["workload_class"],

        "last_inspection" =>
            $zone["last_inspection"]
                ? date(
                    "d M Y",
                    strtotime(
                        $zone["last_inspection"]
                    )
                )
                : "No inspection",

        "recommendation" =>
            $zone["recommendation"]
    ];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Dashboard | Inspector Weeds
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <?php
    $dashboard_style_path = __DIR__ . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "style.css";
    $dashboard_style_version = file_exists($dashboard_style_path)
        ? filemtime($dashboard_style_path)
        : time();
    ?>
    <link
        rel="stylesheet"
        href="./css/style.css?v=<?= $dashboard_style_version ?>"
    >

    <link
        rel="stylesheet"
        href="./css/dashboard.css?v=20261120-fixed-zone-labels-v28"
    >

</head>


<body class="dashboard-body home-dashboard-page">

<?php require __DIR__ . "/includes/sidebar.php"; ?>


<main class="app-main">

    <header class="dashboard-page-header">

    <div class="header-left">

        <div class="header-title-row">

            <h1>
                Weed Monitoring Dashboard
            </h1>

        </div>

        <p>
            Track completed inspections, treatment demand,
            weed coverage and estimated operating costs.
        </p>

    </div>

    <div class="header-right">


</div>

    </div>

</header>

    <!-- =====================================================
         EXECUTIVE KPI OVERVIEW
    ====================================================== -->

    <section class="home-executive-kpis">

        <article class="home-kpi-card">
            <div class="home-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 16 3-3 3 3 2-2 3 3M8 9h.01"/></svg>
            </div>
            <div>
                <span>Images Analysed</span>
                <strong><?= number_format($total_images) ?></strong>
            </div>
        </article>

        <article class="home-kpi-card">
            <div class="home-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 21V9m0 4c-4 0-7-2-7-6 4 0 7 2 7 6Zm0 3c4 0 7-2 7-6-4 0-7 2-7 6Z"/></svg>
            </div>
            <div>
                <span>Treatment Required</span>
                <strong><?= number_format($treatment_trees) ?></strong>
            </div>
        </article>

        <article class="home-kpi-card">
            <div class="home-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15"/></svg>
            </div>
            <div>
                <span>Zones Requiring Treatment</span>
                <strong><?= number_format($zones_requiring_treatment) ?></strong>
            </div>
        </article>

        <article class="home-kpi-card">
            <div class="home-kpi-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.7-1-3-1-1.7 0-3 .8-3 2s1.2 1.8 3 2.2 3 1 3 2.3-1.3 2-3 2c-1.3 0-2.5-.4-3.3-1.2M12 5.5v13"/></svg>
            </div>
            <div>
                <span>Estimated Treatment OPEX</span>
                <strong>RM <?= number_format($estimated_opex, 2) ?></strong>
            </div>
        </article>

    </section>


    <section class="home-management-grid">

        <div class="home-map-column">

    <!-- =====================================================
         PLANTATION MAP
    ====================================================== -->

    <section class="plantation-map-shell">

         <div
        class="plantation-map-canvas"
        style="
            background-image:
                linear-gradient(
                    rgba(5, 40, 25, 0.20),
                    rgba(5, 40, 25, 0.20)
                ),
                url('images/plantation-map-bg.png') !important;
            background-size: cover !important;
            background-position: center !important;
            background-repeat: no-repeat !important;
        "
    >

            <div class="plantation-map-title">

                <h2>
                    Plantation Zone Map
                </h2>

                <span>
                    Colour = treatment workload · Counts and rates use analysed trees only
                    · Select a zone to view details
                </span>

            </div>

            <div class="plantation-svg-map" aria-label="Interactive plantation zone map">

                <svg
                    class="zone-map-svg"
                    viewBox="0 0 900 520"
                    preserveAspectRatio="xMidYMid meet"
                    role="img"
                    aria-labelledby="plantationMapTitle"
                >
                    <title id="plantationMapTitle">Treatment workload and rate by plantation zone</title>

                    <?php
                    $svg_zone_config = [
                        "Zone A" => [
                            "class" => "zone-a",
                            "points" => "55,68 302,68 342,125 320,278 250,322 92,305 35,235",
                            "label_x" => 175,
                            "label_y" => 177
                        ],
                        "Zone B" => [
                            "class" => "zone-b",
                            "points" => "302,68 515,72 558,130 534,270 420,304 320,278 342,125",
                            "label_x" => 425,
                            "label_y" => 172
                        ],
                        "Zone C" => [
                            "class" => "zone-c",
                            "points" => "515,72 816,82 860,142 836,284 700,316 534,270 558,130",
                            "label_x" => 700,
                            "label_y" => 183
                        ],
                        "Zone D" => [
                            "class" => "zone-d",
                            "points" => "35,235 320,278 420,304 558,350 500,476 198,494 70,434",
                            "label_x" => 278,
                            "label_y" => 367
                        ],
                        "Zone E" => [
                            "class" => "zone-e",
                            "points" => "558,350 700,316 836,284 875,364 812,480 626,490 500,476",
                            "label_x" => 690,
                            "label_y" => 387
                        ]
                    ];
                    ?>

                    <?php foreach ($svg_zone_config as $zone_name => $config): ?>
                        <?php
                        $zone = $zone_results[$zone_name] ?? [
                            "image_count" => 0,
                            "high_images" => 0,
                            "treatment_rate" => 0,
                            "average_coverage" => 0,
                            "estimated_opex" => 0,
                            "workload_class" => "none"
                        ];
                        $zone_treatment_trees = (int)$zone["high_images"];
                        $zone_analysed_trees = (int)$zone["image_count"];
                        $zone_treatment_rate = (float)$zone["treatment_rate"];
                        $zone_fill_style = match ($zone["workload_class"]) {
                            "low" => "fill: rgba(221, 190, 51, 0.68) !important;",
                            "medium" => "fill: rgba(224, 123, 38, 0.68) !important;",
                            "high" => "fill: rgba(177, 48, 54, 0.72) !important;",
                            default => "fill: rgba(31, 142, 74, 0.56) !important;"
                        };
                        ?>

                        <g
                            class="svg-zone-group<?= $zone_name === $default_zone_name ? ' selected' : '' ?>"
                            data-zone="<?= safe_output($zone_name) ?>"
                            tabindex="0"
                            role="button"
                            aria-label="<?= $zone_analysed_trees === 0
                                ? safe_output($zone_name) . ": No analyses yet"
                                : safe_output($zone_name) . ": " . number_format($zone_treatment_trees) . " of " . number_format($zone_analysed_trees) . " analysed trees require treatment; " . number_format($zone_treatment_rate, 1) . " percent treatment rate" ?>"
                        >
                            <title>
                                <?= safe_output($zone_name) ?>:
                                <?= number_format($zone_analysed_trees) ?> analysed trees;
                                <?= number_format($zone_treatment_trees) ?> require treatment;
                                <?= number_format($zone_treatment_rate, 1) ?>% treatment rate;
                                <?= number_format((float)$zone["average_coverage"], 1) ?>% average weed coverage;
                                RM <?= number_format((float)$zone["estimated_opex"], 2) ?> estimated treatment cost
                            </title>
                            <polygon
                                class="svg-zone workload-<?= safe_output($zone["workload_class"]) ?>"
                                points="<?= safe_output($config["points"]) ?>"
                                style="<?= safe_output($zone_fill_style) ?>"
                            ></polygon>

                            <g class="svg-zone-label">
                                <text
                                    x="<?= (int)$config["label_x"] ?>"
                                    y="<?= (int)$config["label_y"] ?>"
                                >
                                    <tspan
                                        class="zone-name"
                                        x="<?= (int)$config["label_x"] ?>"
                                    ><?= safe_output($zone_name) ?></tspan>
                                    <tspan
                                        class="coverage-text<?= $zone_analysed_trees === 0 ? ' no-analysis-count' : '' ?>"
                                        x="<?= (int)$config["label_x"] ?>"
                                        dy="1.2em"
                                    >
                                    <?php if ($zone_analysed_trees === 0): ?>
                                        0 analysed
                                    <?php else: ?>
                                        <?= number_format($zone_treatment_trees) ?> / <?= number_format($zone_analysed_trees) ?> trees
                                    <?php endif ?>
                                    </tspan>
                                    <tspan
                                        class="rate-text"
                                        x="<?= (int)$config["label_x"] ?>"
                                        dy="1.4em"
                                    >
                                    <?php if ($zone_analysed_trees === 0): ?>
                                        No analyses yet
                                    <?php else: ?>
                                        <?= number_format($zone_treatment_rate, 1) ?>% treatment
                                    <?php endif; ?>
                                    </tspan>
                                </text>
                            </g>
                        </g>
                    <?php endforeach; ?>

                </svg>

            </div>

        </div>


        <aside class="zone-detail-panel">

            <div
                class="zone-detail-header"
                id="zoneDetailHeader"
            >

                <h3 id="zoneDetailName">
                    <?= safe_output(
                        $default_zone_name
                    ) ?> Details
                </h3>

            </div>


            <div class="zone-detail-body">

                <div class="zone-detail-row">
                    <span>Trees Analysed</span>
                    <strong id="zoneImageCount">
                        <?= number_format(
                            (int)$default_zone["image_count"]
                        ) ?>
                    </strong>
                </div>

                <div class="zone-detail-row">
                    <span>Require Treatment</span>
                    <strong id="zoneTreatmentTrees">
                        <?= number_format((int)$default_zone["high_images"]) ?>
                    </strong>
                </div>

                <div class="zone-detail-row">
                    <span>Treatment Rate</span>
                    <strong id="zoneTreatmentRate">
                        <?= number_format((float)$default_zone["treatment_rate"], 1) ?>%
                    </strong>
                </div>

                <div class="zone-detail-row">
                    <span>Average Weed Coverage</span>
                    <strong id="zoneAverageCoverage">
                        <?= number_format((float)$default_zone["average_coverage"], 1) ?>%
                    </strong>
                </div>

                <div class="zone-detail-row">
                    <span>Estimated Treatment Cost</span>
                    <strong id="zoneTreatmentCost">
                        RM <?= number_format((float)$default_zone["estimated_opex"], 2) ?>
                    </strong>
                </div>

                <div class="zone-detail-row">
                    <span>Last Analysis</span>
                    <strong id="zoneInspectionDate">
                        <?= $default_zone["last_inspection"]
                            ? date("d M Y", strtotime($default_zone["last_inspection"]))
                            : "No analysis" ?>
                    </strong>
                </div>


                <div class="zone-detail-row zone-recommendation-row">

                    <span>
                        Recommendation
                    </span>

                    <strong id="zoneRecommendation">
                        <?= safe_output(
                            $default_zone[
                                "recommendation"
                            ]
                        ) ?>
                    </strong>

                </div>


                <a
                    class="zone-detail-button"
                    id="zoneImagesButton"
                    href="detection_results.php"
                >
                    View Zone Analyses
                </a>

            </div>

        </aside>

    </section>


        </div>

        <aside class="home-insight-column">

    <article class="home-insight-card">

        <div class="home-card-heading">
            <div>
                <h2>Severity Distribution</h2>
            </div>

        </div>

        <div class="home-severity-layout">

            <div
                class="severity-donut"
                style="
                    --low-value: <?= number_format(
                        $low_percentage,
                        4,
                        ".",
                        ""
                    ) ?>%;
                    --high-value: <?= number_format(
                        $high_percentage,
                        4,
                        ".",
                        ""
                    ) ?>%;
                "
            >
                <div>
                    <strong><?= number_format($total_severity_images) ?></strong>
                    <span>Trees</span>
                </div>
            </div>

            <div class="home-severity-legend">
                <div class="low">
                    <span></span>
                    <b>Low severity</b>
                    <strong><?= number_format($severity_counts["Low"]) ?></strong>
                </div>

                <div class="high">
                    <span></span>
                    <b>High severity</b>
                    <strong><?= number_format($severity_counts["High"]) ?></strong>
                </div>
            </div>

        </div>

    </article>


    <article class="home-insight-card">

        <div class="home-card-heading">
            <div>
                <h2>Treatment Required by Zone</h2>
            </div>
        </div>

        <div class="home-zone-bars">

            <?php foreach ($zone_names as $zone_name): ?>

                <?php
                $zone =
                    $zone_results[$zone_name]
                    ?? [
                        "high_images" => 0,
                        "image_count" => 0,
                        "treatment_rate" => 0,
                        "workload_ratio" => 0,
                        "workload_class" => "none"
                    ];

                $zone_bar_width = (float)$zone["workload_ratio"] * 100;
                $zone_treatment_trees = (int)$zone["high_images"];
                $zone_analysed_trees = (int)$zone["image_count"];
                ?>

                <div class="home-zone-bar-row">
                    <div class="home-zone-bar-heading">
                        <span><?= safe_output($zone_name) ?></span>
                        <strong><?= number_format($zone_treatment_trees) ?> trees</strong>
                    </div>

                    <div class="home-zone-bar-track">
                        <div
                            class="home-zone-bar-fill workload-<?= safe_output($zone["workload_class"]) ?>"
                            style="
                                width:
                                <?= number_format(
                                    $zone_bar_width,
                                    2,
                                    ".",
                                    ""
                                ) ?>%;
                                background:
                                <?= match ($zone["workload_class"]) {
                                    "low" => "#d6b735",
                                    "medium" => "#df7927",
                                    "high" => "#c64048",
                                    default => "#23894d"
                                } ?>;
                            "
                        ></div>
                    </div>

                    <small>
                        <?php if ($zone_analysed_trees === 0): ?>
                            0 analysed · No analyses yet
                        <?php elseif ($zone_treatment_trees === 0): ?>
                            0 / <?= number_format($zone_analysed_trees) ?> inspected · 0.0% require treatment
                        <?php else: ?>
                            <?= number_format($zone_treatment_trees) ?> of
                            <?= number_format($zone_analysed_trees) ?> inspected ·
                            <?= number_format((float)$zone["treatment_rate"], 1) ?>% require treatment
                        <?php endif ?>
                    </small>

                </div>

            <?php endforeach; ?>

        </div>

    </article>

        </aside>

    </section>


    <footer class="app-footer">

        © 2026 Inspector Weeds.
        All rights reserved.

    </footer>

</main>


<script>
const zoneData =
    <?= json_encode(
        $zone_js_data,
        JSON_UNESCAPED_SLASHES
    ) ?>;


const zoneButtons =
    document.querySelectorAll(
        ".svg-zone-group"
    );


const zoneDetailHeader =
    document.getElementById(
        "zoneDetailHeader"
    );

const zoneDetailName =
    document.getElementById(
        "zoneDetailName"
    );

const zoneTreatmentTrees =
    document.getElementById("zoneTreatmentTrees");

const zoneImageCount =
    document.getElementById("zoneImageCount");

const zoneTreatmentRate =
    document.getElementById("zoneTreatmentRate");

const zoneAverageCoverage =
    document.getElementById("zoneAverageCoverage");

const zoneTreatmentCost =
    document.getElementById("zoneTreatmentCost");

const zoneInspectionDate =
    document.getElementById("zoneInspectionDate");

const zoneRecommendation =
    document.getElementById(
        "zoneRecommendation"
    );

const zoneImagesButton =
    document.getElementById(
        "zoneImagesButton"
    );


function updateZoneDetails(zoneName) {

    const zone =
        zoneData[zoneName];

    if (!zone) {
        return;
    }

    zoneDetailName.textContent =
        zone.zone +
        " Details";

    zoneTreatmentTrees.textContent = zone.high_images;

    zoneImageCount.textContent =
        zone.image_count;

    zoneTreatmentRate.textContent =
        Number(zone.treatment_rate).toFixed(1) + "%";

    zoneAverageCoverage.textContent =
        Number(zone.average_coverage).toFixed(1) + "%";

    zoneTreatmentCost.textContent =
        "RM " + Number(zone.estimated_opex).toFixed(2);

    zoneInspectionDate.textContent =
        zone.last_inspection;

    zoneRecommendation.textContent =
        zone.recommendation;

    zoneImagesButton.href =
        "detection_results.php";

    const workloadColors = {
        none: "linear-gradient(90deg,#176f43,#249357)",
        low: "linear-gradient(90deg,#a48200,#c7a62f)",
        medium: "linear-gradient(90deg,#bd6419,#dc852e)",
        high: "linear-gradient(90deg,#9d3038,#c64a52)"
    };
    zoneDetailHeader.style.background =
        workloadColors[zone.workload_class] || workloadColors.none;

    zoneButtons.forEach(function (button) {
        button.classList.toggle(
            "selected",
            button.dataset.zone === zoneName
        );

        const polygon = button.querySelector(".svg-zone");
        if (!polygon) {
            return;
        }

        if (button.dataset.zone === zoneName) {
            polygon.style.stroke = "#ffffff";
            polygon.style.strokeWidth = "5";
            polygon.style.filter =
                "brightness(1.08) drop-shadow(0 0 5px rgba(255,255,255,0.38))";
        } else {
            polygon.style.stroke = "";
            polygon.style.strokeWidth = "";
            polygon.style.filter = "";
        }
    });
}


zoneButtons.forEach(
    function (button) {

        button.addEventListener(
            "mouseenter",
            function () {
                this.classList.add("hovered");
                const polygon = this.querySelector(".svg-zone");
                const label = this.querySelector(".svg-zone-label");
                if (polygon) {
                    polygon.style.stroke = "#ffffff";
                    polygon.style.strokeWidth = "5";
                    polygon.style.filter =
                        "brightness(1.12) drop-shadow(0 0 7px rgba(255,255,255,0.48))";
                }
                if (label) {
                    label.style.transform = "scale(1.01)";
                    label.style.transformBox = "fill-box";
                    label.style.transformOrigin = "center";
                }
            }
        );

        button.addEventListener(
            "mouseleave",
            function () {
                this.classList.remove("hovered");
                const polygon = this.querySelector(".svg-zone");
                const label = this.querySelector(".svg-zone-label");
                if (polygon) {
                    if (!this.classList.contains("selected")) {
                        polygon.style.stroke = "";
                        polygon.style.strokeWidth = "";
                        polygon.style.filter = "";
                    }
                }
                if (label) {
                    label.style.transform = this.classList.contains("selected")
                        ? "scale(1.01)"
                        : "";
                }
            }
        );

        button.addEventListener(
            "click",
            function () {

                const zoneName =
                    this.dataset.zone;

                updateZoneDetails(
                    zoneName
                );
            }
        );

        button.addEventListener(
            "keydown",
            function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    updateZoneDetails(this.dataset.zone);
                }
            }
        );
    }
);


updateZoneDetails(
    <?= json_encode(
        $default_zone_name
    ) ?>
);
</script>

</body>
</html>
