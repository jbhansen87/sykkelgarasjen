<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$autoloadCandidates = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php",
    dirname(__DIR__) . DIRECTORY_SEPARATOR . "public_html" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php",
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "public_html" . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php",
];

foreach ($autoloadCandidates as $autoloadCandidate) {
    if (is_readable($autoloadCandidate)) {
        require_once $autoloadCandidate;
        break;
    }
}

function sg_send_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit;
}

function sg_require_get_method(): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
        sg_send_json(405, ["ok" => false, "message" => "Method not allowed"]);
    }
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

    $configCandidates[] = __DIR__ . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . "public_html" . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";
    $configCandidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "sykkelgarasjen-db.php";

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

function sg_service_booking_slugs(): array
{
    return [
        "ny-sykkel-enkel-gjennomgang",
        "barnesykkel-service",
        "rask-service",
        "standard-service",
        "aktiv-service",
        "premium-pro-service",
        "race-full-service",
    ];
}

function sg_get_booking_capacity_rule(string $serviceSlug): array
{
    if (in_array($serviceSlug, sg_service_booking_slugs(), true)) {
        return [
            "capacityType" => "service",
            "dailyLimit" => 2,
            "message" => "Denne datoen er fullbooket for service.",
        ];
    }

    return [
        "capacityType" => "small_job",
        "dailyLimit" => 4,
        "message" => "Denne datoen er fullbooket for mindre jobber.",
    ];
}

function sg_get_capacity_bucket_sql(string $serviceSlug): array
{
    $serviceSlugs = sg_service_booking_slugs();
    $placeholders = implode(", ", array_fill(0, count($serviceSlugs), "?"));
    $rule = sg_get_booking_capacity_rule($serviceSlug);

    if ($rule["capacityType"] === "service") {
        return [
            "sql" => "service_slug IN ($placeholders)",
            "params" => $serviceSlugs,
            "rule" => $rule,
        ];
    }

    return [
        "sql" => "service_slug NOT IN ($placeholders)",
        "params" => $serviceSlugs,
        "rule" => $rule,
    ];
}

function sg_validate_booking_request(array $input): array
{
    $contact = sg_validate_contact_fields($input, true);
    if ($contact["honeypot"]) {
        return $contact + [
            "address" => "",
            "bikeType" => "",
            "transportAssistance" => "no",
            "washOption" => "not_requested",
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
    $address = trim((string)($input["address"] ?? ""));
    $bikeType = trim((string)($input["bikeType"] ?? ""));
    $transportAssistance = strtolower(trim((string)($input["transportAssistance"] ?? "no")));
    $washOption = strtolower(trim((string)($input["washOption"] ?? "not_requested")));
    $preferredDate = trim((string)($input["preferredDate"] ?? ""));
    $preferredTime = trim((string)($input["preferredTime"] ?? ""));
    $messageText = trim((string)($input["message"] ?? ""));
    $newsletter = sg_parse_bool($input["newsletter"] ?? false);

    if ($serviceSlug === "" || $serviceName === "" || sg_string_length($serviceName) > 255 || strlen($serviceSlug) > 120) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid service"]);
    }

    if (sg_string_length($address) > 255) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid address"]);
    }

    if ($bikeType === "" || sg_string_length($bikeType) > 120) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid bike type"]);
    }

    $allowedTransportAssistance = ["no", "yes", "maybe"];
    if (!in_array($transportAssistance, $allowedTransportAssistance, true)) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid transport choice"]);
    }

    $allowedWashOptions = ["not_requested", "requested", "if_needed"];
    if (!in_array($washOption, $allowedWashOptions, true)) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid wash option"]);
    }

    if ($transportAssistance === "yes" && $address === "") {
        sg_send_json(400, ["ok" => false, "message" => "Adresse er påkrevd når du ønsker hjelp til transport."]);
    }

    if ($preferredDate !== "") {
        $date = DateTimeImmutable::createFromFormat("Y-m-d", $preferredDate, sg_get_app_timezone());
        $dateErrors = DateTimeImmutable::getLastErrors();
        $warningCount = is_array($dateErrors) ? (int)($dateErrors["warning_count"] ?? 0) : 0;
        $errorCount = is_array($dateErrors) ? (int)($dateErrors["error_count"] ?? 0) : 0;

        if (!$date || $warningCount > 0 || $errorCount > 0) {
            sg_send_json(400, ["ok" => false, "message" => "Ugyldig ønsket dato."]);
        }

        $today = new DateTimeImmutable("today", sg_get_app_timezone());
        if ($date < $today) {
            sg_send_json(400, ["ok" => false, "message" => "Du kan ikke velge en dato tilbake i tid."]);
        }
    }

    if (strlen($preferredTime) > 60) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid preferred time"]);
    }

    if (sg_string_length($messageText) > 4000) {
        sg_send_json(400, ["ok" => false, "message" => "Invalid message"]);
    }

    return $contact + [
        "address" => $address,
        "bikeType" => $bikeType,
        "transportAssistance" => $transportAssistance,
        "washOption" => $washOption,
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

function sg_ensure_newsletter_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS newsletter_signups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(40) NULL,
            consent TINYINT(1) NOT NULL DEFAULT 1,
            consent_at DATETIME NOT NULL,
            consent_ip VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_newsletter_email (email),
            KEY idx_newsletter_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function sg_ensure_booking_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS booking_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(40) NULL,
            address VARCHAR(255) NULL,
            bike_type VARCHAR(120) NULL,
            transport_assistance VARCHAR(20) NOT NULL DEFAULT 'no',
            wash_option VARCHAR(20) NOT NULL DEFAULT 'not_requested',
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
            admin_status VARCHAR(20) NOT NULL DEFAULT 'new',
            admin_status_updated_at DATETIME NULL,
            admin_note TEXT NULL,
            admin_last_reply_subject VARCHAR(255) NULL,
            admin_last_replied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_booking_created_at (created_at),
            KEY idx_booking_service_slug (service_slug),
            KEY idx_booking_preferred_date (preferred_date),
            KEY idx_booking_admin_status (admin_status)
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

    $hasBikeTypeColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'bike_type'");
    if ($hasBikeTypeColumn instanceof PDOStatement && !$hasBikeTypeColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN bike_type VARCHAR(120) NULL
             AFTER phone"
        );
    }

    $hasAddressColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'address'");
    if ($hasAddressColumn instanceof PDOStatement && !$hasAddressColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN address VARCHAR(255) NULL
             AFTER phone"
        );
    }

    $hasTransportAssistanceColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'transport_assistance'");
    if ($hasTransportAssistanceColumn instanceof PDOStatement && !$hasTransportAssistanceColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN transport_assistance VARCHAR(20) NOT NULL DEFAULT 'no'
             AFTER bike_type"
        );
    }

    $hasWashOptionColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'wash_option'");
    if ($hasWashOptionColumn instanceof PDOStatement && !$hasWashOptionColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN wash_option VARCHAR(20) NOT NULL DEFAULT 'not_requested'
             AFTER transport_assistance"
        );
    }

    $hasAdminStatusColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'admin_status'");
    if ($hasAdminStatusColumn instanceof PDOStatement && !$hasAdminStatusColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN admin_status VARCHAR(20) NOT NULL DEFAULT 'new'
             AFTER notification_error"
        );
    }

    $hasAdminStatusUpdatedAtColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'admin_status_updated_at'");
    if ($hasAdminStatusUpdatedAtColumn instanceof PDOStatement && !$hasAdminStatusUpdatedAtColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN admin_status_updated_at DATETIME NULL
             AFTER admin_status"
        );
    }

    $hasAdminNoteColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'admin_note'");
    if ($hasAdminNoteColumn instanceof PDOStatement && !$hasAdminNoteColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN admin_note TEXT NULL
             AFTER admin_status_updated_at"
        );
    }

    $hasAdminLastReplySubjectColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'admin_last_reply_subject'");
    if ($hasAdminLastReplySubjectColumn instanceof PDOStatement && !$hasAdminLastReplySubjectColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN admin_last_reply_subject VARCHAR(255) NULL
             AFTER admin_note"
        );
    }

    $hasAdminLastRepliedAtColumn = $pdo->query("SHOW COLUMNS FROM booking_requests LIKE 'admin_last_replied_at'");
    if ($hasAdminLastRepliedAtColumn instanceof PDOStatement && !$hasAdminLastRepliedAtColumn->fetch()) {
        $pdo->exec(
            "ALTER TABLE booking_requests
             ADD COLUMN admin_last_replied_at DATETIME NULL
             AFTER admin_last_reply_subject"
        );
    }
}

function sg_ensure_booking_blocked_dates_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS booking_blocked_dates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            blocked_date DATE NOT NULL,
            reason VARCHAR(255) NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_booking_blocked_date (blocked_date),
            KEY idx_booking_blocked_date (blocked_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function sg_ensure_admin_login_attempts_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip_address VARBINARY(16) NULL,
            username VARCHAR(120) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin_login_attempts_ip (ip_address),
            KEY idx_admin_login_attempts_attempted_at (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function sg_get_manually_blocked_booking_dates(PDO $pdo, ?string $fromDate = null): array
{
    $fromDate = $fromDate !== null && $fromDate !== ""
        ? $fromDate
        : (new DateTimeImmutable("today", sg_get_app_timezone()))->format("Y-m-d");

    $stmt = $pdo->prepare(
        "SELECT blocked_date
         FROM booking_blocked_dates
         WHERE blocked_date >= :from_date
         ORDER BY blocked_date ASC"
    );
    $stmt->execute([
        ":from_date" => $fromDate,
    ]);

    $dates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
        if (is_string($date) && $date !== "") {
            $dates[] = $date;
        }
    }

    return $dates;
}

function sg_is_booking_date_manually_blocked(PDO $pdo, string $preferredDate): bool
{
    if ($preferredDate === "") {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM booking_blocked_dates
         WHERE blocked_date = :blocked_date
         LIMIT 1"
    );
    $stmt->execute([
        ":blocked_date" => $preferredDate,
    ]);

    return (bool)$stmt->fetchColumn();
}

function sg_ensure_core_tables(PDO $pdo): void
{
    sg_ensure_newsletter_table($pdo);
    sg_ensure_booking_table($pdo);
    sg_ensure_booking_blocked_dates_table($pdo);
}

function sg_ensure_admin_tables(PDO $pdo): void
{
    sg_ensure_core_tables($pdo);
    sg_ensure_admin_login_attempts_table($pdo);
}

function sg_get_unavailable_booking_dates(PDO $pdo, string $serviceSlug, ?string $fromDate = null): array
{
    if ($serviceSlug === "") {
        return [];
    }

    $fromDate = $fromDate !== null && $fromDate !== ""
        ? $fromDate
        : (new DateTimeImmutable("today", sg_get_app_timezone()))->format("Y-m-d");

    $bucket = sg_get_capacity_bucket_sql($serviceSlug);
    $query = "
        SELECT preferred_date
        FROM booking_requests
        WHERE preferred_date IS NOT NULL
          AND preferred_date >= ?
          AND {$bucket["sql"]}
        GROUP BY preferred_date
        HAVING COUNT(*) >= ?
        ORDER BY preferred_date ASC
    ";

    $stmt = $pdo->prepare($query);
    $params = array_merge([$fromDate], $bucket["params"], [$bucket["rule"]["dailyLimit"]]);
    $stmt->execute($params);

    $dates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $date) {
        if (is_string($date) && $date !== "") {
            $dates[] = $date;
        }
    }

    $dates = array_values(array_unique(array_merge(
        $dates,
        sg_get_manually_blocked_booking_dates($pdo, $fromDate)
    )));
    sort($dates);

    return $dates;
}

function sg_assert_booking_capacity(PDO $pdo, string $preferredDate, string $serviceSlug): void
{
    if ($preferredDate === "" || $serviceSlug === "") {
        return;
    }

    if (sg_is_booking_date_manually_blocked($pdo, $preferredDate)) {
        sg_send_json(409, [
            "ok" => false,
            "message" => "Denne datoen er ikke tilgjengelig for booking.",
            "capacityType" => "blocked",
            "unavailableDates" => [$preferredDate],
        ]);
    }

    $bucket = sg_get_capacity_bucket_sql($serviceSlug);
    $query = "
        SELECT COUNT(*)
        FROM booking_requests
        WHERE preferred_date = ?
          AND {$bucket["sql"]}
    ";

    $stmt = $pdo->prepare($query);
    $params = array_merge([$preferredDate], $bucket["params"]);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $bucket["rule"]["dailyLimit"]) {
        sg_send_json(409, [
            "ok" => false,
            "message" => $bucket["rule"]["message"],
            "capacityType" => $bucket["rule"]["capacityType"],
            "dailyLimit" => $bucket["rule"]["dailyLimit"],
            "unavailableDates" => [$preferredDate],
        ]);
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

function sg_get_app_timezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezoneName = getenv("SYKKEL_TIMEZONE");
    if (!is_string($timezoneName) || trim($timezoneName) === "") {
        $timezoneName = "Europe/Oslo";
    }

    try {
        $timezone = new DateTimeZone(trim($timezoneName));
    } catch (\Exception) {
        $timezone = new DateTimeZone("Europe/Oslo");
    }

    return $timezone;
}

function sg_format_booking_date(string $date): string
{
    if ($date === "") {
        return "Ikke oppgitt";
    }

    $parsed = DateTimeImmutable::createFromFormat("Y-m-d", $date, sg_get_app_timezone());
    if (!$parsed instanceof DateTimeImmutable) {
        return $date;
    }

    return $parsed->format("d.m.Y");
}

function sg_format_transport_assistance(string $transportAssistance): string
{
    return match ($transportAssistance) {
        "yes" => "Ja",
        "maybe" => "Kanskje / ønsker å avklare",
        default => "Nei",
    };
}

function sg_format_wash_option(string $washOption): string
{
    return match ($washOption) {
        "requested" => "Ja, gjerne",
        "if_needed" => "Hvis det anbefales",
        default => "Nei takk",
    };
}

function sg_format_booking_subject_reference(int $bookingId): string
{
    if ($bookingId <= 0) {
        return "[Booking] ";
    }

    return "[Booking #" . $bookingId . "] ";
}

function sg_clean_mail_header_text(string $value): string
{
    return trim(str_replace(["\r", "\n"], " ", $value));
}

function sg_is_https_request(): bool
{
    if (isset($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off" && $_SERVER["HTTPS"] !== "") {
        return true;
    }

    $forwardedProto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "";
    return is_string($forwardedProto) && strtolower($forwardedProto) === "https";
}

function sg_send_admin_security_headers(): void
{
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("X-Robots-Tag: noindex, nofollow, noarchive");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: same-origin");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

function sg_start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");

    session_name("sg_admin_session");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "domain" => "",
        "secure" => sg_is_https_request(),
        "httponly" => true,
        "samesite" => "Lax",
    ]);

    session_start();
}

function sg_admin_get_config(array $config): array
{
    $username = sg_config_string($config, "admin_username", "SYKKEL_ADMIN_USERNAME");
    $passwordHash = sg_config_string($config, "admin_password_hash", "SYKKEL_ADMIN_PASSWORD_HASH");

    return [
        "username" => $username,
        "passwordHash" => $passwordHash,
        "configured" => $username !== "" && $passwordHash !== "",
    ];
}

function sg_admin_is_authenticated(): bool
{
    $session = $_SESSION["sg_admin"] ?? null;
    if (!is_array($session) || ($session["authenticated"] ?? false) !== true) {
        return false;
    }

    $lastActivityAt = isset($session["lastActivityAt"]) ? (int)$session["lastActivityAt"] : 0;
    if ($lastActivityAt <= 0 || (time() - $lastActivityAt) > 7200) {
        unset($_SESSION["sg_admin"]);
        return false;
    }

    $_SESSION["sg_admin"]["lastActivityAt"] = time();

    return true;
}

function sg_admin_log_in(string $username): void
{
    session_regenerate_id(true);
    $_SESSION["sg_admin"] = [
        "authenticated" => true,
        "username" => $username,
        "loggedInAt" => time(),
        "lastActivityAt" => time(),
    ];
}

function sg_admin_log_out(): void
{
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            [
                "expires" => time() - 42000,
                "path" => $params["path"] ?? "/",
                "domain" => $params["domain"] ?? "",
                "secure" => (bool)($params["secure"] ?? false),
                "httponly" => (bool)($params["httponly"] ?? true),
                "samesite" => $params["samesite"] ?? "Lax",
            ]
        );
    }

    session_destroy();
}

function sg_admin_get_csrf_token(): string
{
    if (!isset($_SESSION["sg_admin_csrf"]) || !is_string($_SESSION["sg_admin_csrf"]) || $_SESSION["sg_admin_csrf"] === "") {
        $_SESSION["sg_admin_csrf"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["sg_admin_csrf"];
}

function sg_admin_verify_csrf_token(?string $token): bool
{
    $sessionToken = $_SESSION["sg_admin_csrf"] ?? "";
    return is_string($token) && is_string($sessionToken) && $sessionToken !== "" && hash_equals($sessionToken, $token);
}

function sg_admin_set_flash(string $type, string $message): void
{
    $_SESSION["sg_admin_flash"] = [
        "type" => $type,
        "message" => $message,
    ];
}

function sg_admin_pull_flash(): ?array
{
    $flash = $_SESSION["sg_admin_flash"] ?? null;
    unset($_SESSION["sg_admin_flash"]);

    return is_array($flash) ? $flash : null;
}

function sg_admin_count_recent_failed_attempts(PDO $pdo, ?string $ipBin): int
{
    if ($ipBin === null) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM admin_login_attempts
         WHERE ip_address = :ip_address
           AND success = 0
           AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->bindValue(":ip_address", $ipBin, PDO::PARAM_LOB);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function sg_admin_record_login_attempt(PDO $pdo, ?string $ipBin, string $username, bool $success): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO admin_login_attempts
         (ip_address, username, success, attempted_at)
         VALUES
         (:ip_address, :username, :success, NOW())"
    );
    $stmt->bindValue(":ip_address", $ipBin, $ipBin !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
    $stmt->bindValue(":username", $username !== "" ? $username : null, $username !== "" ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(":success", $success ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
}

function sg_send_mail(
    array $config,
    string $recipient,
    string $recipientName,
    string $subject,
    string $body,
    ?array $replyTo = null
): array {
    $fromEmail = sg_config_string($config, "mail_from_email", "SYKKEL_MAIL_FROM_EMAIL", "booking@nesnasykkel.no");
    $fromName = sg_config_string($config, "mail_from_name", "SYKKEL_MAIL_FROM_NAME", "Sykkelgarasjen");
    $smtpHost = sg_config_string($config, "smtp_host", "SYKKEL_SMTP_HOST");
    $smtpUser = sg_config_string($config, "smtp_user", "SYKKEL_SMTP_USER", $fromEmail);
    $smtpPass = sg_config_string($config, "smtp_pass", "SYKKEL_SMTP_PASS");
    $smtpSecure = strtolower(sg_config_string($config, "smtp_secure", "SYKKEL_SMTP_SECURE", "ssl"));
    $smtpPortRaw = sg_config_string($config, "smtp_port", "SYKKEL_SMTP_PORT", "465");
    $smtpPort = ctype_digit($smtpPortRaw) ? (int)$smtpPortRaw : 465;

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            "sent" => false,
            "error" => "Invalid recipient configuration",
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

    if ($smtpHost === "" || $smtpUser === "" || $smtpPass === "") {
        return [
            "sent" => false,
            "error" => "Invalid SMTP configuration",
            "recipient" => $recipient,
        ];
    }

    $mailSent = false;
    $mail = null;
    $mailError = null;

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;
        $mail->CharSet = "UTF-8";
        $mail->Timeout = 10;
        $mail->Timelimit = 15;

        if ($smtpSecure === "ssl" || $smtpSecure === "smtps") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpSecure === "none" || $smtpSecure === "") {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient, $recipientName);

        if (is_array($replyTo)) {
            $replyToEmail = $replyTo["email"] ?? "";
            $replyToName = $replyTo["name"] ?? "";
            if (is_string($replyToEmail) && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyToEmail, is_string($replyToName) ? $replyToName : "");
            }
        }

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        $mailSent = true;
    } catch (Exception $e) {
        $mailError = $mail instanceof PHPMailer && $mail->ErrorInfo !== ""
            ? $mail->ErrorInfo
            : $e->getMessage();
    }

    if (!$mailSent) {
        return [
            "sent" => false,
            "error" => $mailError !== null ? substr($mailError, 0, 255) : "PHPMailer send failed",
            "recipient" => $recipient,
        ];
    }

    return [
        "sent" => true,
        "error" => null,
        "recipient" => $recipient,
    ];
}

function sg_send_booking_notification(array $config, array $booking): array
{
    $recipient = sg_config_string($config, "booking_notification_email", "SYKKEL_BOOKING_NOTIFICATION_EMAIL", "booking@nesnasykkel.no");
    $safeName = trim(str_replace(["\r", "\n"], " ", $booking["name"]));
    $bookingReference = sg_format_booking_subject_reference((int)($booking["bookingId"] ?? 0));
    $subject = $bookingReference . "Ny bookingforesporsel fra " . $safeName;
    $body = implode("\n", [
        "Ny bookingforesporsel mottatt fra nettsiden.",
        "",
        "Navn: " . $booking["name"],
        "E-post: " . $booking["email"],
        "Telefon: " . ($booking["phone"] !== "" ? $booking["phone"] : "Ikke oppgitt"),
        "Adresse: " . ($booking["address"] !== "" ? $booking["address"] : "Ikke oppgitt"),
        "Sykkeltype: " . ($booking["bikeType"] !== "" ? $booking["bikeType"] : "Ikke oppgitt"),
        "Transport / frakt: " . sg_format_transport_assistance((string)($booking["transportAssistance"] ?? "no")),
        "Vask: " . sg_format_wash_option((string)($booking["washOption"] ?? "not_requested")),
        "Tjeneste: " . $booking["serviceName"],
        "Service-slug: " . $booking["serviceSlug"],
        "Onsket dato: " . sg_format_booking_date($booking["preferredDate"]),
        "Onsket tidspunkt: " . ($booking["preferredTime"] !== "" ? $booking["preferredTime"] : "Ikke oppgitt"),
        "Nyhetsbrev: " . ($booking["newsletter"] ? "Ja" : "Nei"),
        "",
        "Beskrivelse:",
        $booking["messageText"] !== "" ? $booking["messageText"] : "Ingen ekstra beskrivelse",
        "",
        "Booking-ID: " . $booking["bookingId"],
    ]);

    return sg_send_mail(
        $config,
        $recipient,
        "Bookingmottaker",
        $subject,
        $body,
        [
            "email" => $booking["email"],
            "name" => $safeName !== "" ? $safeName : "Bookingkunde",
        ]
    );
}

function sg_send_booking_customer_confirmation(array $config, array $booking): array
{
    $replyToEmail = sg_config_string($config, "booking_reply_to_email", "SYKKEL_BOOKING_REPLY_TO_EMAIL", "booking@nesnasykkel.no");
    $replyToName = sg_config_string($config, "booking_reply_to_name", "SYKKEL_BOOKING_REPLY_TO_NAME", "Sykkelgarasjen Nesna");
    $bookingReference = sg_format_booking_subject_reference((int)($booking["bookingId"] ?? 0));
    $subject = $bookingReference . "Vi har mottatt bookingforesporselen din";
    $body = implode("\n", [
        "Hei " . $booking["name"] . ",",
        "",
        "Takk for bookingforesporselen din til Sykkelgarasjen Nesna.",
        "Vi har mottatt foresporselen og tar kontakt sa snart vi kan.",
        "",
        "Oppsummering:",
        "Tjeneste: " . $booking["serviceName"],
        "Onsket dato: " . sg_format_booking_date($booking["preferredDate"]),
        "Onsket tidspunkt: " . ($booking["preferredTime"] !== "" ? $booking["preferredTime"] : "Ikke oppgitt"),
        "Adresse: " . ($booking["address"] !== "" ? $booking["address"] : "Ikke oppgitt"),
        "Sykkeltype: " . ($booking["bikeType"] !== "" ? $booking["bikeType"] : "Ikke oppgitt"),
        "Transport / frakt: " . sg_format_transport_assistance((string)($booking["transportAssistance"] ?? "no")),
        "Vask: " . sg_format_wash_option((string)($booking["washOption"] ?? "not_requested")),
        "Telefon: " . ($booking["phone"] !== "" ? $booking["phone"] : "Ikke oppgitt"),
        "",
        "Hvis du trenger a oppdatere noe, kan du svare pa denne e-posten eller kontakte oss pa booking@nesnasykkel.no / 469 48 847.",
        "",
        "Booking-ID: " . $booking["bookingId"],
        "",
        "Hilsen",
        "Sykkelgarasjen Nesna",
    ]);

    return sg_send_mail(
        $config,
        $booking["email"],
        $booking["name"],
        $subject,
        $body,
        [
            "email" => $replyToEmail,
            "name" => $replyToName,
        ]
    );
}

function sg_send_admin_booking_reply(array $config, array $booking, string $subject, string $body): array
{
    $customerEmail = strtolower(trim((string)($booking["email"] ?? "")));
    $customerName = trim((string)($booking["name"] ?? ""));
    $bookingId = (int)($booking["id"] ?? $booking["bookingId"] ?? 0);
    $cleanSubject = sg_clean_mail_header_text($subject);

    if ($cleanSubject === "") {
        $cleanSubject = sg_format_booking_subject_reference($bookingId) . "Svar fra Sykkelgarasjen Nesna";
    } elseif ($bookingId > 0 && stripos($cleanSubject, "booking #") === false && stripos($cleanSubject, "[booking") === false) {
        $cleanSubject = sg_format_booking_subject_reference($bookingId) . $cleanSubject;
    }

    $replyToEmail = sg_config_string($config, "booking_reply_to_email", "SYKKEL_BOOKING_REPLY_TO_EMAIL", "booking@nesnasykkel.no");
    $replyToName = sg_config_string($config, "booking_reply_to_name", "SYKKEL_BOOKING_REPLY_TO_NAME", "Sykkelgarasjen Nesna");

    return sg_send_mail(
        $config,
        $customerEmail,
        $customerName !== "" ? $customerName : "Bookingkunde",
        $cleanSubject,
        $body,
        [
            "email" => $replyToEmail,
            "name" => $replyToName,
        ]
    );
}
