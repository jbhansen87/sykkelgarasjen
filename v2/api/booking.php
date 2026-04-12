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

sg_require_post_method();

$input = sg_read_request_input();
$booking = sg_validate_booking_request($input);

if ($booking["honeypot"]) {
    sg_send_json(200, ["ok" => true, "message" => "Booking request received"]);
}

$meta = sg_get_request_meta();
$config = sg_load_config_or_fail();

try {
    $pdo = sg_connect_pdo($config);
    sg_ensure_core_tables($pdo);
} catch (PDOException $e) {
    sg_log_error("Booking DB bootstrap failed", [], $e);
    sg_send_json(500, ["ok" => false, "message" => "Server error"]);
}

$notificationRecipient = sg_config_string($config, "booking_notification_email", "SYKKEL_BOOKING_NOTIFICATION_EMAIL", "booking@nesnasykkel.no");

try {
    sg_assert_booking_capacity($pdo, $booking["preferredDate"], $booking["serviceSlug"]);

    $insertBooking = $pdo->prepare(
        "INSERT INTO booking_requests
         (name, email, phone, address, bike_type, transport_assistance, wash_option, service_slug, service_name, preferred_date, preferred_time, message, consent, newsletter_opt_in, consent_at, consent_ip, user_agent, notification_email)
         VALUES
         (:name, :email, :phone, :address, :bike_type, :transport_assistance, :wash_option, :service_slug, :service_name, :preferred_date, :preferred_time, :message, 1, :newsletter_opt_in, NOW(), :consent_ip, :user_agent, :notification_email)"
    );

    $insertBooking->execute([
        ":name" => $booking["name"],
        ":email" => $booking["email"],
        ":phone" => $booking["phone"] !== "" ? $booking["phone"] : null,
        ":address" => $booking["address"] !== "" ? $booking["address"] : null,
        ":bike_type" => $booking["bikeType"] !== "" ? $booking["bikeType"] : null,
        ":transport_assistance" => $booking["transportAssistance"],
        ":wash_option" => $booking["washOption"],
        ":service_slug" => $booking["serviceSlug"],
        ":service_name" => $booking["serviceName"],
        ":preferred_date" => $booking["preferredDate"] !== "" ? $booking["preferredDate"] : null,
        ":preferred_time" => $booking["preferredTime"] !== "" ? $booking["preferredTime"] : null,
        ":message" => $booking["messageText"] !== "" ? $booking["messageText"] : null,
        ":newsletter_opt_in" => $booking["newsletter"] ? 1 : 0,
        ":consent_ip" => $meta["ipBin"],
        ":user_agent" => $meta["userAgent"],
        ":notification_email" => $notificationRecipient,
    ]);
} catch (PDOException $e) {
    sg_log_error("Booking insert failed", ["email" => $booking["email"], "service" => $booking["serviceSlug"]], $e);
    sg_send_json(500, ["ok" => false, "message" => "Server error"]);
}

$bookingId = (int)$pdo->lastInsertId();
$booking["bookingId"] = $bookingId;

$notification = sg_send_booking_notification($config, $booking);
if (!$notification["sent"]) {
    sg_log_error(
        "Booking notification failed",
        [
            "bookingId" => $bookingId,
            "recipient" => $notification["recipient"] ?? null,
            "error" => $notification["error"] ?? null,
        ]
    );
}

$customerConfirmation = sg_send_booking_customer_confirmation($config, $booking);
if (!$customerConfirmation["sent"]) {
    sg_log_error(
        "Booking customer confirmation failed",
        [
            "bookingId" => $bookingId,
            "recipient" => $customerConfirmation["recipient"] ?? null,
            "error" => $customerConfirmation["error"] ?? null,
        ]
    );
}

$newsletterResult = [
    "requested" => $booking["newsletter"],
    "status" => "not_requested",
];

if ($booking["newsletter"]) {
    try {
        $newsletterResult = [
            "requested" => true,
        ] + sg_subscribe_newsletter(
            $pdo,
            [
                "name" => $booking["name"],
                "email" => $booking["email"],
                "phone" => $booking["phone"],
                "consent" => true,
            ],
            $meta
        );
    } catch (PDOException $e) {
        sg_log_error("Booking newsletter subscribe failed", ["bookingId" => $bookingId, "email" => $booking["email"]], $e);
        $newsletterResult = [
            "requested" => true,
            "ok" => false,
            "status" => "failed",
            "message" => "Newsletter subscription failed",
        ];
    }
}

try {
    $updateNotification = $pdo->prepare(
        "UPDATE booking_requests
         SET notification_sent = :notification_sent,
             notification_error = :notification_error
         WHERE id = :id"
    );

    $updateNotification->execute([
        ":notification_sent" => $notification["sent"] ? 1 : 0,
        ":notification_error" => $notification["error"],
        ":id" => $bookingId,
    ]);
} catch (PDOException $e) {
    sg_log_error("Booking notification status update failed", ["bookingId" => $bookingId], $e);
}

$partial = !$notification["sent"] || !$customerConfirmation["sent"] || $newsletterResult["status"] === "failed";
$response = [
    "ok" => true,
    "message" => "Booking request received",
    "bookingId" => $bookingId,
    "notificationSent" => $notification["sent"],
    "customerConfirmationSent" => $customerConfirmation["sent"],
    "newsletter" => [
        "requested" => $newsletterResult["requested"],
        "status" => $newsletterResult["status"],
    ],
    "partial" => $partial,
];

if ($partial) {
    $response["warnings"] = [];

    if (!$notification["sent"]) {
        $response["warnings"][] = "notification_failed";
    }

    if (!$customerConfirmation["sent"]) {
        $response["warnings"][] = "customer_confirmation_failed";
    }

    if ($newsletterResult["status"] === "failed") {
        $response["warnings"][] = "newsletter_failed";
    }
}

sg_send_json(200, $response);
