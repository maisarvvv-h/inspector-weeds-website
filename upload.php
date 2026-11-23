<?php
$uploadRequestStartedAt = microtime(true);
session_start();

$isUploadPostRequest = ($_SERVER["REQUEST_METHOD"] ?? "") === "POST";
$isAjaxUploadRequest = $isUploadPostRequest &&
    (string) ($_POST["ajax_upload"] ?? "") === "1";
$isLocalDevelopment = in_array(
    strtolower((string) ($_SERVER["SERVER_NAME"] ?? "")),
    ["localhost", "127.0.0.1", "::1"],
    true
) && in_array(
    strtolower((string) ($_SERVER["REMOTE_ADDR"] ?? "")),
    ["127.0.0.1", "::1"],
    true
);
$uploadTimingEnabled = $isUploadPostRequest && $isLocalDevelopment;

if ($uploadTimingEnabled) {
    // Keep local sequential batches bounded without extending production requests.
    set_time_limit(600);

    register_shutdown_function(
        static function () use ($uploadRequestStartedAt): void {
            $lastError = error_get_last();
            $fatalError = "";

            if (
                $lastError !== null &&
                in_array(
                    $lastError["type"],
                    [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                    true
                )
            ) {
                $fatalMessage = str_replace(
                    ["\r", "\n"],
                    " ",
                    (string) $lastError["message"]
                );
                $fatalError = " fatal=" . $fatalMessage;
            }

            error_log(
                sprintf(
                    "Inspector Weeds upload request duration_seconds=%.3f%s",
                    microtime(true) - $uploadRequestStartedAt,
                    $fatalError
                )
            );
        }
    );
}

require_once "db_connect.php";

$message = "";
$message_type = "";
$redirectAfterPost = false;
$completedImageIds = [];
$completionImageIds = [];
$uploadImageTotal = 0;
$uploadPreparationFailures = 0;

if (isset($_SESSION["flash_message"])) {
    $message = $_SESSION["flash_message"];
    $message_type = $_SESSION["flash_type"] ?? "success";
    unset($_SESSION["flash_message"], $_SESSION["flash_type"]);
}

if (isset($_SESSION["upload_completion_image_ids"])) {
    if (is_array($_SESSION["upload_completion_image_ids"])) {
        foreach ($_SESSION["upload_completion_image_ids"] as $imageId) {
            if (is_string($imageId) && $imageId !== "") {
                $completionImageIds[] = $imageId;
            }
        }
    }

    unset($_SESSION["upload_completion_image_ids"]);
}

$uploadProgressFormToken = "";
if (!$isUploadPostRequest) {
    $formTokens = $_SESSION["upload_progress_form_tokens"] ?? [];
    if (!is_array($formTokens)) {
        $formTokens = [];
    }

    $tokenExpiry = time() - 3600;
    foreach ($formTokens as $token => $createdAt) {
        if (!is_int($createdAt) || $createdAt < $tokenExpiry) {
            unset($formTokens[$token]);
        }
    }

    $uploadProgressFormToken = bin2hex(random_bytes(16));
    $formTokens[$uploadProgressFormToken] = time();
    $_SESSION["upload_progress_form_tokens"] = $formTokens;
}

$uploadProgressToken = "";
if ($isAjaxUploadRequest) {
    $uploadProgressToken = trim(
        (string) ($_POST["upload_progress_token"] ?? "")
    );
    $formTokens = $_SESSION["upload_progress_form_tokens"] ?? [];

    if (
        preg_match('/^[a-f0-9]{32}$/', $uploadProgressToken) !== 1 ||
        !is_array($formTokens) ||
        !isset($formTokens[$uploadProgressToken])
    ) {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => "error",
            "message" => "The upload session expired. Refresh the page and try again."
        ]);
        exit();
    }

    unset($_SESSION["upload_progress_form_tokens"][$uploadProgressToken]);
    storeUploadProgress($uploadProgressToken, [
        "status" => "preparing",
        "current" => 0,
        "total" => 0,
        "success_count" => 0,
        "failed_count" => 0,
        "message" => ""
    ]);
}

/**
 * Return the final valid JSON object printed by the Python program.
 */
function extractPythonJson(array $outputLines): ?array
{
    for ($index = count($outputLines) - 1; $index >= 0; $index--) {
        $line = trim($outputLines[$index]);

        if ($line === "") {
            continue;
        }

        $decoded = json_decode($line, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function setFlashMessage(string $message, string $type = "success"): void
{
    $_SESSION["flash_message"] = $message;
    $_SESSION["flash_type"] = $type;
}

function redirectToUpload(): void
{
    header("Location: upload.php");
    exit();
}

function storeUploadProgress(string $token, array $progress): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException("Could not reopen the upload session.");
    }

    if (!isset($_SESSION["upload_progress"]) || !is_array($_SESSION["upload_progress"])) {
        $_SESSION["upload_progress"] = [];
    }

    $progress["updated_at"] = time();
    $_SESSION["upload_progress"][$token] = $progress;

    foreach ($_SESSION["upload_progress"] as $existingToken => $existingProgress) {
        if (
            $existingToken !== $token &&
            is_array($existingProgress) &&
            (int) ($existingProgress["updated_at"] ?? 0) < time() - 3600
        ) {
            unset($_SESSION["upload_progress"][$existingToken]);
        }
    }

    if (!session_write_close()) {
        throw new RuntimeException("Could not save upload progress.");
    }
}

/**
 * Delete an uploaded image and its related analysis records.
 */
function deleteUploadedImage(mysqli $conn, string $imageId): array
{
    if ($imageId === "") {
        return [
            "success" => false,
            "message" => "Invalid image ID."
        ];
    }

    // Get image paths before deleting the database record
    $getImage = mysqli_prepare(
        $conn,
        "
        SELECT
            image_file,
            original_image_path,
            processed_image_path,
            mask_image_path
        FROM images
        WHERE image_id = ?
        LIMIT 1
        "
    );

    if (!$getImage) {
        return [
            "success" => false,
            "message" => "Could not prepare image lookup."
        ];
    }

    mysqli_stmt_bind_param($getImage, "s", $imageId);
    mysqli_stmt_execute($getImage);

    $result = mysqli_stmt_get_result($getImage);
    $image = mysqli_fetch_assoc($result);

    mysqli_stmt_close($getImage);

    if (!$image) {
        return [
            "success" => false,
            "message" => "Image could not be found."
        ];
    }

    /*
     * Delete related detection result first.
     */
    $deleteResult = mysqli_prepare(
        $conn,
        "DELETE FROM detection_results WHERE image_id = ?"
    );

    if ($deleteResult) {
        mysqli_stmt_bind_param($deleteResult, "s", $imageId);
        mysqli_stmt_execute($deleteResult);
        mysqli_stmt_close($deleteResult);
    }

    /*
     * Delete the image record from MySQL.
     */
    $deleteImage = mysqli_prepare(
        $conn,
        "DELETE FROM images WHERE image_id = ?"
    );

    if (!$deleteImage) {
        return [
            "success" => false,
            "message" => "Could not prepare image deletion."
        ];
    }

    mysqli_stmt_bind_param($deleteImage, "s", $imageId);

    if (!mysqli_stmt_execute($deleteImage)) {
        $error = mysqli_stmt_error($deleteImage);
        mysqli_stmt_close($deleteImage);
        error_log("Inspector Weeds image deletion failed for {$imageId}: " . $error);

        return [
            "success" => false,
            "message" => "The image could not be deleted. Please try again."
        ];
    }

    mysqli_stmt_close($deleteImage);

    /*
     * Delete physical files from the server.
     */
    $filesToDelete = [
        $image["original_image_path"],
        $image["processed_image_path"],
        $image["mask_image_path"]
    ];

    foreach ($filesToDelete as $filePath) {

        if (empty($filePath)) {
            continue;
        }

        /*
         * Convert database path such as:
         * uploads/example.jpg
         *
         * into:
         * C:/xampp/htdocs/inspector-weeds/uploads/example.jpg
         */
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR .
            str_replace(
                ["/", "\\"],
                DIRECTORY_SEPARATOR,
                ltrim($filePath, "/\\")
            );

        if (file_exists($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    return [
        "success" => true,
        "message" => "Image deleted successfully."
    ];
}

/**
 * Detect Zone A-E from names such as:
 * A37.jpg, A_37.jpg, Zone_A_37.jpg, Zone-A-37.jpg.
 */
function detectZoneFromFilename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);

    if (preg_match('/(?:^|[_\-\s])ZONE[_\-\s]*([A-E])(?:[_\-\s]|$)/i', $name, $match)) {
        return "Zone " . strtoupper($match[1]);
    }

    if (preg_match('/^([A-E])(?:[_\-\s]|\d)/i', $name, $match)) {
        return "Zone " . strtoupper($match[1]);
    }

    return "Unknown";
}
/**
 * Process one uploaded image.
 */
function processImage(array $file, mysqli $conn): array
{
    return [
        "success" => true,
        "file" => $file
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
     * DELETE IMAGE
     */
    if (isset($_POST["delete_image_id"])) {

        $deleteImageId = trim($_POST["delete_image_id"]);

        $deleteResult = deleteUploadedImage(
            $conn,
            $deleteImageId
        );

        setFlashMessage(
            $deleteResult["message"],
            $deleteResult["success"] ? "success" : "error"
        );

        redirectToUpload();
    }


    /*
     * NORMAL IMAGE UPLOAD
     */
    $uploadedFiles = [];

    if (
        !isset($_FILES["plantation_image"]) ||
        $_FILES["plantation_image"]["error"] === UPLOAD_ERR_NO_FILE
    ) {
        $message = "Please select a plantation image.";
        $message_type = "error";
    } else {
      
    $uploadedFiles = [];

$totalFiles = count($_FILES["plantation_image"]["name"]);

for ($i = 0; $i < $totalFiles; $i++) {

    $uploadedFiles[] = [

        "name" => $_FILES["plantation_image"]["name"][$i],

        "type" => $_FILES["plantation_image"]["type"][$i],

        "tmp_name" => $_FILES["plantation_image"]["tmp_name"][$i],

        "error" => $_FILES["plantation_image"]["error"][$i],

        "size" => $_FILES["plantation_image"]["size"][$i]

    ];

}

    $tempZipFolder = __DIR__ . "/temp_zip/";

if (!file_exists($tempZipFolder)) {
    mkdir($tempZipFolder, 0777, true);
}

$newUploadedFiles = [];


foreach ($uploadedFiles as $file) {

    $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    if ($extension === "zip") {
        if (!class_exists("ZipArchive")) {
            $uploadPreparationFailures++;
            $message = "ZIP uploads are unavailable because PHP ZIP support is not enabled. Upload images individually.";
            $message_type = "error";
            continue;
        }

        $zip = new ZipArchive();

        if ($zip->open($file["tmp_name"]) === TRUE) {

            $extractFolder = $tempZipFolder . uniqid() . "/";

            mkdir($extractFolder);

            $zip->extractTo($extractFolder);

            $zip->close();

            $images = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $extractFolder,
        RecursiveDirectoryIterator::SKIP_DOTS
    )
);

foreach ($iterator as $image) {

    if (!$image->isFile()) {
        continue;
    }

    $extension = strtolower($image->getExtension());

    if (in_array($extension, ["jpg","jpeg","png","webp"])) {

        $images[] = $image->getPathname();

    }

}

            foreach ($images as $image) {

                $newUploadedFiles[] = [

                    "name" => basename($image),

                    "tmp_name" => $image,

                    "error" => 0,

                    "size" => filesize($image),

                    "type" => mime_content_type($image)

                ];

            }

        } else {
            $uploadPreparationFailures++;
            $message = "A ZIP file could not be opened. Please check it and try again.";
            $message_type = "error";
        }

    } else {

        $newUploadedFiles[] = $file;

    }

}

if ($newUploadedFiles === [] && $uploadPreparationFailures === 0) {
    $message = "No supported images were found. Please upload JPG, PNG or WEBP files.";
    $message_type = "error";
}

$uploadedFiles = $newUploadedFiles;
$uploadImageTotal = count($uploadedFiles);

if ($isAjaxUploadRequest) {
    storeUploadProgress($uploadProgressToken, [
        "status" => "preparing",
        "current" => 0,
        "total" => $uploadImageTotal,
        "success_count" => 0,
        "failed_count" => 0,
        "message" => ""
    ]);
}

}
foreach ($uploadedFiles as $imageIndex => $file) {
    if ($isAjaxUploadRequest) {
        storeUploadProgress($uploadProgressToken, [
            "status" => "processing",
            "current" => $imageIndex + 1,
            "total" => $uploadImageTotal,
            "success_count" => count($completedImageIds),
            "failed_count" => max(0, $imageIndex - count($completedImageIds)),
            "message" => ""
        ]);
    }

    $imageProcessingStartedAt = microtime(true);

    if ($file["error"] !== UPLOAD_ERR_OK) {
            $message = "The image upload failed. Please try again.";
            $message_type = "error";
        } else {
            $allowedTypes = [
                "image/jpeg" => "jpg",
                "image/png" => "png",
                "image/webp" => "webp"
            ];

            $maximumSize = 10 * 1024 * 1024;

            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $file["tmp_name"]);
            finfo_close($fileInfo);

            if (!array_key_exists($mimeType, $allowedTypes)) {
                $message = "Only JPG, PNG and WEBP files are allowed.";
                $message_type = "error";
            } elseif ($file["size"] > $maximumSize) {
                $message = "The image must be smaller than 10 MB.";
                $message_type = "error";
            } else {
                $extension = $allowedTypes[$mimeType];
                $originalName = pathinfo(basename($file["name"]), PATHINFO_FILENAME);
                $safeName = preg_replace("/[^A-Za-z0-9_-]/", "_", $originalName);
                $safeName = trim($safeName, "_-");

                if ($safeName === "") {
                    $safeName = "plantation_image";
                }

                // The previous file used $unique_code without creating it.
                $uniqueCode = date("YmdHis") . "_" . bin2hex(random_bytes(4));
                $maximumImageIdLength = 120;
                $maximumSafeNameLength =
                    $maximumImageIdLength - strlen($uniqueCode) - 1;
                $safeName = substr($safeName, 0, $maximumSafeNameLength);

                $imageId = $safeName . "_" . $uniqueCode;
                $fileName = $imageId . "." . $extension;

                $zoneId = detectZoneFromFilename($file["name"]);

                $uploadFolder = __DIR__ . DIRECTORY_SEPARATOR . "uploads";
                $absoluteFilePath = $uploadFolder . DIRECTORY_SEPARATOR . $fileName;
                $databaseFilePath = "uploads/" . $fileName;

                if (!is_dir($uploadFolder) && !mkdir($uploadFolder, 0775, true)) {
                    $message = "Images could not be saved. Please try again.";
                    $message_type = "error";
                } else {

    $fileMoved = false;

    // Normal uploaded file
    if (is_uploaded_file($file["tmp_name"])) {

        $fileMoved = move_uploaded_file(
            $file["tmp_name"],
            $absoluteFilePath
        );

    }
    // File extracted from ZIP
    else {

        $fileMoved = copy(
            $file["tmp_name"],
            $absoluteFilePath
        );

    }

    if (!$fileMoved) {

        $message = "The image could not be saved. Please try again.";

        $message_type = "error";

    } else {
                    $imageRecordInsertStartedAt = microtime(true);
                    $insertImage = mysqli_prepare(
                        $conn,
                        "
                        INSERT INTO images (
                            image_id,
                            image_file,
                            original_image_path,
                            processed_image_path,
                            zone_id,
                            model_used,
                            analysis_status
                        ) VALUES (?, ?, ?, NULL, ?, 'DeepLabV3+', 'Processing')
                        "
                    );

                    if (!$insertImage) {
                        @unlink($absoluteFilePath);
                        error_log(
                            "Inspector Weeds image insert preparation failed: " .
                            mysqli_error($conn)
                        );
                        $message = "The image could not be saved. Please try again.";
                        $message_type = "error";
                    } else {
                        $imageInsertError = "";
                        try {
                            if (!mysqli_stmt_bind_param(
                                $insertImage,
                                "ssss",
                                $imageId,
                                $fileName,
                                $databaseFilePath,
                                $zoneId
                            )) {
                                $imageInsertError = mysqli_stmt_error($insertImage);
                            } elseif (!mysqli_stmt_execute($insertImage)) {
                                $imageInsertError = mysqli_stmt_error($insertImage);
                            }
                        } catch (Throwable $error) {
                            $imageInsertError = $error->getMessage();
                        }

                        if ($imageInsertError !== "") {
                            @unlink($absoluteFilePath);
                            error_log(
                                "Inspector Weeds image insert failed for {$imageId}: " .
                                $imageInsertError
                            );
                            $message = "The image could not be saved. Please try again.";
                            $message_type = "error";
                        } else {
                            $imageRecordInsertSeconds =
                                microtime(true) - $imageRecordInsertStartedAt;
                            if ($uploadTimingEnabled) {
                                error_log(
                                    sprintf(
                                        "Inspector Weeds upload timing image=%s database_image_insert_seconds=%.3f",
                                        $imageId,
                                        $imageRecordInsertSeconds
                                    )
                                );
                            }

                            $pythonPath = __DIR__ . DIRECTORY_SEPARATOR . "venv"
                                . DIRECTORY_SEPARATOR . "Scripts"
                                . DIRECTORY_SEPARATOR . "python.exe";

                            $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . "run_inference_deeplab.py";
                            $pythonOutput = [];
                            $pythonReturnCode = 1;
                            $analysis = null;

                            if (!file_exists($pythonPath)) {
                                error_log(
                                    "Inspector Weeds Python executable not found: " .
                                    $pythonPath
                                );
                                $message = "Image analysis is unavailable. Please try again later.";
                                $message_type = "error";
                            } elseif (!file_exists($scriptPath)) {
                                error_log(
                                    "Inspector Weeds inference script not found: " .
                                    $scriptPath
                                );
                                $message = "Image analysis could not be started. Please try again later.";
                                $message_type = "error";
                            } else {
                                $command =
                                    escapeshellarg($pythonPath) . " " .
                                    escapeshellarg($scriptPath) . " " .
                                    escapeshellarg($absoluteFilePath) .
                                    " 2>&1";

                                $pythonOutput = [];
                                $pythonReturnCode = 0;

                                $pythonStartedAt = microtime(true);
                                exec($command, $pythonOutput, $pythonReturnCode);
                                $pythonProcessSeconds =
                                    microtime(true) - $pythonStartedAt;

                                $pythonTimings = [];
                                foreach ($pythonOutput as $pythonOutputLine) {
                                    if (
                                        preg_match(
                                            '/^TIMING ([a-z_]+)=([0-9]+(?:\.[0-9]+)?)$/',
                                            trim($pythonOutputLine),
                                            $timingMatches
                                        ) === 1
                                    ) {
                                        $pythonTimings[$timingMatches[1]] =
                                            (float) $timingMatches[2];
                                    }
                                }

                                $modelInitializationSeconds =
                                    isset($pythonTimings["model_init_seconds"])
                                        ? number_format(
                                            $pythonTimings["model_init_seconds"],
                                            3,
                                            ".",
                                            ""
                                        )
                                        : "not_reported";
                                $dependencyImportSeconds =
                                    isset($pythonTimings["dependency_import_seconds"])
                                        ? number_format(
                                            $pythonTimings["dependency_import_seconds"],
                                            3,
                                            ".",
                                            ""
                                        )
                                        : "not_reported";
                                $inferenceSeconds =
                                    isset($pythonTimings["inference_seconds"])
                                        ? number_format(
                                            $pythonTimings["inference_seconds"],
                                            3,
                                            ".",
                                            ""
                                        )
                                        : "not_reported";

                                if ($uploadTimingEnabled) {
                                    error_log(
                                        sprintf(
                                            "Inspector Weeds upload timing image=%s python_process_seconds=%.3f dependency_import_seconds=%s model_init_seconds=%s inference_seconds=%s",
                                            $imageId,
                                            $pythonProcessSeconds,
                                            $dependencyImportSeconds,
                                            $modelInitializationSeconds,
                                            $inferenceSeconds
                                        )
                                    );
                                }

                                $analysis = extractPythonJson($pythonOutput);
                            }

                            if (
                                $pythonReturnCode !== 0 ||
                                !is_array($analysis) ||
                                ($analysis["status"] ?? "") !== "success"
                            ) {
                                $errorDetails = implode("\n", $pythonOutput ?? []);
                                if ($errorDetails !== "") {
                                    error_log(
                                        "Inspector Weeds inference failed for {$imageId}: " .
                                        $errorDetails
                                    );
                                }
                                error_log(
                                    "Inspector Weeds inference did not complete for {$imageId}; " .
                                    "Python return code: {$pythonReturnCode}."
                                );

                                $failedStatus = mysqli_prepare(
                                    $conn,
                                    "UPDATE images SET analysis_status = 'Failed' WHERE image_id = ?"
                                );

                                if ($failedStatus) {
                                    mysqli_stmt_bind_param($failedStatus, "s", $imageId);
                                    mysqli_stmt_execute($failedStatus);
                                    mysqli_stmt_close($failedStatus);
                                }

                                if ($message === "") {
                                    $message = "Image analysis did not complete. Please try again.";
                                    $message_type = "error";
                                }
                            } else {
                                $processedImagePath = (string)($analysis["processed_image_path"] ?? "");
                                $maskImagePath = (string)($analysis["mask_path"] ?? "");
                                $modelUsed = (string)($analysis["model_used"] ?? "DeepLabV3+");

                                $severityRaw = strtoupper((string)($analysis["severity_level"] ?? "LOW"));
                                $severityMap = [
                                    "LOW" => "Low",
                                    "MODERATE" => "Moderate",
                                    "MEDIUM" => "Moderate",
                                    "HIGH" => "High"
                                ];
                                $severity = $severityMap[$severityRaw] ?? "Low";

                                $weedCoverage = (float)($analysis["weed_coverage"] ?? 0);
                                $weedPixels = (int)($analysis["weed_pixels"] ?? 0);
                                $totalPixels = (int)($analysis["total_pixels"] ?? 0);
                                $averageConfidence = (float)($analysis["confidence_score"] ?? 0);

                                // Keep the filename-derived zone because uploaded filenames
                                // receive a generated suffix before Python runs.
                                if ($zoneId === "Unknown") {
                                    $pythonZone = (string)($analysis["zone"] ?? "Unknown");
                                    if ($pythonZone !== "Unknown") {
                                        $zoneId = $pythonZone;
                                    }
                                }

                                $resultSaveStartedAt = microtime(true);
                                mysqli_begin_transaction($conn);

                                try {
                                    $updateImage = mysqli_prepare(
                                        $conn,
                                        "
                                        UPDATE images
                                        SET processed_image_path = ?,
                                            mask_image_path = ?,
                                            zone_id = ?,
                                            model_used = ?,
                                            analysis_status = 'Completed'
                                        WHERE image_id = ?
                                        "
                                    );

                                    if (!$updateImage) {
                                        throw new Exception(mysqli_error($conn));
                                    }

                                    mysqli_stmt_bind_param(
                                        $updateImage,
                                        "sssss",
                                        $processedImagePath,
                                        $maskImagePath,
                                        $zoneId,
                                        $modelUsed,
                                        $imageId
                                    );

                                    if (!mysqli_stmt_execute($updateImage)) {
                                        throw new Exception(mysqli_stmt_error($updateImage));
                                    }

                                    mysqli_stmt_close($updateImage);

                                    $insertResult = mysqli_prepare(
                                        $conn,
                                        "
                                        INSERT INTO detection_results (
                                            image_id,
                                            image_file,
                                            zone_id,
                                            severity_level,
                                            weed_coverage,
                                            weed_pixels,
                                            total_pixels,
                                            average_confidence,
                                            processed_image_path,
                                            mask_image_path,
                                            model_used
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                        "
                                    );

                                    if (!$insertResult) {
                                        throw new Exception(mysqli_error($conn));
                                    }

                                    mysqli_stmt_bind_param(
                                        $insertResult,
                                        "ssssdiidsss",
                                        $imageId,
                                        $fileName,
                                        $zoneId,
                                        $severity,
                                        $weedCoverage,
                                        $weedPixels,
                                        $totalPixels,
                                        $averageConfidence,
                                        $processedImagePath,
                                        $maskImagePath,
                                        $modelUsed
                                    );

                                    if (!mysqli_stmt_execute($insertResult)) {
                                        throw new Exception(mysqli_stmt_error($insertResult));
                                    }

                                    mysqli_stmt_close($insertResult);
                                    mysqli_commit($conn);
                                    $resultSaveSeconds =
                                        microtime(true) - $resultSaveStartedAt;

                                    $completedImageIds[] = $imageId;
                                    $message = "Image uploaded and analysed successfully.";
                                    $message_type = "success";
                                    if ($uploadTimingEnabled) {
                                        error_log(
                                            sprintf(
                                                "Inspector Weeds upload timing image=%s database_result_save_seconds=%.3f database_total_seconds=%.3f image_total_seconds=%.3f status=Completed",
                                                $imageId,
                                                $resultSaveSeconds,
                                                $imageRecordInsertSeconds + $resultSaveSeconds,
                                                microtime(true) - $imageProcessingStartedAt
                                            )
                                        );
                                    }
                                } catch (Throwable $error) {
                                    mysqli_rollback($conn);
                                    $saveErrorMessage =
                                        "Inspector Weeds analysis result save failed for {$imageId}: " .
                                        $error->getMessage();
                                    if ($uploadTimingEnabled) {
                                        $saveErrorMessage .= sprintf(
                                            " (database_result_save_seconds=%.3f)",
                                            microtime(true) - $resultSaveStartedAt
                                        );
                                    }
                                    error_log($saveErrorMessage);

                                    $failedStatus = mysqli_prepare(
                                        $conn,
                                        "UPDATE images SET analysis_status = 'Failed' WHERE image_id = ?"
                                    );

                                    if ($failedStatus) {
                                        mysqli_stmt_bind_param($failedStatus, "s", $imageId);
                                        mysqli_stmt_execute($failedStatus);
                                        mysqli_stmt_close($failedStatus);
                                    }

                                    $message = "Analysis completed, but the results could not be saved. Please try again.";
                                    $message_type = "error";
                                }
                            }
                        }

                        mysqli_stmt_close($insertImage);

                        $redirectAfterPost = true;
                    }
                }
            }
        }
    }
}

$uploadProgressMessage = "";
if ($redirectAfterPost || $isAjaxUploadRequest) {
    if (
        $isAjaxUploadRequest &&
        session_status() !== PHP_SESSION_ACTIVE &&
        !session_start()
    ) {
        throw new RuntimeException("Could not save the upload results.");
    }

    $successCount = count($completedImageIds);
    $failedImageCount = max(0, $uploadImageTotal - $successCount);
    $failedCount = $failedImageCount + $uploadPreparationFailures;

    if ($uploadImageTotal > 0) {
        if ($failedImageCount > 0) {
            $uploadProgressMessage = sprintf(
                "%d of %d images analysed successfully. %d %s failed.",
                $successCount,
                $uploadImageTotal,
                $failedImageCount,
                $failedImageCount === 1 ? "image" : "images"
            );
        } else {
            $uploadProgressMessage = $uploadImageTotal === 1
                ? "Image analysed successfully."
                : sprintf("%d images analysed successfully.", $successCount);
        }
    }

    if ($uploadPreparationFailures > 0) {
        $zipFailureMessage = $uploadPreparationFailures === 1
            ? "1 ZIP file could not be processed."
            : sprintf("%d ZIP files could not be processed.", $uploadPreparationFailures);
        $uploadProgressMessage = $uploadProgressMessage === "" && $message !== ""
            ? $message
            : trim($uploadProgressMessage . " " . $zipFailureMessage);
    }

    if ($uploadProgressMessage === "") {
        $uploadProgressMessage = $message !== ""
            ? $message
            : "No supported images were found. Please upload JPG, PNG or WEBP files.";
    }

    $message = $uploadProgressMessage;
    $message_type = $failedCount > 0 || $successCount === 0
        ? "error"
        : "success";

    if ($completedImageIds !== []) {
        $_SESSION["upload_completion_image_ids"] = $completedImageIds;
        $_SESSION["latest_analysis_batch_ids"] = $completedImageIds;

        if ($message_type === "error") {
            setFlashMessage($message, $message_type);
        } else {
            unset($_SESSION["flash_message"], $_SESSION["flash_type"]);
        }
    } else {
        setFlashMessage($message, $message_type);
    }

    if ($isAjaxUploadRequest) {
        $progressStatus = $uploadImageTotal === 0 && $failedCount === 0
            ? "error"
            : "complete";
        $_SESSION["upload_progress"][$uploadProgressToken] = [
            "status" => $progressStatus,
            "current" => $uploadImageTotal,
            "total" => $uploadImageTotal,
            "success_count" => $successCount,
            "failed_count" => $failedCount,
            "message" => $uploadProgressMessage,
            "updated_at" => time()
        ];
        if (!session_write_close()) {
            throw new RuntimeException("Could not save the upload results.");
        }

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => $progressStatus,
            "current" => $uploadImageTotal,
            "total" => $uploadImageTotal,
            "success_count" => $successCount,
            "failed_count" => $failedCount,
            "message" => $uploadProgressMessage,
            "redirect" => "upload.php"
        ]);
        exit();
    }

    if ($redirectAfterPost) {
        redirectToUpload();
    }
}
}

$recent_uploads = mysqli_query(
    $conn,
    "
    SELECT image_id, image_file, original_image_path, zone_id,
           analysis_status, analysis_date
    FROM images
    ORDER BY analysis_date DESC
    LIMIT 4
    "
);
$completionCount = count($completionImageIds);
?>

<!DOCTYPE html>
<html lang="en">
 
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
 
    <title>Upload Images | Inspector Weeds</title>
 
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
 
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >
    <?php
    $stylePath = __DIR__ . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "style.css";
    $styleVersion = file_exists($stylePath) ? filemtime($stylePath) : time();
    ?>
    <link rel="stylesheet" href="./css/style.css?v=<?= $styleVersion ?>">
    <?php
    $uploadStylePath = __DIR__ . DIRECTORY_SEPARATOR . "css" . DIRECTORY_SEPARATOR . "upload.css";
    $uploadStyleVersion = file_exists($uploadStylePath) ? filemtime($uploadStylePath) : time();
    ?>
    <link rel="stylesheet" href="./css/upload.css?v=<?= $uploadStyleVersion ?>-saas-v16">

</head>
 
 
<body class="dashboard-body">
<?php require __DIR__ . "/includes/sidebar.php"; ?>
 
<main class="app-main upload-main">
 
    <header class="app-header">

    <div>
        <h1>Upload Images</h1>
        <p>Upload plantation images for weed analysis.</p>
    </div>
 
    </header>

    <?php if ($completionImageIds !== []): ?>
        <section class="analysis-complete-state" aria-live="polite">
            <div class="completion-copy">
                <h2>Analysis complete</h2>
                <p>
                    <?= $completionCount === 1
                        ? "1 image was successfully analysed."
                        : $completionCount . " images were successfully analysed." ?>
                </p>
            </div>
            <div class="completion-actions">
                <a
                    class="main-action-button completion-primary-action"
                    href="detection_results.php"
                >
                    <?= $completionCount === 1
                        ? "View Analysis Results"
                        : "View All Results" ?> <span aria-hidden="true">→</span>
                </a>
                <div class="completion-secondary-links">
                    <a href="dashboard.php">View Dashboard</a>
                    <a href="treatment_planning.php">Treatment Planning</a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
 
        <div class="page-alert <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
 
    <?php endif; ?>
 
    <section class="upload-page-grid">
 
        <section class="content-card upload-form-card">
 
            <form
                method="POST"
                enctype="multipart/form-data"
                id="uploadForm"
            >
                <input
                    type="hidden"
                    name="upload_progress_token"
                    value="<?= htmlspecialchars($uploadProgressFormToken) ?>"
                >

                <div
                    class="image-drop-area"
                    id="dropArea"
                >
 
                    <input
    type="file"
    name="plantation_image[]"
    id="plantation_image"
    aria-label="Choose plantation images or a ZIP file"
    accept=".jpg,.jpeg,.png,.webp,.zip,image/jpeg,image/png,image/webp,application/zip"
    multiple
    required
>
 
                    <div
                        class="upload-placeholder"
                        id="uploadPlaceholder"
                    >
 
                        <div class="upload-icon-circle" aria-hidden="true">+</div>
                        <h3>Choose plantation images or a ZIP file</h3>
                        <p>Select one or more files to begin.</p>
                        <span>JPG, PNG, WEBP or ZIP</span>
 
                    </div>
 
                    <div
                        class="selected-image-preview"
                        id="previewContainer"
                        hidden
                    >
 
                        <img
                            src=""
                            alt="Preview of the selected plantation image"
                            id="imagePreview"
                        >
 
                        <div class="selected-file-details">
 
                            <strong id="selectedFileName"></strong>
 
                            <span id="selectedFileSize"></span>
 
                        </div>
 
                        <button
                            type="button"
                            class="remove-file-button"
                            id="removeFileButton"
                        >
                                Clear selection
                        </button>
 
                    </div>
 
                </div>
 
                <button
                    type="submit"
                    class="main-action-button"
                    id="analyseButton"
                >
                    <span id="analyseButtonLabel">Analyse Images</span>
                    <span aria-hidden="true">→</span>
                </button>
                <p
                    id="uploadProgressMessage"
                    class="upload-progress-message"
                    role="status"
                    aria-live="polite"
                    hidden
                ></p>
 
            </form>
 
        </section>
 
        <aside class="content-card recent-upload-section recent-upload-panel">
 
        <div class="recent-upload-heading">
 
            <div>
                <h2>Recent Uploads</h2>
            </div>
 
            <a
                href="detection_results.php"
                class="simple-link"
            >
                Analysis Results →
            </a>
 
        </div>
 
        <div class="recent-upload-grid">
 
            <?php
            if (
                $recent_uploads &&
                mysqli_num_rows($recent_uploads) > 0
            ):
            ?>
 
                <?php
                while (
                    $upload = mysqli_fetch_assoc($recent_uploads)
                ):
                ?>
 
                    <article class="recent-upload-card">
 
                        <img
                            src="<?=
                            htmlspecialchars(
                                $upload["original_image_path"]
                            )
                            ?>"
                            alt="Recently uploaded palm plantation image"
                        >
 
                        <div class="recent-upload-content">
 
                            <div class="recent-upload-title">
                                <span class="zone-label">
                                    <?=
                                    htmlspecialchars(
                                        $upload["zone_id"]
                                    )
                                    ?>
                                </span>
 
                            </div>
 
                            <p>
                                Uploaded:
                                <?=
                                date(
                                    "d M Y, h:i A",
                                    strtotime(
                                        $upload["analysis_date"]
                                    )
                                )
                                ?>
                            </p>
 
                            <span class="upload-status">
                                <?= htmlspecialchars($upload["analysis_status"]) ?>
                            </span>

                            <?php if ($upload["analysis_status"] === "Completed"): ?>
                                <a
                                    class="recent-result-link"
                                    href="detection_results.php?image_id=<?= urlencode(
                                        (string) $upload["image_id"]
                                    ) ?>"
                                >
                                    View analysis <span aria-hidden="true">→</span>
                                </a>
                            <?php endif; ?>

<form
    method="POST"
    class="delete-image-form"
    onsubmit="return confirmDeleteImage(
        '<?= htmlspecialchars($upload["image_file"], ENT_QUOTES) ?>'
    );"
>
    <input
        type="hidden"
        name="delete_image_id"
        value="<?= htmlspecialchars($upload["image_id"]) ?>"
    >

    <button
        type="submit"
        class="delete-image-button"
    >
        Delete
    </button>
</form>
 
                        </div>
 
                    </article>
 
                <?php endwhile; ?>
 
            <?php else: ?>
 
                <div class="no-upload-state">
                    <h3>No recent uploads</h3>
                    <p>Your analysed images will appear here.</p>
                </div>
 
            <?php endif; ?>
 
        </div>
 
        </aside>

    </section>
 
</main>
 
<script>
const fileInput = document.getElementById("plantation_image");
const dropArea = document.getElementById("dropArea");
const placeholder = document.getElementById("uploadPlaceholder");
const previewContainer = document.getElementById("previewContainer");
const imagePreview = document.getElementById("imagePreview");
const selectedFileName = document.getElementById("selectedFileName");
const selectedFileSize = document.getElementById("selectedFileSize");
const removeFileButton = document.getElementById("removeFileButton");
 
function showImagePreview(file, selectionCount = 1) {
    if (!file) {
        return;
    }
 
   const acceptedTypes = [
    "image/jpeg",
    "image/png",
    "image/webp",
    "application/zip",
    "application/x-zip-compressed"
];
 
    if (!acceptedTypes.includes(file.type)) {
        alert("Please select a JPG, PNG or WEBP image.");
        fileInput.value = "";
        return;
    }
 
    if (file.size > 10 * 1024 * 1024) {
        alert("The selected image must be smaller than 10 MB.");
        fileInput.value = "";
        return;
    }
 
    const reader = new FileReader();
 
    reader.onload = function (event) {
        imagePreview.src = event.target.result;
        selectedFileName.textContent = selectionCount > 1
            ? selectionCount + " files selected"
            : file.name;
        selectedFileSize.textContent = selectionCount > 1
            ? "Images and ZIP files"
            : (file.size / 1024 / 1024).toFixed(2) + " MB";
 
        placeholder.hidden = true;
        previewContainer.hidden = false;
        dropArea.classList.add("contains-image");
    };
 
    reader.readAsDataURL(file);
}
 
fileInput.addEventListener("change", function () {
    showSelectedFiles(this.files);
});
 
function showSelectedFiles(files) {
    if (!files || files.length === 0) {
        return;
    }

    const filesArray = Array.from(files);

    if (
        filesArray.length === 1 &&
        filesArray[0].name.toLowerCase().endsWith(".zip")
    ) {
        placeholder.hidden = true;
        previewContainer.hidden = false;
        imagePreview.style.display = "none";
        selectedFileName.textContent = filesArray[0].name;
        selectedFileSize.textContent =
            "ZIP file · " +
            (filesArray[0].size / 1024 / 1024).toFixed(2) +
            " MB";
        dropArea.classList.add("contains-image");
        return;
    }

    const firstImage = filesArray.find(function (file) {
        return ["image/jpeg", "image/png", "image/webp"].includes(file.type);
    });

    if (firstImage) {
        imagePreview.style.display = "";
        showImagePreview(firstImage, filesArray.length);
    } else {
        placeholder.hidden = true;
        previewContainer.hidden = false;
        imagePreview.style.display = "none";
    }

    selectedFileName.textContent = filesArray.length === 1
        ? filesArray[0].name
        : filesArray.length + " files selected";

    if (filesArray.length > 1) {
        selectedFileSize.textContent = "Images and ZIP files";
    }
}

dropArea.addEventListener("dragover", function (event) {
    event.preventDefault();
    dropArea.classList.add("drag-active");
});
 
dropArea.addEventListener("dragleave", function () {
    dropArea.classList.remove("drag-active");
});
 
dropArea.addEventListener("drop", function (event) {
    event.preventDefault();
    dropArea.classList.remove("drag-active");
 
    if (event.dataTransfer.files.length > 0) {
        const fileTransfer = new DataTransfer();

        Array.from(event.dataTransfer.files).forEach(function (file) {
            fileTransfer.items.add(file);
        });

        fileInput.files = fileTransfer.files;
        showSelectedFiles(fileInput.files);
    }
});
 
removeFileButton.addEventListener("click", function () {
    fileInput.value = "";
    imagePreview.src = "";
    previewContainer.hidden = true;
    placeholder.hidden = false;
    imagePreview.style.display = "";
 
    dropArea.classList.remove("contains-image");
});

const uploadForm = document.getElementById("uploadForm");
const analyseButton = document.getElementById("analyseButton");
const analyseButtonLabel = document.getElementById("analyseButtonLabel");
const uploadProgressMessage = document.getElementById("uploadProgressMessage");
const progressToken = uploadForm.querySelector(
    'input[name="upload_progress_token"]'
).value;

let analysisSubmitted = false;
let progressPollTimer = null;
let progressPollFailures = 0;
let completionRedirectScheduled = false;

function showUploadProgress(message) {
    uploadProgressMessage.textContent = message;
    uploadProgressMessage.hidden = false;
}

function stopUploadProgressPolling() {
    if (progressPollTimer !== null) {
        clearTimeout(progressPollTimer);
        progressPollTimer = null;
    }
}

function finishUpload(data) {
    if (completionRedirectScheduled) {
        return;
    }

    completionRedirectScheduled = true;
    analysisSubmitted = false;
    stopUploadProgressPolling();
    showUploadProgress(data.message || "Analysis complete.");
    analyseButtonLabel.textContent = "Analysis complete";
    window.setTimeout(function () {
        window.location.assign(data.redirect || "upload.php");
    }, 850);
}

function applyUploadProgress(state) {
    if (state.status === "complete" || state.status === "error") {
        finishUpload({
            message: state.message,
            redirect: "upload.php"
        });
        return;
    }

    if (state.status === "processing" && Number(state.total) > 0) {
        const total = Number(state.total);
        const current = Math.min(Number(state.current), total);
        const message = total === 1
            ? "Analysing image…"
            : `Analysing ${current} of ${total} images…`;
        showUploadProgress(message);
        analyseButtonLabel.textContent = "Analysing…";
        progressPollFailures = 0;
        return;
    }

    if (state.status === "preparing") {
        showUploadProgress("Preparing images…");
        analyseButtonLabel.textContent = "Preparing…";
    }
}

async function pollUploadProgress() {
    if (!analysisSubmitted || completionRedirectScheduled) {
        return;
    }

    try {
        const response = await fetch(
            "upload_progress.php?token=" +
                encodeURIComponent(progressToken) +
                "&_=" +
                Date.now(),
            {
                credentials: "same-origin",
                cache: "no-store"
            }
        );

        if (!response.ok) {
            throw new Error("Progress request returned HTTP " + response.status);
        }

        const state = await response.json();
        applyUploadProgress(state);
    } catch (error) {
        progressPollFailures++;
        if (progressPollFailures >= 3) {
            showUploadProgress(
                "Processing continues; reconnecting to progress updates…"
            );
        }
    } finally {
        if (analysisSubmitted && !completionRedirectScheduled) {
            progressPollTimer = window.setTimeout(pollUploadProgress, 800);
        }
    }
}

uploadForm.addEventListener("submit", function (event) {
    if (analysisSubmitted) {
        event.preventDefault();
        return;
    }

    if (!fileInput.files || fileInput.files.length === 0) {
        event.preventDefault();
        alert("Please select a plantation image first.");
        return;
    }

    event.preventDefault();
    analysisSubmitted = true;
    analyseButton.disabled = true;
    analyseButton.classList.add("is-processing");
    analyseButtonLabel.textContent = "Preparing…";
    showUploadProgress("Uploading files…");
    uploadForm.setAttribute("aria-busy", "true");

    const formData = new FormData(uploadForm);
    formData.append("ajax_upload", "1");

    const request = new XMLHttpRequest();
    request.open("POST", uploadForm.action || window.location.href, true);
    request.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    request.upload.addEventListener("progress", function (progressEvent) {
        if (
            progressEvent.lengthComputable &&
            progressEvent.loaded >= progressEvent.total
        ) {
            showUploadProgress("Preparing images…");
        } else {
            showUploadProgress("Uploading files…");
        }
    });

    request.addEventListener("load", function () {
        let result;
        try {
            result = JSON.parse(request.responseText);
        } catch (error) {
            showUploadProgress(
                "The server could not confirm completion. Refresh the page to check before trying again."
            );
            return;
        }

        if (request.status >= 200 && request.status < 300 && result.redirect) {
            finishUpload(result);
            return;
        }

        analysisSubmitted = false;
        stopUploadProgressPolling();
        analyseButton.disabled = false;
        analyseButton.classList.remove("is-processing");
        analyseButtonLabel.textContent = "Analyse Images";
        uploadForm.setAttribute("aria-busy", "false");
        showUploadProgress(
            result.message || "The upload could not be started. Refresh and try again."
        );
    });

    request.addEventListener("error", function () {
        showUploadProgress(
            "The connection was interrupted. Checking whether processing is still active…"
        );
    });

    request.send(formData);
    pollUploadProgress();
});

function confirmDeleteImage(fileName) {

    return confirm(
        "Are you sure you want to delete this image?\n\n" +
        fileName +
        "\n\nThis will also delete its analysis result."
    );

}

</script>
 
</body>
</html>
