<?php
declare(strict_types=1);

function sg_send_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit;
}

function sg_log_error(string $message, array $context = [], ?Throwable $exception = null): void
{
    $parts = ["[sykkelgarasjen-api] " . $message];

    if ($context !== []) {
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($contextJson)) {
            $parts[] = "context=" . $contextJson;
        }
    }

    if ($exception !== null) {
        $parts[] = "exception=" . $exception->getMessage();
    }

    error_log(implode(" ", $parts));
}

function sg_require_post_method(): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
        sg_send_json(405, ["ok" => false, "message" => "Method not allowed"]);
    }
}

function sg_read_request_input(): array
{
    $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

    if (stripos($contentType, "application/json") !== false) {
        $raw = file_get_contents("php://input");
        $decoded = json_decode($raw ?: "", true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function sg_string_length(string $value): int
{
    return function_exists("mb_strlen") ? mb_strlen($value) : strlen($value);
}

function sg_parse_bool(mixed $value): bool
{
    if (is_string($value)) {
        $value = strtolower(trim($value));
    }

    return $value === true
        || $value === 1
        || $value === "1"
        || $value === "true"
        || $value === "on";
}

function sg_get_request_meta(): array
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;
    $ipBin = null;

    if (is_string($ip) && $ip !== "" && filter_var($ip, FILTER_VALIDATE_IP)) {
        $packedIp = inet_pton($ip);
        if ($packedIp !== false) {
            $ipBin = $packedIp;
        }
    }

    return [
        "ip" => $ip,
        "ipBin" => $ipBin,
        "userAgent" => substr((string)($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255),
    ];
}

function sg_find_config_path(): ?string
{
    $configCandidates = [];
    $envConfigPath = getenv("SYKKEL_DB_CONFIG");

    if (is_string($envConfigPath) && $envConfigPath !== "") {
        $configCandidates[] = $envConfigPath;
    }

    $configCandidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = __DIR__ . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";

    foreach ($configCandidates as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function sg_load_config_or_fail(): array
{
    $configPath = sg_find_config_path();
    if ($configPath === null) {
        sg_log_error("Missing DB config file");
        sg_send_json(500, ["ok" => false, "message" => "Missing DB config file"]);
    }

    $config = require $configPath;
    if (!is_array($config)) {
        sg_log_error("Invalid server configuration", ["configPath" => $configPath]);
        sg_send_json(500, ["ok" => false, "message" => "Server configuration error"]);
    }

    $dbHost = (string)($config["db_host"] ?? "");
    $dbName = (string)($config["db_name"] ?? "");
    $dbUser = (string)($config["db_user"] ?? "");
    $dbPass = (string)($config["db_pass"] ?? "");

    if ($dbHost === "" || $dbName === "" || $dbUser === "" || $dbPass === "") {
        sg_log_error("Incomplete DB configuration", ["configPath" => $configPath]);
        sg_send_json(500, ["ok" => false, "message" => "Server configuration error"]);
    }

    $config["_config_path"] = $configPath;

    return $config;
}

function sg_connect_pdo(array $config): PDO
{
    return new PDO(
        "mysql:host={$config["db_host"]};dbname={$config["db_name"]};charset=utf8mb4",
        (string)$config["db_user"],
        (string)$config["db_pass"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function sg_validate_contact_fields(array $input, bool $requireConsent): array
{
    $name = trim((string)($input["name"] ?? ""));
    $email = strtolower(trim((string)($input["email"] ?? "")));
    $phone = trim((string)($input["phone"] ?? ""));
    $consent = sg_parse_bool($input["consent"] ?? false);
    $honeypot = trim((string)($input["website"] ?? ""));

    if ($honeypot !== "") {
        return [
            "honeypot" => true,
            "name" => $name,
            "email" => $email,
            "phone" => $phone,
            "consent" => $consent,
        ];
    }

    if ($name === "" || sg_string_length($name) > 120) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid name"]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid email"]);
    }

    if (strlen($phone) > 40) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid phone"]);
    }

    if ($requireConsent && !$consent) {
        sg_send_json(400, ["ok" => false, "message" => "Consent required"]);
    }

    return [
        "honeypot" => false,
        "name" => $name,
        "email" => $email,
        "phone" => $phone,
        "consent" => $consent,
    ];
}

function sg_validate_newsletter_request(array $input): array
{
    return sg_validate_contact_fields($input, true);
}

function sg_validate_booking_request(array $input): array
{
    $contact = sg_validate_contact_fields($input, true);
    if ($contact["honeypot"]) {
        return $contact + [
            "serviceSlug" => "",
            "serviceName" => "",
            "preferredDate" => "",
            "preferredTime" => "",
            "messageText" => "",
            "newsletter" => false,
        ];
    }

    $serviceSlug = trim((string)($input["serviceSlug"] ?? ""));
    $serviceName = trim((string)($input["serviceName"] ?? ""));
    $preferredDate = trim((string)($input["preferredDate"] ?? ""));
    $preferredTime = trim((string)($input["preferredTime"] ?? ""));
    $messageText = trim((string)($input["message"] ?? ""));
    $newsletter = sg_parse_bool($input["newsletter"] ?? false);

    if ($serviceSlug === "" || $serviceName === "" || sg_string_length($serviceName) > 255 || strlen($serviceSlug) > 120) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid service"]);
    }

    if ($preferredDate !== "") {
        $date = DateTimeImmutable::createFromFormat("Y-m-d", $preferredDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $warningCount = is_array($dateErrors) ? (int)($dateErrors["warning_count"] ?? 0) : 0;
        $errorCount = is_array($dateErrors) ? (int)($dateErrors["error_count"] ?? 0) : 0;

        if (!$date || $warningCount > 0 || $errorCount > 0) {
            sg_send_json(400, ["ok" => false, "message" => "Invalid preferred date"]);
        }
    }

    if (strlen($preferredTime) > 60) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid preferred time"]);
    }

    if (sg_string_length($messageText) > 4000) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid message"]);
    }

    return $contact + [
        "serviceSlug" => $serviceSlug,
        "serviceName" => $serviceName,
        "preferredDate" => $preferredDate,
        "preferredTime" => $preferredTime,
        "messageText" => $messageText,
        "newsletter" => $newsletter,
    ];
}

function sg_subscribe_newsletter(PDO $pdo, array $signup, array $meta): array
{
    $existing = $pdo->prepare(
        "SELECT id
         FROM newsletter_signups
         WHERE email = :email
         LIMIT 1"
    );
    $existing->execute([
        ":email" => $signup["email"],
    ]);

    if ($existing->fetch()) {
        return [
            "ok" => true,
            "status" => "already_subscribed",
            "message" => "Already subscribed",
        ];
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO newsletter_signups
             (name, email, phone, consent, consent_at, consent_ip, user_agent)
             VALUES
             (:name, :email, :phone, 1, NOW(), :consent_ip, :user_agent)"
        );

        $stmt->execute([
            ":name" => $signup["name"],
            ":email" => $signup["email"],
            ":phone" => $signup["phone"] !== "" ? $signup["phone"] : null,
            ":consent_ip" => $meta["ipBin"],
            ":user_agent" => $meta["userAgent"],
        ]);

        return [
            "ok" => true,
            "status" => "subscribed",
            "message" => "Subscribed",
        ];
    } catch (PDOException $e) {
        $info = $e->errorInfo ?? null;
        $mysqlErr = is_array($info) && isset($info[1]) ? (int)$info[1] : 0;
        $sqlState = $e->getCode();

        if ($mysqlErr === 1062 || $sqlState === "23000") {
            return [
                "ok" => true,
                "status" => "already_subscribed",
                "message" => "Already subscribed",
            ];
        }

        throw $e;
    }
}

function sg_ensure_booking_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS booking_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(40) NULL,
            service_slug VARCHAR(120) NOT NULL,
            service_name VARCHAR(255) NOT NULL,
            preferred_date DATE NULL,
            preferred_time VARCHAR(60) NULL,
            message TEXT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 1,
            newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            consent_at DATETIME NOT NULL,
            consent_ip VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            notification_email VARCHAR(255) NOT NULL,
            notification_sent TINYINT(1) NOT NULL DEFAULT 0,
            notification_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_booking_created_at (created_at),
            KEY idx_booking_service_slug (service_slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $hasNewsletterColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'newsletter_opt_in'");
    if ($hasNewsletterColumn instanceof PDOStatement && !$hasNewsletterColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0
             AFTER consent"
        );
    }
}

function sg_config_string(array $config, string $configKey, string $envKey, string $default = ""): string
{
    $envValue = getenv($envKey);
    if (is_string($envValue) && trim($envValue) !== "") {
        return trim($envValue);
    }

    $configValue = $config[$configKey] ?? null;
    if (is_string($configValue) && trim($configValue) !== "") {
        return trim($configValue);
    }

    return $default;
}

function sg_send_booking_notification(array $config, array $booking): array
{
    $recipient = sg_config_string($config, "booking_notification_email", "SYKKEL_BOOKING_NOTIFICATION_EMAIL", "booking@nesnasykkel.no");
    $fromEmail = sg_config_string($config, "mail_from_email", "SYKKEL_MAIL_FROM_EMAIL", "post@nesnasykkel.no");
    $fromName = sg_config_string($config, "mail_from_name", "SYKKEL_MAIL_FROM_NAME", "Sykkelgarasjen");

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            "sent" => false,
            "error" => "Invalid notification recipient configuration",
            "recipient" => $recipient,
        ];
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            "sent" => false,
            "error" => "Invalid sender configuration",
            "recipient" => $recipient,
        ];
    }

    if (!function_exists("mail")) {
        return [
            "sent" => false,
            "error" => "mail() unavailable",
            "recipient" => $recipient,
        ];
    }

    $safeName = trim(str_replace(["\r", "\n"], " ", $booking["name"]));
    $subjectText = "Ny bookingforesporsel fra " . $safeName;
    $subject = function_exists("mb_encode_mimeheader")
        ? mb_encode_mimeheader($subjectText, "UTF-8")
        : $subjectText;

    $bodyLines = [
        "Ny bookingforesporsel mottatt fra nettsiden.",
        "",
        "Navn: " . $booking["name"],
        "E-post: " . $booking["email"],
        "Telefon: " . ($booking["phone"] !== "" ? $booking["phone"] : "Ikke oppgitt"),
        "Tjeneste: " . $booking["serviceName"],
        "Service-slug: " . $booking["serviceSlug"],
        "Onsket dato: " . ($booking["preferredDate"] !== "" ? $booking["preferredDate"] : "Ikke oppgitt"),
        "Onsket tidspunkt: " . ($booking["preferredTime"] !== "" ? $booking["preferredTime"] : "Ikke oppgitt"),
        "Nyhetsbrev: " . ($booking["newsletter"] ? "Ja" : "Nei"),
        "",
        "Beskrivelse:",
        $booking["messageText"] !== "" ? $booking["messageText"] : "Ingen ekstra beskrivelse",
        "",
        "Booking-ID: " . $booking["bookingId"],
    ];

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "From: " . $fromName . " <" . $fromEmail . ">",
        "Reply-To: " . $booking["email"],
    ];

    $mailWarning = null;
    set_error_handler(
        static function (int $severity, string $message) use (&$mailWarning): bool {
            $mailWarning = $message;
            return true;
        }
    );

    try {
        $mailSent = mail(
            $recipient,
            $subject,
            implode("\n", $bodyLines),
            implode("\r\n", $headers)
        );
    } finally {
        restore_error_handler();
    }

    if (!$mailSent) {
        return [
            "sent" => false,
            "error" => $mailWarning !== null ? substr($mailWarning, 0, 255) : "mail() returned false",
            "recipient" => $recipient,
        ];
    }

    return [
        "sent" => true,
        "error" => null,
        "recipient" => $recipient,
    ];
}
