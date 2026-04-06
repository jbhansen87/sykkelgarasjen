<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . "bootstrap.php";

sg_require_post_method();

$input = sg_read_request_input();
$signup = sg_validate_newsletter_request($input);

if ($signup["honeypot"]) {
    sg_send_json(200, ["ok" => true, "message" => "Subscribed"]);
}

$meta = sg_get_request_meta();
$config = sg_load_config_or_fail();

try {
    $pdo = sg_connect_pdo($config);
    $result = sg_subscribe_newsletter($pdo, $signup, $meta);

    if ($result["status"] === "already_subscribed") {
        sg_send_json(409, ["ok" => false, "message" => "Already subscribed"]);
    }

    sg_send_json(200, ["ok" => true, "message" => "Subscribed"]);
} catch (PDOException $e) {
    sg_log_error("Newsletter subscribe failed", ["email" => $signup["email"]], $e);
    sg_send_json(500, ["ok" => false, "message" => "Server error"]);
}
