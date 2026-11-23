<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/treatment_rules.php';


/* =========================================================
   1. HELPERS
========================================================= */

function tpEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tpRedirect(
    string $message,
    string $type = 'success',
    array $extra = []
): never {
    header(
        'Location: treatment_planning.php?'
        . http_build_query(
            array_merge(
                [
                    'message' => $message,
                    'message_type' => $type,
                ],
                $extra
            )
        )
    );
    exit();
}

function tpTableExists(mysqli $conn, string $tableName): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $tableName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (int)($row['total'] ?? 0) > 0;
}


/* =========================================================
   2. LOAD TREE / IMAGE ANALYSIS BY ZONE
========================================================= */

/*
 * Prototype assumption:
 * One completed analysed image represents one palm/tree location.
 *
 * Treatment tree:
 * weed_coverage >= 5%
 *
 * Infested tree:
 * weed_coverage > 10%
 */
function tpLoadZoneAnalysis(mysqli $conn): array
{
    $sql = "
        SELECT
            i.zone_id,

            COUNT(*) AS analysed_trees,

            SUM(
                CASE
                    WHEN d.weed_coverage >= 5
                    THEN 1
                    ELSE 0
                END
            ) AS treatment_trees,

            SUM(
                CASE
                    WHEN d.weed_coverage > 10
                    THEN 1
                    ELSE 0
                END
            ) AS infested_trees,

            AVG(d.weed_coverage) AS average_coverage,
            MAX(d.weed_coverage) AS maximum_coverage,
            AVG(d.average_confidence) AS average_confidence,
            MAX(i.analysis_date) AS latest_analysis_date

        FROM images AS i

        INNER JOIN detection_results AS d
            ON d.image_id = i.image_id

        WHERE i.analysis_status = 'Completed'
          AND i.zone_id IS NOT NULL
          AND i.zone_id <> ''

        GROUP BY i.zone_id
        ORDER BY i.zone_id
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        throw new RuntimeException(
            'Unable to load zone analysis: '
            . mysqli_error($conn)
        );
    }

    $zones = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $zoneId = trim((string)$row['zone_id']);

        $zones[$zoneId] = [
            'zone_id' => $zoneId,
            'analysed_trees' => (int)$row['analysed_trees'],
            'treatment_trees' => (int)$row['treatment_trees'],
            'infested_trees' => (int)$row['infested_trees'],
            'average_coverage' =>
                round((float)$row['average_coverage'], 2),
            'maximum_coverage' =>
                round((float)$row['maximum_coverage'], 2),
            'average_confidence' =>
                round((float)$row['average_confidence'], 2),
            'latest_analysis_date' =>
                (string)$row['latest_analysis_date'],
        ];
    }

    return $zones;
}


/* =========================================================
   3. STORED PLAN SELECTIONS
========================================================= */

function tpLoadStoredPlans(mysqli $conn): array
{
    if (!tpTableExists($conn, 'treatment_plans')) {
        return [];
    }

    $result = mysqli_query(
        $conn,
        'SELECT *
         FROM treatment_plans
         ORDER BY zone_id'
    );

    if (!$result) {
        return [];
    }

    $plans = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $zoneId = trim((string)($row['zone_id'] ?? ''));

        if ($zoneId !== '') {
            $plans[$zoneId] = $row;
        }
    }

    return $plans;
}


/* =========================================================
   4. SAVE / UPDATE ZONE OPEX PLAN
========================================================= */

function tpUpsertPlan(
    mysqli $conn,
    array $analysis,
    array $plan,
    string $recommendation
): bool {
    $zoneId = (string)$analysis['zone_id'];

    $analysedTrees = (int)$analysis['analysed_trees'];
    $treatmentTrees = (int)$analysis['treatment_trees'];
    $infestedTrees = (int)$analysis['infested_trees'];

    $averageCoverage = (float)$analysis['average_coverage'];
    $maximumCoverage = (float)$analysis['maximum_coverage'];
    $averageConfidence = (float)$analysis['average_confidence'];

    $equivalentArea = (float)$plan['equivalent_area_ha'];

    $herbicide = (string)$plan['herbicide_name'];
    $applicationRate = (string)$plan['application_rate'];

    $herbicideCostPerHa =
        (float)$plan['herbicide_cost_per_hectare'];
    $herbicideCostPerTree =
        (float)$plan['herbicide_cost_per_tree'];

    $herbicideGramsPerHa =
        (float)$plan['herbicide_grams_per_hectare'];
    $herbicideGramsPerTree =
        (float)$plan['herbicide_grams_per_tree'];

    $waterLitresPerTree =
        (float)$plan['water_litres_per_tree'];
    $waterCostPerTree =
        (float)$plan['water_cost_per_tree'];

    $uavHoursPerTree =
        (float)$plan['uav_hours_per_tree'];
    $uavLabourCostPerTree =
        (float)$plan['uav_labour_cost_per_tree'];

    $herbicideRequiredGrams =
        (float)$plan['herbicide_required_grams'];
    $waterRequiredLitres =
        (float)$plan['water_required_litres'];
    $uavLabourHours =
        (float)$plan['uav_labour_hours'];

    $herbicideCost = (float)$plan['herbicide_cost'];
    $waterCost = (float)$plan['water_cost'];
    $uavLabourCost = (float)$plan['uav_labour_cost'];
    $totalCost = (float)$plan['total_cost'];

    $priority = (string)$plan['priority'];

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO treatment_plans (
            zone_id,
            analysed_trees,
            treatment_trees,
            infested_trees,
            average_coverage,
            maximum_coverage,
            average_confidence,
            equivalent_area_ha,
            recommended_herbicide,
            application_rate,
            herbicide_cost_per_hectare,
            herbicide_cost_per_tree,
            herbicide_grams_per_hectare,
            herbicide_grams_per_tree,
            water_litres_per_tree,
            water_cost_per_tree,
            uav_hours_per_tree,
            uav_labour_cost_per_tree,
            herbicide_required_grams,
            water_required_litres,
            uav_labour_hours,
            herbicide_cost,
            water_cost,
            uav_labour_cost,
            total_cost,
            priority_level,
            recommended_action,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            analysed_trees = VALUES(analysed_trees),
            treatment_trees = VALUES(treatment_trees),
            infested_trees = VALUES(infested_trees),
            average_coverage = VALUES(average_coverage),
            maximum_coverage = VALUES(maximum_coverage),
            average_confidence = VALUES(average_confidence),
            equivalent_area_ha = VALUES(equivalent_area_ha),
            recommended_herbicide = VALUES(recommended_herbicide),
            application_rate = VALUES(application_rate),
            herbicide_cost_per_hectare =
                VALUES(herbicide_cost_per_hectare),
            herbicide_cost_per_tree =
                VALUES(herbicide_cost_per_tree),
            herbicide_grams_per_hectare =
                VALUES(herbicide_grams_per_hectare),
            herbicide_grams_per_tree =
                VALUES(herbicide_grams_per_tree),
            water_litres_per_tree =
                VALUES(water_litres_per_tree),
            water_cost_per_tree =
                VALUES(water_cost_per_tree),
            uav_hours_per_tree =
                VALUES(uav_hours_per_tree),
            uav_labour_cost_per_tree =
                VALUES(uav_labour_cost_per_tree),
            herbicide_required_grams =
                VALUES(herbicide_required_grams),
            water_required_litres =
                VALUES(water_required_litres),
            uav_labour_hours =
                VALUES(uav_labour_hours),
            herbicide_cost = VALUES(herbicide_cost),
            water_cost = VALUES(water_cost),
            uav_labour_cost = VALUES(uav_labour_cost),
            total_cost = VALUES(total_cost),
            priority_level = VALUES(priority_level),
            recommended_action = VALUES(recommended_action),
            updated_at = CURRENT_TIMESTAMP'
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'siiiddddssdddddddddddddddss',
        $zoneId,
        $analysedTrees,
        $treatmentTrees,
        $infestedTrees,
        $averageCoverage,
        $maximumCoverage,
        $averageConfidence,
        $equivalentArea,
        $herbicide,
        $applicationRate,
        $herbicideCostPerHa,
        $herbicideCostPerTree,
        $herbicideGramsPerHa,
        $herbicideGramsPerTree,
        $waterLitresPerTree,
        $waterCostPerTree,
        $uavHoursPerTree,
        $uavLabourCostPerTree,
        $herbicideRequiredGrams,
        $waterRequiredLitres,
        $uavLabourHours,
        $herbicideCost,
        $waterCost,
        $uavLabourCost,
        $totalCost,
        $priority,
        $recommendation
    );

    $success = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $success;
}


/* =========================================================
   5. PAGE INITIALISATION
========================================================= */

$pageError = '';
$message = trim((string)($_GET['message'] ?? ''));
$messageType =
    ($_GET['message_type'] ?? 'success') === 'error'
        ? 'error'
        : 'success';

if (!tpTableExists($conn, 'treatment_plans')) {
    $pageError =
        'The treatment_plans table does not exist. '
        . 'Run the updated treatment_plans SQL in phpMyAdmin.';
}

$herbicideReference = getTreatmentHerbicideReference();
$storedPlans = [];
$zoneAnalysis = [];

if ($pageError === '') {
    try {
        $zoneAnalysis = tpLoadZoneAnalysis($conn);
        $storedPlans = tpLoadStoredPlans($conn);

        /*
         * Build/update one plan per zone.
         * Existing manager herbicide choices are preserved.
         */
        foreach ($zoneAnalysis as $zoneId => $analysis) {
            $existing = $storedPlans[$zoneId] ?? [];

            $selectedHerbicide =
                $existing['recommended_herbicide']
                ?? TREATMENT_DEFAULT_HERBICIDE;

            $selectedRate =
                $existing['application_rate']
                ?? TREATMENT_DEFAULT_APPLICATION_RATE;

            $plan = generateTreeBasedTreatmentPlan(
                (int)$analysis['treatment_trees'],
                $selectedHerbicide,
                $selectedRate
            );

            $recommendation =
                generateZoneTreatmentRecommendation(
                    $zoneId,
                    (int)$analysis['treatment_trees'],
                    (int)$analysis['analysed_trees'],
                    (float)$analysis['average_coverage']
                );

            if (
                !tpUpsertPlan(
                    $conn,
                    $analysis,
                    $plan,
                    $recommendation
                )
            ) {
                throw new RuntimeException(
                    'Could not synchronise '
                    . $zoneId
                    . ': '
                    . mysqli_error($conn)
                );
            }
        }

        $storedPlans = tpLoadStoredPlans($conn);
    } catch (Throwable $exception) {
        $pageError = $exception->getMessage();
    }
}


/* =========================================================
   6. FORM ACTION — CHANGE HERBICIDE PLAN
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $pageError === ''
) {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_zone_plan') {
        $zoneId = trim((string)($_POST['zone_id'] ?? ''));
        $herbicide =
            trim((string)($_POST['recommended_herbicide'] ?? ''));
        $rate =
            trim((string)($_POST['application_rate'] ?? ''));

        if (!isset($zoneAnalysis[$zoneId])) {
            tpRedirect(
                'The selected zone could not be found.',
                'error'
            );
        }

        $analysis = $zoneAnalysis[$zoneId];

        $plan = generateTreeBasedTreatmentPlan(
            (int)$analysis['treatment_trees'],
            $herbicide,
            $rate
        );

        $recommendation =
            generateZoneTreatmentRecommendation(
                $zoneId,
                (int)$analysis['treatment_trees'],
                (int)$analysis['analysed_trees'],
                (float)$analysis['average_coverage']
            );

        if (
            !tpUpsertPlan(
                $conn,
                $analysis,
                $plan,
                $recommendation
            )
        ) {
            tpRedirect(
                'The zone plan could not be recalculated.',
                'error'
            );
        }

        tpRedirect(
            $zoneId
            . ' OPEX plan recalculated successfully.',
            'success',
            [
                'zone' => $zoneId,
                'view' => 'details',
            ]
        );
    }
}


/* =========================================================
   7. FINAL DASHBOARD DATA — LIVE COST RECALCULATION

   IMPORTANT:
   The database stores the selected herbicide and application
   rate, but all numerical treatment costs are recalculated
   from those exact selections every time this page loads.

   This prevents an old stored cost (for example Glufosinate)
   from being displayed beside a newer dropdown selection
   (for example Glyphosate 400 g a.i./ha).
========================================================= */

$storedPlans = $pageError === ''
    ? tpLoadStoredPlans($conn)
    : [];

$records = [];

foreach ($zoneAnalysis as $zoneId => $analysis) {
    $stored = $storedPlans[$zoneId] ?? [];

    /*
     * Only the manager's treatment selection is taken from
     * the stored record. All quantities and costs below are
     * calculated fresh from treatment_rules.php.
     */
    $selectedHerbicide =
        (string)(
            $stored['recommended_herbicide']
            ?? TREATMENT_DEFAULT_HERBICIDE
        );

    $selectedRate =
        (string)(
            $stored['application_rate']
            ?? TREATMENT_DEFAULT_APPLICATION_RATE
        );

    /*
     * Resolve invalid / old selections safely and calculate
     * the exact per-tree and zone-level treatment plan.
     */
    $livePlan = generateTreeBasedTreatmentPlan(
        (int)$analysis['treatment_trees'],
        $selectedHerbicide,
        $selectedRate
    );

    $recommendation =
        generateZoneTreatmentRecommendation(
            $zoneId,
            (int)$analysis['treatment_trees'],
            (int)$analysis['analysed_trees'],
            (float)$analysis['average_coverage']
        );

    /*
     * Synchronise the database with the live calculation.
     * This also repairs stale cost values left by an older
     * version of the Treatment Planning page.
     */
    if (
        !tpUpsertPlan(
            $conn,
            $analysis,
            $livePlan,
            $recommendation
        )
    ) {
        $pageError =
            'Unable to synchronise the latest treatment cost '
            . 'for '
            . $zoneId
            . ': '
            . mysqli_error($conn);

        break;
    }

    /*
     * The dashboard displays the live calculation, NOT the
     * previous numerical values stored in treatment_plans.
     */
    $records[$zoneId] = array_merge(
        $analysis,
        [
            'equivalent_area_ha' =>
                (float)$livePlan['equivalent_area_ha'],

            'recommended_herbicide' =>
                (string)$livePlan['herbicide_name'],

            'application_rate' =>
                (string)$livePlan['application_rate'],

            'herbicide_cost_per_hectare' =>
                (float)$livePlan[
                    'herbicide_cost_per_hectare'
                ],

            'herbicide_cost_per_tree' =>
                (float)$livePlan[
                    'herbicide_cost_per_tree'
                ],

            'herbicide_grams_per_hectare' =>
                (float)$livePlan[
                    'herbicide_grams_per_hectare'
                ],

            'herbicide_grams_per_tree' =>
                (float)$livePlan[
                    'herbicide_grams_per_tree'
                ],

            'water_litres_per_tree' =>
                (float)$livePlan[
                    'water_litres_per_tree'
                ],

            'water_cost_per_tree' =>
                (float)$livePlan[
                    'water_cost_per_tree'
                ],

            'uav_hours_per_tree' =>
                (float)$livePlan[
                    'uav_hours_per_tree'
                ],

            'uav_labour_cost_per_tree' =>
                (float)$livePlan[
                    'uav_labour_cost_per_tree'
                ],

            'herbicide_required_grams' =>
                (float)$livePlan[
                    'herbicide_required_grams'
                ],

            'water_required_litres' =>
                (float)$livePlan[
                    'water_required_litres'
                ],

            'uav_labour_hours' =>
                (float)$livePlan[
                    'uav_labour_hours'
                ],

            'herbicide_cost' =>
                (float)$livePlan['herbicide_cost'],

            'water_cost' =>
                (float)$livePlan['water_cost'],

            'uav_labour_cost' =>
                (float)$livePlan['uav_labour_cost'],

            'total_cost' =>
                (float)$livePlan['total_cost'],

            'priority_level' =>
                (string)$livePlan['priority'],

            'recommended_action' =>
                $recommendation,
        ]
    );
}

/*
 * Reload corrected rows so the Dashboard receives the same synchronised OPEX.
 */
if ($pageError === '') {
    $storedPlans = tpLoadStoredPlans($conn);
}

/* =========================================================
   8. ZONE + SEVERITY + HERBICIDE FILTERS
========================================================= */

$zoneFilter =
    trim((string)($_GET['zone'] ?? ''));

$severityFilter =
    trim((string)($_GET['severity'] ?? ''));

$herbicideFilter =
    trim((string)($_GET['herbicide'] ?? ''));

$detailMode =
    trim((string)($_GET['view'] ?? '')) === 'details';

$zonesAvailable =
    array_keys($records);

sort($zonesAvailable);

/*
 * For this Treatment Planning page:
 * - High = the zone contains at least one tree/image
 *          whose weed coverage is >= 5%.
 * - Low  = no analysed tree in the zone currently
 *          reaches the 5% treatment threshold.
 *
 * This keeps the filter consistent with the treatment
 * costing logic used on this page.
 */
foreach ($records as $zoneId => &$record) {
    $record['severity_level'] =
        (int)$record['treatment_trees'] > 0
            ? 'High'
            : 'Low';
}
unset($record);

$records = array_filter(
    $records,
    static function (array $record) use (
        $zoneFilter,
        $severityFilter,
        $herbicideFilter
    ): bool {
        if (
            $zoneFilter !== ''
            && (string)$record['zone_id']
                !== $zoneFilter
        ) {
            return false;
        }

        if (
            in_array(
                $severityFilter,
                ['Low', 'High'],
                true
            )
            && (string)$record['severity_level']
                !== $severityFilter
        ) {
            return false;
        }

        if (
            $herbicideFilter !== ''
            && (string)$record['recommended_herbicide']
                !== $herbicideFilter
        ) {
            return false;
        }

        return true;
    }
);


$summary = [
    'treatment_trees' => 0,
    'infested_trees' => 0,
    'equivalent_area_ha' => 0.00,
    'herbicide_required_grams' => 0.00,
    'water_required_litres' => 0.00,
    'uav_labour_hours' => 0.00,
    'herbicide_cost' => 0.00,
    'water_cost' => 0.00,
    'uav_labour_cost' => 0.00,
    'total_cost' => 0.00,
    'priority_zone' => 'None',
    'priority_zone_cost' => 0.00,
];

foreach ($records as $record) {
    $summary['treatment_trees'] +=
        (int)$record['treatment_trees'];

    $summary['infested_trees'] +=
        (int)$record['infested_trees'];

    $summary['equivalent_area_ha'] +=
        (float)$record['equivalent_area_ha'];

    $summary['herbicide_required_grams'] +=
        (float)$record['herbicide_required_grams'];

    $summary['water_required_litres'] +=
        (float)$record['water_required_litres'];

    $summary['uav_labour_hours'] +=
        (float)$record['uav_labour_hours'];

    $summary['herbicide_cost'] +=
        (float)$record['herbicide_cost'];

    $summary['water_cost'] +=
        (float)$record['water_cost'];

    $summary['uav_labour_cost'] +=
        (float)$record['uav_labour_cost'];

    $summary['total_cost'] +=
        (float)$record['total_cost'];

    if (
        (float)$record['total_cost']
        > $summary['priority_zone_cost']
    ) {
        $summary['priority_zone_cost'] =
            (float)$record['total_cost'];

        $summary['priority_zone'] =
            (string)$record['zone_id'];
    }
}

$chartLabels = [];
$chartValues = [];

foreach ($records as $record) {
    $chartLabels[] = $record['zone_id'];
    $chartValues[] = round(
        (float)$record['total_cost'],
        2
    );
}

/*
 * Drill-through record:
 * Detailed treatment information is shown only when a zone
 * is selected from the filter or the summary table.
 */
$selectedRecord =
    (
        $zoneFilter !== ''
        && isset($records[$zoneFilter])
    )
        ? $records[$zoneFilter]
        : null;

$styleVersion =
    file_exists(__DIR__ . '/css/style.css')
        ? filemtime(__DIR__ . '/css/style.css')
        : time();

$treatmentStyleVersion =
    file_exists(__DIR__ . '/css/treatment.css')
        ? filemtime(__DIR__ . '/css/treatment.css')
        : time();

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
        Treatment & Resource Planning | Inspector Weeds
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

    <link
        rel="stylesheet"
        href="css/style.css?v=<?= tpEscape($styleVersion) ?>"
    >

    <link
        rel="stylesheet"
        href="css/treatment.css?v=<?= tpEscape(
            $treatmentStyleVersion
        ) ?>-saas-v16"
    >
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="dashboard-body treatment-planning-page">

<?php require __DIR__ . "/includes/sidebar.php"; ?>


<main class="app-main treatment-planning-main">

    <header class="treatment-planning-header">
        <div>
            <h1>
                Treatment &amp; OPEX Planning
            </h1>

            <p>
                Estimate treatment palms, equivalent area,
                herbicide, water, UAV labour and operational
                expenditure by plantation zone.
            </p>
        </div>

        <div class="treatment-header-actions">
            <button
                type="button"
                class="analytics-print-button"
                onclick="window.print()"
            >
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" style="fill:none;stroke:currentColor;stroke-width:1.8"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v7H7z"/></svg>
                Export / Print Plan
            </button>
        </div>
    </header>


    <?php if ($message !== ''): ?>
        <div
            class="page-alert <?= tpEscape($messageType) ?>"
        >
            <?= tpEscape($message) ?>
        </div>
    <?php endif; ?>


    <?php if ($pageError !== ''): ?>
        <div class="page-alert error">
            <?= tpEscape($pageError) ?>
        </div>
    <?php endif; ?>


    <!-- =====================================================
         COMPACT MANAGEMENT OVERVIEW
         Filters and OPEX appear first. The full-width zone
         treatment summary follows beneath them.
    ====================================================== -->

    <?php if (!$detailMode): ?>

    <div class="treatment-dashboard-layout">

        <section class="treatment-overview-panel">


            <!-- =============================================
                 MANAGEMENT KPI CARDS
            ============================================== -->

            <section class="treatment-overview-kpis">

                <article class="treatment-overview-kpi">
                    <div class="treatment-overview-kpi-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" style="fill:none;stroke:currentColor;stroke-width:1.7"><path d="M12 21V10M12 10c-4 0-7-2-8-5 4 0 7 1 8 5Zm0 0c4 0 7-2 8-5-4 0-7 1-8 5Z"/></svg>
                    </div>

                    <div>
                        <span>
                            Treatment Required
                        </span>

                        <strong>
                            <?= number_format(
                                (int)$summary[
                                    'treatment_trees'
                                ]
                            ) ?>
                        </strong>

                        <small>
                            Trees with coverage ≥5%
                        </small>
                    </div>
                </article>


                <article class="treatment-overview-kpi">
                    <div class="treatment-overview-kpi-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" style="fill:none;stroke:currentColor;stroke-width:1.7"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.7-1-3-1-1.7 0-3 .8-3 2s1.2 1.8 3 2.2 3 1 3 2.3-1.3 2-3 2c-1.3 0-2.5-.4-3.3-1.2M12 5.5v13"/></svg>
                    </div>

                    <div>
                        <span>
                            Total OPEX
                        </span>

                        <strong>
                            <?= treatmentFormatMoney(
                                (float)$summary[
                                    'total_cost'
                                ]
                            ) ?>
                        </strong>

                        <small>
                            Herbicide + water + UAV labour
                        </small>
                    </div>
                </article>


                <article class="treatment-overview-kpi">
                    <div class="treatment-overview-kpi-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" style="fill:none;stroke:currentColor;stroke-width:1.7"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    </div>

                    <div>
                        <span>
                            Priority Zone
                        </span>

                        <strong>
                            <?= tpEscape(
                                $summary[
                                    'priority_zone'
                                ]
                            ) ?>
                        </strong>

                        <small>
                            Highest treatment OPEX
                        </small>
                    </div>
                </article>

            </section>


            <!-- =============================================
                 OPEX BY ZONE
            ============================================== -->

            <section
                class="
                    content-card
                    treatment-overview-chart-card
                "
            >

                <div class="treatment-compact-heading">

                    <div>
                        <h2>
                            OPEX by Zone
                        </h2>
                    </div>

                    <span>
                        Estimated expenditure (RM)
                    </span>

                </div>


                <div class="treatment-overview-chart-wrap">
                    <canvas id="opexByZoneChart"></canvas>
                </div>

            </section>


            <!-- =============================================
                 COMPACT ZONE SUMMARY / DRILL THROUGH
            ============================================== -->

            <section
                class="
                    content-card
                    treatment-zone-summary-card
                "
            >

                <div class="treatment-compact-heading">

                    <div>
                        <h2>
                            Treatment Priorities
                        </h2>
                    </div>

                    <span>
                        Select a zone to view the detailed plan
                    </span>

                </div>


                <?php if (empty($records)): ?>

                    <div class="treatment-empty-state">
                        No completed plantation analyses match
                        the selected filters.
                    </div>

                <?php else: ?>

                    <div class="treatment-summary-table-wrap">

                        <table class="treatment-summary-table">

                            <thead>
                            <tr>
                                <th>Zone</th>
                                <th>Treat Trees</th>
                                <th>OPEX</th>
                                <th>Priority</th>
                                <th>Plan</th>
                            </tr>
                            </thead>


                            <tbody>

                            <?php foreach (
                                $records
                                as $record
                            ): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= tpEscape(
                                                $record[
                                                    'zone_id'
                                                ]
                                            ) ?>
                                        </strong>

                                        <small>
                                            <?= treatmentFormatCoverage(
                                                (float)$record[
                                                    'average_coverage'
                                                ]
                                            ) ?>
                                            avg cover
                                        </small>
                                    </td>


                                    <td>
                                        <?= number_format(
                                            (int)$record[
                                                'treatment_trees'
                                            ]
                                        ) ?>
                                    </td>


                                    <td>
                                        <strong>
                                            <?= treatmentFormatMoney(
                                                (float)$record[
                                                    'total_cost'
                                                ]
                                            ) ?>
                                        </strong>
                                    </td>


                                    <td>
                                        <span
                                            class="
                                                treatment-priority-badge
                                                <?= strtolower(
                                                    tpEscape(
                                                        $record[
                                                            'priority_level'
                                                        ]
                                                    )
                                                ) ?>
                                            "
                                        >
                                            <?= tpEscape(
                                                $record[
                                                    'priority_level'
                                                ]
                                            ) ?>
                                        </span>
                                    </td>


                                    <td>
                                        <a
                                            class="
                                                treatment-view-plan-link
                                            "
                                            href="treatment_planning.php?<?= tpEscape(
                                                http_build_query([
                                                    'zone' =>
                                                        $record[
                                                            'zone_id'
                                                        ],
                                                    'severity' =>
                                                        $severityFilter,
                                                    'herbicide' =>
                                                        $herbicideFilter,
                                                    'view' =>
                                                        'details',
                                                ])
                                            ) ?>"
                                        >
                                            View Plan →
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        </section>


        <!-- =============================================
             LEFT-SIDE FILTER PANEL
        ============================================== -->

        <aside class="treatment-side-filter">

            <div class="treatment-side-filter-heading">
                <span>Plan Filters</span>
            </div>


            <form
                method="get"
                class="treatment-side-filter-form"
            >


                <label for="zone">

                    <span>
                        Zone
                    </span>

                    <select
                        name="zone"
                        id="zone"
                    >
                        <option value="">
                            All zones
                        </option>

                        <?php foreach (
                            $zonesAvailable
                            as $zoneId
                        ): ?>

                            <option
                                value="<?= tpEscape(
                                    $zoneId
                                ) ?>"
                                <?= $zoneFilter === $zoneId
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= tpEscape(
                                    $zoneId
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>


                <label for="severity">

                    <span>
                        Severity
                    </span>

                    <select
                        name="severity"
                        id="severity"
                    >
                        <option value="">
                            All severities
                        </option>

                        <option
                            value="Low"
                            <?= $severityFilter === 'Low'
                                ? 'selected'
                                : '' ?>
                        >
                            Low
                        </option>

                        <option
                            value="High"
                            <?= $severityFilter === 'High'
                                ? 'selected'
                                : '' ?>
                        >
                            High
                        </option>

                    </select>

                </label>


                <label for="herbicide">

                    <span>
                        Herbicide
                    </span>

                    <select
                        name="herbicide"
                        id="herbicide"
                    >

                        <option value="">
                            All herbicides
                        </option>

                        <?php foreach (
                            $herbicideReference
                            as $herbicideName
                                => $herbicideData
                        ): ?>

                            <option
                                value="<?= tpEscape(
                                    $herbicideName
                                ) ?>"
                                <?= $herbicideFilter
                                    === $herbicideName
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= tpEscape(
                                    $herbicideName
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </label>


                <div class="treatment-side-filter-actions">

                    <button
                        type="submit"
                        class="treatment-filter-apply"
                    >
                        Apply Filters
                    </button>

                    <a
                        href="treatment_planning.php"
                        class="treatment-filter-clear"
                    >
                        Clear
                    </a>

                </div>

            </form>


        </aside>

    </div>

    <?php endif; ?>


    <!-- =====================================================
         ZONE DRILL-THROUGH DETAIL
         Only appears after the user selects a zone.
    ====================================================== -->

    <?php if ($detailMode && $zoneFilter !== ''): ?>


        <?php if ($selectedRecord): ?>

            <?php
            $selectedTreatmentRate =
                (int)$selectedRecord[
                    'analysed_trees'
                ] > 0
                    ? (
                        (int)$selectedRecord[
                            'treatment_trees'
                        ]
                        /
                        (int)$selectedRecord[
                            'analysed_trees'
                        ]
                    ) * 100
                    : 0.00;
            ?>


            <section
                class="
                    treatment-zone-detail
                    treatment-drill-through
                "
                data-treatment-trees="<?= (int)$selectedRecord[
                    'treatment_trees'
                ] ?>"
            >


                <div class="treatment-drill-banner">

                    <div>
                        <h2>
                            <?= tpEscape(
                                $selectedRecord[
                                    'zone_id'
                                ]
                            ) ?>
                            Detailed Treatment &amp; Resource Plan
                        </h2>
                    </div>

                    <a
                        href="treatment_planning.php"
                        class="treatment-back-overview"
                    >
                        ← Back to Overview
                    </a>

                </div>


                <div class="treatment-drill-grid">


                    <!-- =====================================
                         LOCATION / CONDITION
                    ====================================== -->

                    <article
                        class="
                            content-card
                            treatment-drill-card
                        "
                    >

                        <div class="treatment-drill-card-title">
                            LOCATION &amp; CONDITION
                        </div>


                        <div class="treatment-drill-metrics">

                            <div>
                                <span>
                                    Trees Analysed
                                </span>

                                <strong>
                                    <?= number_format(
                                        (int)$selectedRecord[
                                            'analysed_trees'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Trees to Treat
                                </span>

                                <strong>
                                    <?= number_format(
                                        (int)$selectedRecord[
                                            'treatment_trees'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Treat Rate
                                </span>

                                <strong>
                                    <?= number_format(
                                        $selectedTreatmentRate,
                                        1
                                    ) ?>%
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Average Coverage
                                </span>

                                <strong>
                                    <?= treatmentFormatCoverage(
                                        (float)$selectedRecord[
                                            'average_coverage'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Equivalent Area
                                </span>

                                <strong>
                                    <?= treatmentFormatArea(
                                        (float)$selectedRecord[
                                            'equivalent_area_ha'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Priority
                                </span>

                                <strong>
                                    <?= tpEscape(
                                        $selectedRecord[
                                            'priority_level'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </article>


                    <!-- =====================================
                         RESOURCE REQUIREMENTS
                    ====================================== -->

                    <article
                        class="
                            content-card
                            treatment-drill-card
                        "
                    >

                        <div class="treatment-drill-card-title">
                            RESOURCE REQUIREMENTS
                        </div>


                        <div class="treatment-drill-metrics">

                            <div>
                                <span>
                                    Herbicide Required
                                </span>

                                <strong>
                                    <?= treatmentFormatGrams(
                                        (float)$selectedRecord[
                                            'herbicide_required_grams'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Water Required
                                </span>

                                <strong>
                                    <?= treatmentFormatLitres(
                                        (float)$selectedRecord[
                                            'water_required_litres'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    UAV Labour
                                </span>

                                <strong>
                                    <?= treatmentFormatHours(
                                        (float)$selectedRecord[
                                            'uav_labour_hours'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </article>


                    <!-- =====================================
                         OPEX BREAKDOWN
                    ====================================== -->

                    <article
                        class="
                            content-card
                            treatment-drill-card
                        "
                    >

                        <div class="treatment-drill-card-title">
                            OPEX BREAKDOWN
                        </div>


                        <div class="treatment-drill-metrics">

                            <div>
                                <span>
                                    Herbicide
                                </span>

                                <strong class="zone-herbicide-cost">
                                    <?= treatmentFormatMoney(
                                        (float)$selectedRecord[
                                            'herbicide_cost'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    Water
                                </span>

                                <strong>
                                    <?= treatmentFormatMoney(
                                        (float)$selectedRecord[
                                            'water_cost'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div>
                                <span>
                                    UAV Labour
                                </span>

                                <strong>
                                    <?= treatmentFormatMoney(
                                        (float)$selectedRecord[
                                            'uav_labour_cost'
                                        ]
                                    ) ?>
                                </strong>
                            </div>


                            <div class="highlight">
                                <span>
                                    Total OPEX
                                </span>

                                <strong class="zone-total-opex">
                                    <?= treatmentFormatMoney(
                                        (float)$selectedRecord[
                                            'total_cost'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </article>


                    <!-- =====================================
                         TREATMENT PLAN EDITOR
                    ====================================== -->

                    <article
                        class="
                            content-card
                            treatment-drill-card
                            treatment-drill-plan-card
                        "
                    >

                        <div class="treatment-drill-card-title">
                            TREATMENT PLAN
                        </div>


                        <form
                            method="post"
                            class="treatment-plan-editor"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="save_zone_plan"
                            >

                            <input
                                type="hidden"
                                name="zone_id"
                                value="<?= tpEscape(
                                    $selectedRecord[
                                        'zone_id'
                                    ]
                                ) ?>"
                            >


                            <label>
                                Herbicide

                                <select
                                    name="recommended_herbicide"
                                    class="
                                        treatment-herbicide-select
                                    "
                                >

                                    <?php foreach (
                                        $herbicideReference
                                        as $herbicideName
                                            => $herbicideData
                                    ): ?>

                                        <option
                                            value="<?= tpEscape(
                                                $herbicideName
                                            ) ?>"
                                            <?= $selectedRecord[
                                                'recommended_herbicide'
                                            ] === $herbicideName
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= tpEscape(
                                                $herbicideName
                                            ) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </label>


                            <label>
                                Application Rate

                                <select
                                    name="application_rate"
                                    class="treatment-rate-select"
                                    data-current="<?= tpEscape(
                                        $selectedRecord[
                                            'application_rate'
                                        ]
                                    ) ?>"
                                ></select>

                            </label>


                            <div class="treatment-live-preview">

                                <span>
                                    Herbicide cost / tree
                                </span>

                                <strong>
                                    <span
                                        class="
                                            zone-herbicide-cost-per-tree
                                        "
                                    >
                                        <?= number_format(
                                            (float)$selectedRecord[
                                                'herbicide_cost_per_tree'
                                            ],
                                            4
                                        ) ?>
                                    </span>
                                    RM/tree
                                </strong>

                            </div>


                            <button
                                type="submit"
                                class="
                                    treatment-small-button
                                    treatment-drill-save
                                "
                            >
                                Apply &amp; Recalculate
                            </button>

                        </form>

                    </article>

                </div>

            </section>


        <?php else: ?>

            <div class="treatment-empty-state">
                No treatment detail is available for the
                selected zone and filters.
            </div>

        <?php endif; ?>


    <?php endif; ?>


    <footer class="app-footer">
        © 2026 Inspector Weeds. All rights reserved.
    </footer>

</main>


<script>
const herbicideRates = <?= json_encode(
    array_map(
        static fn(array $item): array =>
            array_keys($item['rates']),
        $herbicideReference
    ),
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) ?>;

/*
 * Full costing reference for live dashboard preview.
 * The server remains the source of truth when the form is submitted.
 */
const treatmentCostReference = <?= json_encode(
    $herbicideReference,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) ?>;

const TREES_PER_HECTARE = <?= (int)TREATMENT_TREES_PER_HECTARE ?>;
const WATER_COST_PER_HECTARE = <?= (float)TREATMENT_WATER_COST_PER_HECTARE ?>;
const UAV_LABOUR_COST_PER_HECTARE = <?= (float)TREATMENT_UAV_LABOUR_COST_PER_HECTARE ?>;

function populateRates(herbicideSelect) {
    const form = herbicideSelect.closest(
        '.treatment-plan-editor'
    );

    if (!form) return;

    const rateSelect = form.querySelector(
        '.treatment-rate-select'
    );

    if (!rateSelect) return;

    const currentRate =
        rateSelect.dataset.current || '';

    const rates =
        herbicideRates[herbicideSelect.value]
        || [];

    rateSelect.innerHTML = '';

    rates.forEach((rate) => {
        const option =
            document.createElement('option');

        option.value = rate;
        option.textContent = rate;

        if (rate === currentRate) {
            option.selected = true;
        }

        rateSelect.appendChild(option);
    });

    if (
        rateSelect.selectedIndex < 0
        && rateSelect.options.length > 0
    ) {
        rateSelect.selectedIndex = 0;
    }
}


function updateZoneCostPreview(form) {
    if (!form) return;

    const row =
        form.closest(
            '.treatment-zone-detail'
        );

    if (!row) return;

    const herbicideSelect =
        form.querySelector(
            '.treatment-herbicide-select'
        );

    const rateSelect =
        form.querySelector(
            '.treatment-rate-select'
        );

    if (!herbicideSelect || !rateSelect) {
        return;
    }

    const herbicide =
        herbicideSelect.value;

    const rate =
        rateSelect.value;

    const rateData =
        treatmentCostReference?.[herbicide]
            ?.rates?.[rate];

    if (!rateData) {
        return;
    }

    const treatmentTrees =
        Number(
            row.dataset.treatmentTrees || 0
        );

    /*
     * Costing logic:
     *
     * herbicide cost/tree
     * = herbicide cost/ha ÷ 109 trees/ha
     *
     * total OPEX/tree
     * = herbicide cost/tree
     * + water cost/tree
     * + UAV labour cost/tree
     *
     * zone OPEX
     * = total OPEX/tree × treatment trees
     */
    const herbicideCostPerTree =
        Number(rateData.cost_per_hectare)
        / TREES_PER_HECTARE;

    const waterCostPerTree =
        WATER_COST_PER_HECTARE
        / TREES_PER_HECTARE;

    const uavCostPerTree =
        UAV_LABOUR_COST_PER_HECTARE
        / TREES_PER_HECTARE;

    const herbicideCost =
        herbicideCostPerTree
        * treatmentTrees;

    const totalOpex =
        (
            herbicideCostPerTree
            + waterCostPerTree
            + uavCostPerTree
        )
        * treatmentTrees;

    const totalEl =
        row.querySelector(
            '.zone-total-opex'
        );

    const herbicideEl =
        row.querySelector(
            '.zone-herbicide-cost'
        );

    const herbicidePerTreeEl =
        row.querySelector(
            '.zone-herbicide-cost-per-tree'
        );

    if (totalEl) {
        totalEl.textContent =
            'RM '
            + totalOpex.toFixed(2);
    }

    if (herbicideEl) {
        herbicideEl.textContent =
            'RM '
            + herbicideCost.toFixed(2);
    }

    if (herbicidePerTreeEl) {
        herbicidePerTreeEl.textContent =
            herbicideCostPerTree.toFixed(4);
    }
}

document
    .querySelectorAll(
        '.treatment-herbicide-select'
    )
    .forEach((select) => {
        populateRates(select);

        const initialForm =
            select.closest(
                '.treatment-plan-editor'
            );

        updateZoneCostPreview(
            initialForm
        );

        select.addEventListener(
            'change',
            () => {
                const form = select.closest(
                    '.treatment-plan-editor'
                );

                const rateSelect =
                    form?.querySelector(
                        '.treatment-rate-select'
                    );

                if (rateSelect) {
                    rateSelect.dataset.current = '';
                }

                populateRates(select);

                updateZoneCostPreview(
                    form
                );
            }
        );

        const form =
            select.closest(
                '.treatment-plan-editor'
            );

        const rateSelect =
            form?.querySelector(
                '.treatment-rate-select'
            );

        rateSelect?.addEventListener(
            'change',
            () => {
                updateZoneCostPreview(
                    form
                );
            }
        );
    });


const zoneLabels = <?= json_encode(
    $chartLabels,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) ?>;

const zoneOpex = <?= json_encode(
    $chartValues,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) ?>;

const chartCanvas =
    document.getElementById(
        'opexByZoneChart'
    );

if (chartCanvas) {
    new Chart(chartCanvas, {
        type: 'bar',

        data: {
            labels: zoneLabels,

            datasets: [{
                label: 'Estimated OPEX (RM)',
                data: zoneOpex,
                backgroundColor: '#1b8c55',
                borderRadius: 9,
                borderSkipped: false,
                barThickness: 26
            }]
        },

        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    display: false
                },

                tooltip: {
                    callbacks: {
                        label: (context) =>
                            'RM '
                            + Number(
                                context.raw
                            ).toFixed(2)
                    }
                }
            },

            scales: {
                x: {
                    beginAtZero: true,

                    ticks: {
                        callback: (value) =>
                            'RM ' + value
                    },

                    grid: {
                        color: 'rgba(22, 53, 47, 0.08)'
                    }
                },

                y: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
