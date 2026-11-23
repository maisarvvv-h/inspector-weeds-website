<?php
$uploadRequestStartedAt = microtime(true);
session_start();

$isUploadPostRequest = ($_SERVER["REQUEST_METHOD"] ?? "") === "POST";
$isAjaxUploadRequest = $isUploadPostRequest &&
    (string) ($_POST["ajax_upload"] ?? "") === "1";
$expectedUploadCountHeader = trim(
    (string) ($_SERVER["HTTP_X_EXPECTED_UPLOAD_COUNT"] ?? "")
);
$expectedUploadCount = ctype_digit($expectedUploadCountHeader)
    ? (int) $expectedUploadCountHeader
    : 0;

if (
    $isUploadPostRequest &&
    $expectedUploadCount > 0 &&
    !$isAjaxUploadRequest
) {
    session_write_close();
    http_response_code(413);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "error",
        "message" => sprintf(
            "PHP did not receive the upload request. It may exceed the current post_max_size (%s). Increase the upload limits in C:\\xampp\\php\\php.ini and restart Apache.",
            (string) ini_get("post_max_size")
        )
    ]);
    exit();
}

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
$batchManifestEntries = [];

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
$uploadSessionId = "";
$uploadBatchIndex = 1;
$uploadTotalBatches = 1;
$uploadBatchStartIndex = 0;
$overallSelectedCount = 0;
$batchExpectedFileCount = 0;
$uploadBatchState = [];
if ($isAjaxUploadRequest) {
    $uploadProgressToken = trim(
        (string) ($_POST["upload_progress_token"] ?? "")
    );
    $uploadSessionId = trim((string) ($_POST["upload_session_id"] ?? ""));
    $uploadBatchIndex = filter_var(
        $_POST["batch_index"] ?? null,
        FILTER_VALIDATE_INT
    );
    $uploadTotalBatches = filter_var(
        $_POST["total_batches"] ?? null,
        FILTER_VALIDATE_INT
    );
    $uploadBatchStartIndex = filter_var(
        $_POST["batch_start_index"] ?? null,
        FILTER_VALIDATE_INT
    );
    $overallSelectedCount = filter_var(
        $_POST["overall_total"] ?? null,
        FILTER_VALIDATE_INT
    );
    $batchExpectedFileCount = filter_var(
        $_POST["batch_file_count"] ?? null,
        FILTER_VALIDATE_INT
    );
    $formTokens = $_SESSION["upload_progress_form_tokens"] ?? [];
    $batchSessions = $_SESSION["upload_batch_sessions"] ?? [];
    if (!is_array($batchSessions)) {
        $batchSessions = [];
    }

    $validBatchMetadata =
        preg_match('/^[a-f0-9]{32}$/', $uploadSessionId) === 1 &&
        is_int($uploadBatchIndex) &&
        is_int($uploadTotalBatches) &&
        is_int($uploadBatchStartIndex) &&
        is_int($overallSelectedCount) &&
        is_int($batchExpectedFileCount) &&
        $uploadBatchIndex >= 1 &&
        $uploadTotalBatches >= 1 &&
        $uploadBatchIndex <= $uploadTotalBatches &&
        $uploadBatchStartIndex >= 0 &&
        $overallSelectedCount >= 1 &&
        $batchExpectedFileCount >= 1 &&
        $batchExpectedFileCount <= 15 &&
        $uploadBatchStartIndex + $batchExpectedFileCount <= $overallSelectedCount &&
        $expectedUploadCount === $batchExpectedFileCount;

    $uploadBatchState = $batchSessions[$uploadSessionId] ?? null;
    $isFirstBatch = !is_array($uploadBatchState);
    $validToken = preg_match('/^[a-f0-9]{32}$/', $uploadProgressToken) === 1 &&
        is_array($formTokens) &&
        isset($formTokens[$uploadProgressToken]);
    $validBatchSequence = $isFirstBatch
        ? ($uploadBatchIndex === 1 && $uploadBatchStartIndex === 0 && $validToken)
        : (
            is_array($uploadBatchState) &&
            ($uploadBatchState["token"] ?? "") === $uploadProgressToken &&
            (int) ($uploadBatchState["next_batch"] ?? 0) === $uploadBatchIndex &&
            (int) ($uploadBatchState["next_file_index"] ?? -1) === $uploadBatchStartIndex &&
            (int) ($uploadBatchState["total_batches"] ?? 0) === $uploadTotalBatches &&
            (int) ($uploadBatchState["overall_total"] ?? 0) === $overallSelectedCount
        );

    if (!$validBatchMetadata || !$validBatchSequence) {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => "error",
            "message" => "The upload batch is invalid or out of sequence. Refresh the page and try again."
        ]);
        exit();
    }

    if ($isFirstBatch) {
        unset($_SESSION["upload_progress_form_tokens"][$uploadProgressToken]);
        $uploadBatchState = [
            "token" => $uploadProgressToken,
            "total_batches" => $uploadTotalBatches,
            "overall_total" => $overallSelectedCount,
            "next_batch" => 1,
            "next_file_index" => 0,
            "processed_total" => 0,
            "success_count" => 0,
            "failed_count" => 0,
            "completed_image_ids" => [],
            "updated_at" => time()
        ];
    }

    $batchSessions[$uploadSessionId] = $uploadBatchState;
    $_SESSION["upload_batch_sessions"] = $batchSessions;
    $batchBaseCurrent = (int) ($uploadBatchState["processed_total"] ?? 0);
    $batchBaseSuccessCount = (int) ($uploadBatchState["success_count"] ?? 0);
    $batchBaseFailedCount = (int) ($uploadBatchState["failed_count"] ?? 0);
    $batchRemainingCount = max(
        0,
        $overallSelectedCount -
            ($uploadBatchStartIndex + $batchExpectedFileCount)
    );

    storeUploadProgress($uploadProgressToken, [
        "status" => "preparing",
        "current" => 0,
        "total" => $batchExpectedFileCount,
        "base_current" => $batchBaseCurrent,
        "remaining_count" => $batchRemainingCount,
        "overall_total" => $overallSelectedCount,
        "batch_index" => $uploadBatchIndex,
        "total_batches" => $uploadTotalBatches,
        "batch_start_index" => $uploadBatchStartIndex,
        "success_count" => $batchBaseSuccessCount,
        "failed_count" => $batchBaseFailedCount,
        "message" => sprintf(
            "Preparing batch %d of %d...",
            $uploadBatchIndex,
            $uploadTotalBatches
        )
    ]);
}

if ($isAjaxUploadRequest && $batchExpectedFileCount > 0) {
    $receivedUploadCount = isset($_FILES["plantation_image"]["name"])
        ? (is_array($_FILES["plantation_image"]["name"])
            ? count($_FILES["plantation_image"]["name"])
            : 1)
        : 0;

    if ($receivedUploadCount !== $batchExpectedFileCount) {
        $uploadCountError = sprintf(
            "PHP received %d of %d files in upload batch %d. No files in this batch were processed. Check max_file_uploads (%s) and post_max_size (%s).",
            $receivedUploadCount,
            $batchExpectedFileCount,
            $uploadBatchIndex,
            (string) ini_get("max_file_uploads"),
            (string) ini_get("post_max_size")
        );
        storeUploadProgress($uploadProgressToken, [
            "status" => "configuration_error",
            "current" => 0,
            "total" => $batchExpectedFileCount,
            "base_current" => $batchBaseCurrent,
            "remaining_count" => $batchRemainingCount,
            "overall_total" => $overallSelectedCount,
            "batch_index" => $uploadBatchIndex,
            "total_batches" => $uploadTotalBatches,
            "batch_start_index" => $uploadBatchStartIndex,
            "success_count" => $batchBaseSuccessCount,
            "failed_count" => $batchBaseFailedCount,
            "message" => $uploadCountError
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException("Could not save the upload error.");
        }
        unset($_SESSION["upload_batch_sessions"][$uploadSessionId]);
        $_SESSION["flash_message"] = $uploadCountError;
        $_SESSION["flash_type"] = "error";
        session_write_close();
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => "error",
            "message" => $uploadCountError
        ]);
        exit();
    }
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
        "base_current" => $batchBaseCurrent,
        "remaining_count" => $batchRemainingCount,
        "overall_total" => $overallSelectedCount,
        "batch_index" => $uploadBatchIndex,
        "total_batches" => $uploadTotalBatches,
        "batch_start_index" => $uploadBatchStartIndex,
        "success_count" => $batchBaseSuccessCount,
        "failed_count" => $batchBaseFailedCount,
        "message" => sprintf(
            "Preparing batch %d of %d...",
            $uploadBatchIndex,
            $uploadTotalBatches
        )
    ]);
}

}
foreach ($uploadedFiles as $imageIndex => $file) {
    if ($isAjaxUploadRequest) {
        storeUploadProgress($uploadProgressToken, [
            "status" => "processing",
            "current" => $imageIndex + 1,
            "total" => $uploadImageTotal,
            "base_current" => $batchBaseCurrent,
            "remaining_count" => $batchRemainingCount,
            "overall_total" => $overallSelectedCount,
            "batch_index" => $uploadBatchIndex,
            "total_batches" => $uploadTotalBatches,
            "batch_start_index" => $uploadBatchStartIndex,
            "success_count" => $batchBaseSuccessCount + count($completedImageIds),
            "failed_count" => $batchBaseFailedCount +
                max(0, $imageIndex - count($completedImageIds)),
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

                            $batchManifestEntries[] = [
                                "image_id" => $imageId,
                                "image_path" => $absoluteFilePath,
                                "zone" => $zoneId
                            ];

                            if (false) {

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
                                $pythonReturnCode = 1;
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
                            }

                        mysqli_stmt_close($insertImage);

                        $redirectAfterPost = true;
                    }
                }
            }
        }
    }
}

if ($batchManifestEntries !== []) {
    if ($isAjaxUploadRequest) {
        storeUploadProgress($uploadProgressToken, [
            "status" => "processing",
            "current" => 0,
            "total" => count($batchManifestEntries),
            "base_current" => $batchBaseCurrent,
            "remaining_count" => $batchRemainingCount,
            "overall_total" => $overallSelectedCount,
            "batch_index" => $uploadBatchIndex,
            "total_batches" => $uploadTotalBatches,
            "batch_start_index" => $uploadBatchStartIndex,
            "success_count" => $batchBaseSuccessCount,
            "failed_count" => $batchBaseFailedCount,
            "message" => sprintf(
                "Running DeepLabV3+ analysis on batch %d of %d (%d images)...",
                $uploadBatchIndex,
                $uploadTotalBatches,
                count($batchManifestEntries)
            )
        ]);
    }

    $manifestPath = tempnam(sys_get_temp_dir(), "inspector_weeds_batch_");
    $manifestSaved = $manifestPath !== false &&
        file_put_contents(
            $manifestPath,
            json_encode($batchManifestEntries, JSON_UNESCAPED_SLASHES)
        ) !== false;

    $pythonPath = __DIR__ . DIRECTORY_SEPARATOR . "venv"
        . DIRECTORY_SEPARATOR . "Scripts"
        . DIRECTORY_SEPARATOR . "python.exe";
    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . "run_inference_deeplab.py";

    if ($manifestSaved && file_exists($pythonPath) && file_exists($scriptPath)) {
        $command =
            escapeshellarg($pythonPath) . " " .
            escapeshellarg($scriptPath) . " " .
            "--batch-manifest " . escapeshellarg($manifestPath) .
            " --batch-size 4 2>&1";

        $pythonOutput = [];
        $pythonReturnCode = 0;
        exec($command, $pythonOutput, $pythonReturnCode);
        $batchAnalysis = extractPythonJson($pythonOutput);

        $analysisResults = is_array($batchAnalysis)
            ? (array) ($batchAnalysis["results"] ?? [])
            : [];
        $pythonBatchSucceeded =
            $pythonReturnCode === 0 &&
            is_array($batchAnalysis) &&
            ($batchAnalysis["status"] ?? "") === "success";
        if (!$pythonBatchSucceeded) {
            $pythonDiagnostic = sprintf(
                "exit_code=%d top_level_status=%s error=%s output=%s",
                $pythonReturnCode,
                (string) ($batchAnalysis["status"] ?? "invalid_or_missing_json"),
                (string) ($batchAnalysis["error"] ?? ""),
                implode("\n", $pythonOutput)
            );
            error_log(
                $uploadTimingEnabled
                    ? "Inspector Weeds Python batch failure: " . $pythonDiagnostic
                    : sprintf(
                        "Inspector Weeds Python batch failure: exit_code=%d status=%s",
                        $pythonReturnCode,
                        (string) ($batchAnalysis["status"] ?? "invalid_or_missing_json")
                    )
            );
        }
        $processedBatchIds = [];

        foreach ($analysisResults as $analysis) {
            if (!is_array($analysis)) {
                continue;
            }

            $imageId = (string) ($analysis["image_id"] ?? "");
            if ($imageId === "") {
                continue;
            }
            $processedBatchIds[$imageId] = true;

            if (($analysis["status"] ?? "") !== "success") {
                $failedStatus = mysqli_prepare(
                    $conn,
                    "UPDATE images SET analysis_status = 'Failed' WHERE image_id = ?"
                );
                if ($failedStatus) {
                    mysqli_stmt_bind_param($failedStatus, "s", $imageId);
                    mysqli_stmt_execute($failedStatus);
                    mysqli_stmt_close($failedStatus);
                }
                error_log(
                    "Inspector Weeds inference failed for {$imageId}: " .
                    (string) ($analysis["error"] ?? "Unknown inference error")
                );
                continue;
            }

            $processedImagePath = (string) ($analysis["processed_image_path"] ?? "");
            $maskImagePath = (string) ($analysis["mask_path"] ?? "");
            $modelUsed = (string) ($analysis["model_used"] ?? "DeepLabV3+");

            $severityRaw = strtoupper((string) ($analysis["severity_level"] ?? "LOW"));
            $severity = $severityRaw === "HIGH" ? "High" : "Low";
            $weedCoverage = (float) ($analysis["weed_coverage"] ?? 0);
            $weedPixels = (int) ($analysis["weed_pixels"] ?? 0);
            $totalPixels = (int) ($analysis["total_pixels"] ?? 0);
            $averageConfidence = (float) ($analysis["confidence_score"] ?? 0);

            $zoneId = "Unknown";
            foreach ($batchManifestEntries as $manifestEntry) {
                if ((string) ($manifestEntry["image_id"] ?? "") === $imageId) {
                    $zoneId = (string) ($manifestEntry["zone"] ?? "Unknown");
                    break;
                }
            }
            if ($zoneId === "Unknown") {
                $pythonZone = (string) ($analysis["zone"] ?? "Unknown");
                if ($pythonZone !== "Unknown") {
                    $zoneId = $pythonZone;
                }
            }

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

                $fileName = $imageId . ".jpg";
                foreach ($batchManifestEntries as $manifestEntry) {
                    if ((string) ($manifestEntry["image_id"] ?? "") === $imageId) {
                        $manifestPathForImage = (string) $manifestEntry["image_path"];
                        $fileName = basename($manifestPathForImage);
                        break;
                    }
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
                $completedImageIds[] = $imageId;
            } catch (Throwable $error) {
                mysqli_rollback($conn);
                error_log(
                    "Inspector Weeds analysis result save failed for {$imageId}: " .
                    $error->getMessage()
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
            }
        }

        foreach ($batchManifestEntries as $manifestEntry) {
            $imageId = (string) $manifestEntry["image_id"];
            if (isset($processedBatchIds[$imageId])) {
                continue;
            }
            $failedStatus = mysqli_prepare(
                $conn,
                "UPDATE images SET analysis_status = 'Failed' WHERE image_id = ?"
            );
            if ($failedStatus) {
                mysqli_stmt_bind_param($failedStatus, "s", $imageId);
                mysqli_stmt_execute($failedStatus);
                mysqli_stmt_close($failedStatus);
            }
        }

    } else {
        $startFailure = sprintf(
            "manifest_saved=%s manifest_path=%s python_path=%s python_exists=%s script_path=%s script_exists=%s",
            $manifestSaved ? "yes" : "no",
            $manifestPath === false ? "unavailable" : $manifestPath,
            $pythonPath,
            file_exists($pythonPath) ? "yes" : "no",
            $scriptPath,
            file_exists($scriptPath) ? "yes" : "no"
        );
        error_log(
            $uploadTimingEnabled
                ? "Inspector Weeds batch inference could not be started: " . $startFailure
                : "Inspector Weeds batch inference could not be started."
        );
        foreach ($batchManifestEntries as $manifestEntry) {
            $imageId = (string) $manifestEntry["image_id"];
            $failedStatus = mysqli_prepare(
                $conn,
                "UPDATE images SET analysis_status = 'Failed' WHERE image_id = ?"
            );
            if ($failedStatus) {
                mysqli_stmt_bind_param($failedStatus, "s", $imageId);
                mysqli_stmt_execute($failedStatus);
                mysqli_stmt_close($failedStatus);
            }
        }
    }

    if ($manifestPath !== false) {
        @unlink($manifestPath);
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

    $batchSuccessCount = count($completedImageIds);
    $batchFailedCount =
        max(0, $uploadImageTotal - $batchSuccessCount) +
        $uploadPreparationFailures;
    $batchResultTotal = $batchSuccessCount + $batchFailedCount;

    if ($isAjaxUploadRequest) {
        $batchSessions = $_SESSION["upload_batch_sessions"] ?? [];
        $logicalSession = $batchSessions[$uploadSessionId] ?? null;
        if (!is_array($logicalSession)) {
            throw new RuntimeException("The upload batch session could not be found.");
        }

        $logicalSession["success_count"] =
            (int) ($logicalSession["success_count"] ?? 0) + $batchSuccessCount;
        $logicalSession["failed_count"] =
            (int) ($logicalSession["failed_count"] ?? 0) + $batchFailedCount;
        $logicalSession["processed_total"] =
            (int) ($logicalSession["processed_total"] ?? 0) + $batchResultTotal;
        $logicalSession["completed_image_ids"] = array_values(array_unique(array_merge(
            (array) ($logicalSession["completed_image_ids"] ?? []),
            $completedImageIds
        )));
        $logicalSession["next_batch"] = $uploadBatchIndex + 1;
        $logicalSession["next_file_index"] =
            $uploadBatchStartIndex + $batchExpectedFileCount;
        $logicalSession["updated_at"] = time();
        $batchSessions[$uploadSessionId] = $logicalSession;
        $_SESSION["upload_batch_sessions"] = $batchSessions;

        $successCount = (int) $logicalSession["success_count"];
        $failedCount = (int) $logicalSession["failed_count"];
        $overallImageTotal = $successCount + $failedCount;
        $allCompletedImageIds = $logicalSession["completed_image_ids"];
        $isFinalBatch = $uploadBatchIndex === $uploadTotalBatches;

        if (!$isFinalBatch) {
            $uploadProgressMessage = sprintf(
                "Batch %d of %d complete. Preparing the next batch...",
                $uploadBatchIndex,
                $uploadTotalBatches
            );
            $_SESSION["upload_progress"][$uploadProgressToken] = [
                "status" => "preparing",
                "current" => $overallImageTotal,
                "total" => $overallImageTotal,
                "base_current" => 0,
                "remaining_count" => max(
                    0,
                    $overallSelectedCount - $logicalSession["next_file_index"]
                ),
                "overall_total" => $overallSelectedCount,
                "batch_index" => $uploadBatchIndex + 1,
                "total_batches" => $uploadTotalBatches,
                "batch_start_index" => $logicalSession["next_file_index"],
                "success_count" => $successCount,
                "failed_count" => $failedCount,
                "message" => $uploadProgressMessage,
                "updated_at" => time()
            ];
        } else {
            if ($failedCount > 0) {
                $uploadProgressMessage = sprintf(
                    "%d of %d images analysed successfully. %d %s failed.",
                    $successCount,
                    $overallImageTotal,
                    $failedCount,
                    $failedCount === 1 ? "image" : "images"
                );
            } elseif ($successCount === 0) {
                $uploadProgressMessage = $message !== ""
                    ? $message
                    : "No supported images were found. Please upload JPG, PNG or WEBP files.";
            } else {
                $uploadProgressMessage = $successCount === 1
                    ? "Image analysed successfully."
                    : sprintf("%d images analysed successfully.", $successCount);
            }

            $_SESSION["upload_completion_image_ids"] = $allCompletedImageIds;
            $_SESSION["latest_analysis_batch_ids"] = $allCompletedImageIds;
            if ($failedCount === 0 && $allCompletedImageIds !== []) {
                unset($_SESSION["flash_message"], $_SESSION["flash_type"]);
            } else {
                setFlashMessage($uploadProgressMessage, "error");
            }
            unset($_SESSION["upload_batch_sessions"][$uploadSessionId]);

            $_SESSION["upload_progress"][$uploadProgressToken] = [
                "status" => "complete",
                "current" => $overallImageTotal,
                "total" => $overallImageTotal,
                "base_current" => 0,
                "remaining_count" => 0,
                "overall_total" => $overallSelectedCount,
                "batch_index" => $uploadBatchIndex,
                "total_batches" => $uploadTotalBatches,
                "batch_start_index" => $uploadBatchStartIndex,
                "success_count" => $successCount,
                "failed_count" => $failedCount,
                "message" => $uploadProgressMessage,
                "updated_at" => time()
            ];
        }

        if (!session_write_close()) {
            throw new RuntimeException("Could not save the upload results.");
        }

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => $isFinalBatch ? "complete" : "batch_complete",
            "batch_index" => $uploadBatchIndex,
            "total_batches" => $uploadTotalBatches,
            "batch_success_count" => $batchSuccessCount,
            "batch_failed_count" => $batchFailedCount,
            "batch_total" => $batchResultTotal,
            "success_count" => $successCount,
            "failed_count" => $failedCount,
            "total" => $overallImageTotal,
            "message" => $uploadProgressMessage,
            "redirect" => $isFinalBatch ? "upload.php" : null
        ]);
        exit();
    }

    $successCount = $batchSuccessCount;
    $failedCount = $batchFailedCount;
    if ($uploadImageTotal > 0) {
        $uploadProgressMessage = $failedCount > 0
            ? sprintf(
                "%d of %d images analysed successfully. %d %s failed.",
                $successCount,
                $uploadImageTotal,
                $failedCount,
                $failedCount === 1 ? "image" : "images"
            )
            : sprintf("%d images analysed successfully.", $successCount);
    } elseif ($message !== "") {
        $uploadProgressMessage = $message;
    } else {
        $uploadProgressMessage = "No supported images were found. Please upload JPG, PNG or WEBP files.";
    }

    $message = $uploadProgressMessage;
    $message_type = $failedCount > 0 || $successCount === 0
        ? "error"
        : "success";

    if ($completedImageIds !== []) {
        $_SESSION["upload_completion_image_ids"] = $completedImageIds;
        $_SESSION["latest_analysis_batch_ids"] = $completedImageIds;
    }
    setFlashMessage($message, $message_type);

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

function stopUploadWithError(message) {
    analysisSubmitted = false;
    stopUploadProgressPolling();
    analyseButton.disabled = false;
    analyseButton.classList.remove("is-processing");
    analyseButtonLabel.textContent = "Analyse Images";
    uploadForm.setAttribute("aria-busy", "false");
    showUploadProgress(message);
}

function applyUploadProgress(state) {
    if (state.status === "complete" || state.status === "error") {
        finishUpload({
            message: state.message,
            redirect: "upload.php"
        });
        return;
    }

    if (state.status === "configuration_error") {
        analysisSubmitted = false;
        stopUploadProgressPolling();
        analyseButton.disabled = false;
        analyseButton.classList.remove("is-processing");
        analyseButtonLabel.textContent = "Analyse Images";
        uploadForm.setAttribute("aria-busy", "false");
        showUploadProgress(state.message || "PHP did not receive every selected file.");
        return;
    }

    if (state.status === "processing" && Number(state.total) > 0) {
        const baseCurrent = Number(state.base_current) || 0;
        const current = baseCurrent + Math.min(
            Number(state.current) || 0,
            Number(state.total)
        );
        const total = baseCurrent +
            Number(state.total) +
            (Number(state.remaining_count) || 0);
        const message = state.message ||
            (total === 1
                ? "Analysing image…"
                : `Analysing ${Math.min(current, total)} of ${total} images…`);
        showUploadProgress(message);
        analyseButtonLabel.textContent = "Analysing…";
        progressPollFailures = 0;
        return;
    }

    if (state.status === "preparing") {
        const message = Number(state.total) > 0
            ? `Preparing images ${Math.min(
                (Number(state.base_current) || 0) + (Number(state.current) || 0),
                (Number(state.base_current) || 0) +
                    Number(state.total) +
                    (Number(state.remaining_count) || 0)
            )} of ${
                (Number(state.base_current) || 0) +
                Number(state.total) +
                (Number(state.remaining_count) || 0)
            }…`
            : (state.message || "Preparing images…");
        showUploadProgress(message);
        analyseButtonLabel.textContent = "Preparing…";
    }
}

function createUploadSessionId() {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, function (byte) {
        return byte.toString(16).padStart(2, "0");
    }).join("");
}

function splitUploadFiles(files) {
    const UPLOAD_BATCH_SIZE = 15;
    const MAX_BATCH_BYTES = 30 * 1024 * 1024;
    const batches = [];
    let batch = [];
    let batchBytes = 0;

    files.forEach(function (file) {
        if (
            batch.length > 0 &&
            (
                batch.length >= UPLOAD_BATCH_SIZE ||
                batchBytes + file.size > MAX_BATCH_BYTES
            )
        ) {
            batches.push(batch);
            batch = [];
            batchBytes = 0;
        }

        batch.push(file);
        batchBytes += file.size;
    });

    if (batch.length > 0) {
        batches.push(batch);
    }

    return batches;
}

function sendUploadBatch(
    files,
    batchIndex,
    totalBatches,
    batchStartIndex,
    overallTotal,
    uploadSessionId
) {
    return new Promise(function (resolve, reject) {
        const formData = new FormData(uploadForm);
        formData.delete("plantation_image[]");
        files.forEach(function (file) {
            formData.append("plantation_image[]", file, file.name);
        });
        formData.append("ajax_upload", "1");
        formData.append("upload_session_id", uploadSessionId);
        formData.append("batch_index", String(batchIndex));
        formData.append("total_batches", String(totalBatches));
        formData.append("batch_start_index", String(batchStartIndex));
        formData.append("overall_total", String(overallTotal));
        formData.append("batch_file_count", String(files.length));

        const request = new XMLHttpRequest();
        request.open("POST", uploadForm.action || window.location.href, true);
        request.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        request.setRequestHeader(
            "X-Expected-Upload-Count",
            String(files.length)
        );

        request.upload.addEventListener("progress", function (progressEvent) {
            if (
                progressEvent.lengthComputable &&
                progressEvent.loaded >= progressEvent.total
            ) {
                showUploadProgress("Preparing images...");
            } else {
                showUploadProgress("Uploading images...");
            }
        });

        request.addEventListener("load", function () {
            let result;
            try {
                result = JSON.parse(request.responseText);
            } catch (error) {
                reject(new Error(
                    "The server could not confirm batch " + batchIndex +
                    ". Refresh the page and check Analysis Results before retrying."
                ));
                return;
            }

            if (request.status < 200 || request.status >= 300) {
                reject(new Error(
                    result.message || "Upload batch " + batchIndex + " failed."
                ));
                return;
            }

            const expectedStatus = batchIndex === totalBatches
                ? "complete"
                : "batch_complete";
            if (result.status !== expectedStatus) {
                reject(new Error(
                    result.message || "The server did not complete upload batch " + batchIndex + "."
                ));
                return;
            }
            resolve(result);
        });

        request.addEventListener("error", function () {
            reject(new Error(
                "The connection was interrupted during batch " + batchIndex +
                ". Previously completed batches were retained. Refresh and check Analysis Results before retrying."
            ));
        });

        request.send(formData);
    });
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

uploadForm.addEventListener("submit", async function (event) {
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
    const selectedFiles = Array.from(fileInput.files);
    const totalSelected = selectedFiles.length;
    const uploadBatches = splitUploadFiles(selectedFiles);
    const totalBatches = uploadBatches.length;
    const uploadSessionId = createUploadSessionId();

    analysisSubmitted = true;
    analyseButton.disabled = true;
    analyseButton.classList.add("is-processing");
    analyseButtonLabel.textContent = "Preparing…";
    showUploadProgress("Uploading images...");
    uploadForm.setAttribute("aria-busy", "true");

    pollUploadProgress();

    let finalResult = null;
    let batchStartIndex = 0;
    try {
        for (let batchOffset = 0; batchOffset < totalBatches; batchOffset++) {
            const batchIndex = batchOffset + 1;
            const batchFiles = uploadBatches[batchOffset];
            finalResult = await sendUploadBatch(
                batchFiles,
                batchIndex,
                totalBatches,
                batchStartIndex,
                totalSelected,
                uploadSessionId
            );
            batchStartIndex += batchFiles.length;
        }

        finishUpload(finalResult || {
            message: "Analysis complete.",
            redirect: "upload.php"
        });
    } catch (error) {
        const partialSummary = finalResult
            ? `${finalResult.success_count} images were successfully analysed before this batch; ${finalResult.failed_count} failed in completed batches. `
            : "";
        stopUploadWithError(
            partialSummary + error.message +
            " Successful batches were not re-uploaded."
        );
    }
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
