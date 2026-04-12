<?php
declare(strict_types=1);

$bootstrapCandidates = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "bootstrap.php",
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "bootstrap.php",
];

$bootstrapLoaded = false;
foreach ($bootstrapCandidates as $bootstrapCandidate) {
    if (is_readable($bootstrapCandidate)) {
        require_once $bootstrapCandidate;
        $bootstrapLoaded = true;
        break;
    }
}

if (!$bootstrapLoaded) {
    http_response_code(500);
    exit("Missing bootstrap configuration.");
}

sg_require_get_method();

$serviceSlug = trim((string)($_GET["service"] ?? ""));
$config = sg_load_config_or_fail();

try {
    $pdo = sg_connect_pdo($config);
    sg_ensure_core_tables($pdo);
    $rule = sg_get_booking_capacity_rule($serviceSlug);
    $dates = sg_get_unavailable_booking_dates($pdo, $serviceSlug);

    sg_send_json(200, [
        "ok" => true,
        "serviceSlug" => $serviceSlug,
        "capacityType" => $rule["capacityType"],
        "dailyLimit" => $rule["dailyLimit"],
        "unavailableDates" => $dates,
    ]);
} catch (PDOException $e) {
    sg_log_error("Booking availability lookup failed", ["service" => $serviceSlug], $e);
    sg_send_json(500, ["ok" => false, "message" => "Server error"]);
}
