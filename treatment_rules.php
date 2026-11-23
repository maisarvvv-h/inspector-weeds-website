<?php
declare(strict_types=1);

/* =========================================================
   INSPECTOR WEEDS — TREE-BASED TREATMENT & OPEX RULES
   File: treatment_rules.php

   Core assumption for the prototype:
   - 1 completed analysed image represents 1 palm/tree location.
   - A tree requires treatment when its weed coverage is >= 5%.
   - A tree is "Infested" when weed coverage is > 10%.

   Cost model (per hectare):
   - 109 palms / hectare
   - Water: 40 L / hectare
   - Water cost: RM 0.1404 / hectare
   - UAV labour: 0.5333333333 hour / hectare
   - UAV labour cost: RM 8.33 / hectare

   Total OPEX = herbicide + water + UAV labour
========================================================= */


/* =========================================================
   1. PROJECT CONSTANTS
========================================================= */

const TREATMENT_LOW_THRESHOLD = 5.00;
const TREATMENT_INFESTATION_THRESHOLD = 10.00;

const TREATMENT_TREES_PER_HECTARE = 109;

const TREATMENT_WATER_LITRES_PER_HECTARE = 40.00;
const TREATMENT_WATER_COST_PER_HECTARE = 0.1404;

const TREATMENT_UAV_HOURS_PER_HECTARE = 0.5333333333;
const TREATMENT_UAV_LABOUR_COST_PER_HECTARE = 8.33;

const TREATMENT_DEFAULT_HERBICIDE = 'Glyphosate';
const TREATMENT_DEFAULT_APPLICATION_RATE = '400 g a.i./ha';


/* =========================================================
   2. COST CATALOGUE
========================================================= */

function getTreatmentHerbicideReference(): array
{
    return [
        'Glyphosate' => [
            'display_name' => 'Glyphosate',
            'product_reference' => 'Roundup®',
            'rates' => [
                '400 g a.i./ha' => [
                    'rate_grams_per_hectare' => 400.00,
                    'cost_per_hectare' => 30.30,
                ],
                '800 g a.i./ha' => [
                    'rate_grams_per_hectare' => 800.00,
                    'cost_per_hectare' => 45.50,
                ],
                '1200 g a.i./ha' => [
                    'rate_grams_per_hectare' => 1200.00,
                    'cost_per_hectare' => 60.80,
                ],
                '1600 g a.i./ha' => [
                    'rate_grams_per_hectare' => 1600.00,
                    'cost_per_hectare' => 76.10,
                ],
            ],
        ],

        'Glufosinate Ammonium' => [
            'display_name' => 'Glufosinate Ammonium',
            'product_reference' => 'Basta 15®',
            'rates' => [
                '200 g a.i./ha' => [
                    'rate_grams_per_hectare' => 200.00,
                    'cost_per_hectare' => 61.60,
                ],
                '400 g a.i./ha' => [
                    'rate_grams_per_hectare' => 400.00,
                    'cost_per_hectare' => 108.50,
                ],
                '600 g a.i./ha' => [
                    'rate_grams_per_hectare' => 600.00,
                    'cost_per_hectare' => 155.00,
                ],
                '800 g a.i./ha' => [
                    'rate_grams_per_hectare' => 800.00,
                    'cost_per_hectare' => 201.60,
                ],
            ],
        ],
    ];
}


/* =========================================================
   3. CLASSIFICATION
========================================================= */

function normaliseTreatmentCoverage(float $coverage): float
{
    return max(0.00, min(100.00, $coverage));
}

function determineTreatmentSeverity(float $weedCoverage): string
{
    return normaliseTreatmentCoverage($weedCoverage) < TREATMENT_LOW_THRESHOLD
        ? 'Low'
        : 'High';
}

function determineInfestationStatus(float $weedCoverage): string
{
    return normaliseTreatmentCoverage($weedCoverage) > TREATMENT_INFESTATION_THRESHOLD
        ? 'Infested'
        : 'Not Infested';
}

function determineTreatmentRequiredFromCoverage(float $weedCoverage): string
{
    return normaliseTreatmentCoverage($weedCoverage) >= TREATMENT_LOW_THRESHOLD
        ? 'Yes'
        : 'No';
}

function determineZonePriority(int $treatmentTrees): string
{
    return $treatmentTrees > 0 ? 'High' : 'Low';
}


/* =========================================================
   4. HERBICIDE SELECTION
========================================================= */

function resolveTreatmentSelection(
    ?string $herbicideName,
    ?string $applicationRate
): array {
    $reference = getTreatmentHerbicideReference();

    $herbicideName = trim((string)$herbicideName);
    $applicationRate = trim((string)$applicationRate);

    if (!isset($reference[$herbicideName])) {
        $herbicideName = TREATMENT_DEFAULT_HERBICIDE;
    }

    if (!isset($reference[$herbicideName]['rates'][$applicationRate])) {
        $applicationRate = array_key_first($reference[$herbicideName]['rates'])
            ?? TREATMENT_DEFAULT_APPLICATION_RATE;
    }

    return [
        'herbicide_name' => $herbicideName,
        'application_rate' => $applicationRate,
    ];
}

function getTreatmentRateDetails(
    string $herbicideName,
    string $applicationRate
): array {
    $reference = getTreatmentHerbicideReference();

    if (!isset($reference[$herbicideName]['rates'][$applicationRate])) {
        throw new InvalidArgumentException(
            'Unsupported herbicide or application rate.'
        );
    }

    $herbicide = $reference[$herbicideName];
    $rate = $herbicide['rates'][$applicationRate];

    return [
        'herbicide_name' => $herbicideName,
        'product_reference' => (string)$herbicide['product_reference'],
        'application_rate' => $applicationRate,
        'rate_grams_per_hectare' => (float)$rate['rate_grams_per_hectare'],
        'herbicide_cost_per_hectare' => (float)$rate['cost_per_hectare'],
    ];
}


/* =========================================================
   5. PER-TREE / EQUIVALENT-AREA CALCULATIONS
========================================================= */

function calculateEquivalentArea(int $treatmentTrees): float
{
    if ($treatmentTrees <= 0) {
        return 0.00;
    }

    return $treatmentTrees / TREATMENT_TREES_PER_HECTARE;
}

function calculateCostPerTree(float $costPerHectare): float
{
    return max(0.00, $costPerHectare) / TREATMENT_TREES_PER_HECTARE;
}

function calculateResourcePerTree(float $resourcePerHectare): float
{
    return max(0.00, $resourcePerHectare) / TREATMENT_TREES_PER_HECTARE;
}


/* =========================================================
   6. COMPLETE TREE-BASED OPEX PLAN
========================================================= */

function generateTreeBasedTreatmentPlan(
    int $treatmentTrees,
    ?string $selectedHerbicide = null,
    ?string $selectedApplicationRate = null
): array {
    $treatmentTrees = max(0, $treatmentTrees);

    $selection = resolveTreatmentSelection(
        $selectedHerbicide,
        $selectedApplicationRate
    );

    $rate = getTreatmentRateDetails(
        $selection['herbicide_name'],
        $selection['application_rate']
    );

    $equivalentArea = calculateEquivalentArea($treatmentTrees);

    $herbicideCostPerTree = calculateCostPerTree(
        (float)$rate['herbicide_cost_per_hectare']
    );

    $herbicideGramsPerTree = calculateResourcePerTree(
        (float)$rate['rate_grams_per_hectare']
    );

    $waterCostPerTree = calculateCostPerTree(
        TREATMENT_WATER_COST_PER_HECTARE
    );

    $waterLitresPerTree = calculateResourcePerTree(
        TREATMENT_WATER_LITRES_PER_HECTARE
    );

    $uavLabourCostPerTree = calculateCostPerTree(
        TREATMENT_UAV_LABOUR_COST_PER_HECTARE
    );

    $uavHoursPerTree = calculateResourcePerTree(
        TREATMENT_UAV_HOURS_PER_HECTARE
    );

    $herbicideCost = $herbicideCostPerTree * $treatmentTrees;
    $waterCost = $waterCostPerTree * $treatmentTrees;
    $uavLabourCost = $uavLabourCostPerTree * $treatmentTrees;

    $totalCost = $herbicideCost + $waterCost + $uavLabourCost;

    $herbicideRequiredGrams = $herbicideGramsPerTree * $treatmentTrees;
    $waterRequiredLitres = $waterLitresPerTree * $treatmentTrees;
    $uavLabourHours = $uavHoursPerTree * $treatmentTrees;

    return [
        'treatment_trees' => $treatmentTrees,
        'equivalent_area_ha' => $equivalentArea,

        'herbicide_name' => $rate['herbicide_name'],
        'product_reference' => $rate['product_reference'],
        'application_rate' => $rate['application_rate'],

        'herbicide_cost_per_hectare' =>
            (float)$rate['herbicide_cost_per_hectare'],
        'herbicide_cost_per_tree' => $herbicideCostPerTree,

        'herbicide_grams_per_hectare' =>
            (float)$rate['rate_grams_per_hectare'],
        'herbicide_grams_per_tree' => $herbicideGramsPerTree,

        'water_litres_per_hectare' =>
            TREATMENT_WATER_LITRES_PER_HECTARE,
        'water_litres_per_tree' => $waterLitresPerTree,
        'water_cost_per_hectare' =>
            TREATMENT_WATER_COST_PER_HECTARE,
        'water_cost_per_tree' => $waterCostPerTree,

        'uav_hours_per_hectare' =>
            TREATMENT_UAV_HOURS_PER_HECTARE,
        'uav_hours_per_tree' => $uavHoursPerTree,
        'uav_labour_cost_per_hectare' =>
            TREATMENT_UAV_LABOUR_COST_PER_HECTARE,
        'uav_labour_cost_per_tree' => $uavLabourCostPerTree,

        'herbicide_required_grams' => $herbicideRequiredGrams,
        'water_required_litres' => $waterRequiredLitres,
        'uav_labour_hours' => $uavLabourHours,

        'herbicide_cost' => $herbicideCost,
        'water_cost' => $waterCost,
        'uav_labour_cost' => $uavLabourCost,
        'total_cost' => $totalCost,

        'priority' => determineZonePriority($treatmentTrees),
    ];
}


/* =========================================================
   7. RECOMMENDATION
========================================================= */

function generateZoneTreatmentRecommendation(
    string $zoneId,
    int $treatmentTrees,
    int $analysedTrees,
    float $averageCoverage
): string {
    if ($treatmentTrees <= 0) {
        return
            $zoneId
            . ' remains under routine monitoring. '
            . 'No analysed tree currently meets the 5% treatment threshold.';
    }

    $rate = $analysedTrees > 0
        ? ($treatmentTrees / $analysedTrees) * 100
        : 0.00;

    return
        $zoneId
        . ' has '
        . $treatmentTrees
        . ' tree(s) requiring treatment ('
        . number_format($rate, 1)
        . '% of analysed trees). '
        . 'Use the selected herbicide plan and verify field conditions before UAV treatment.';
}


/* =========================================================
   8. FORMAT HELPERS
========================================================= */

function treatmentFormatMoney(float $amount): string
{
    return 'RM ' . number_format(max(0.00, $amount), 2);
}

function treatmentFormatArea(float $areaHectares): string
{
    return number_format(max(0.00, $areaHectares), 3) . ' ha';
}

function treatmentFormatCoverage(float $coverage): string
{
    return number_format(normaliseTreatmentCoverage($coverage), 2) . '%';
}

function treatmentFormatHours(float $hours): string
{
    return number_format(max(0.00, $hours), 3) . ' hr';
}

function treatmentFormatLitres(float $litres): string
{
    return number_format(max(0.00, $litres), 2) . ' L';
}

function treatmentFormatGrams(float $grams): string
{
    return number_format(max(0.00, $grams), 2) . ' g';
}