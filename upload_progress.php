<?php
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

$token = trim((string) ($_GET["token"] ?? ""));
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    session_write_close();
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid upload progress token."
    ]);
    exit();
}

$progress = $_SESSION["upload_progress"][$token] ?? null;
session_write_close();

if (!is_array($progress)) {
    echo json_encode([
        "status" => "waiting"
    ]);
    exit();
}

echo json_encode([
    "status" => (string) ($progress["status"] ?? "waiting"),
    "current" => (int) ($progress["current"] ?? 0),
    "total" => (int) ($progress["total"] ?? 0),
    "base_current" => (int) ($progress["base_current"] ?? 0),
    "remaining_count" => (int) ($progress["remaining_count"] ?? 0),
    "overall_total" => (int) ($progress["overall_total"] ?? 0),
    "batch_index" => (int) ($progress["batch_index"] ?? 0),
    "total_batches" => (int) ($progress["total_batches"] ?? 0),
    "batch_start_index" => (int) ($progress["batch_start_index"] ?? 0),
    "success_count" => (int) ($progress["success_count"] ?? 0),
    "failed_count" => (int) ($progress["failed_count"] ?? 0),
    "message" => (string) ($progress["message"] ?? "")
]);
