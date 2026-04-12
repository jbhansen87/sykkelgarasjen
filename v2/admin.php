<?php
declare(strict_types=1);

$bootstrapCandidates = [
    __DIR__ . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "bootstrap.php",
    dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "bootstrap.php",
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

function sg_admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function sg_admin_status_labels(): array
{
    return [
        "new" => "Ny",
        "read" => "Lest",
        "done" => "Ferdig",
    ];
}

function sg_admin_normalize_status_filter(mixed $value): string
{
    $allowed = ["all", "new", "read", "done"];
    $status = is_string($value) ? strtolower(trim($value)) : "all";
    return in_array($status, $allowed, true) ? $status : "all";
}

function sg_admin_normalize_status_value(mixed $value): string
{
    $allowed = array_keys(sg_admin_status_labels());
    $status = is_string($value) ? strtolower(trim($value)) : "new";
    return in_array($status, $allowed, true) ? $status : "new";
}

function sg_admin_normalize_sort(mixed $value): string
{
    $allowed = ["preferred_date", "created_at", "status", "last_reply"];
    $sort = is_string($value) ? strtolower(trim($value)) : "preferred_date";
    return in_array($sort, $allowed, true) ? $sort : "preferred_date";
}

function sg_admin_normalize_direction(mixed $value): string
{
    $direction = is_string($value) ? strtolower(trim($value)) : "asc";
    return $direction === "desc" ? "desc" : "asc";
}

function sg_admin_normalize_date_scope(mixed $value): string
{
    $allowed = ["all", "upcoming", "past", "no_date"];
    $scope = is_string($value) ? strtolower(trim($value)) : "all";
    return in_array($scope, $allowed, true) ? $scope : "all";
}

function sg_admin_normalize_query(mixed $value): string
{
    $query = is_string($value) ? trim($value) : "";
    if ($query === "") {
        return "";
    }

    return function_exists("mb_substr")
        ? mb_substr($query, 0, 120)
        : substr($query, 0, 120);
}

function sg_admin_normalize_open_booking_id(mixed $value): int
{
    if (is_numeric($value)) {
        $id = (int)$value;
        return $id > 0 ? $id : 0;
    }

    return 0;
}

function sg_admin_validate_iso_date(string $value): ?string
{
    $value = trim($value);
    if ($value === "") {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat("Y-m-d", $value, sg_get_app_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    $warningCount = is_array($errors) ? (int)($errors["warning_count"] ?? 0) : 0;
    $errorCount = is_array($errors) ? (int)($errors["error_count"] ?? 0) : 0;

    if (!$parsed instanceof DateTimeImmutable || $warningCount > 0 || $errorCount > 0) {
        return null;
    }

    return $parsed->format("Y-m-d");
}

function sg_admin_format_datetime(?string $value): string
{
    if (!is_string($value) || trim($value) === "") {
        return "Ikke oppgitt";
    }

    $parsed = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $value, sg_get_app_timezone());
    if (!$parsed instanceof DateTimeImmutable) {
        return $value;
    }

    return $parsed->format("d.m.Y \\k\\l. H:i");
}

function sg_admin_compact_value(?string $value, string $fallback = "Ikke oppgitt"): string
{
    $value = is_string($value) ? trim($value) : "";
    return $value !== "" ? $value : $fallback;
}

function sg_admin_default_reply_subject(array $booking): string
{
    $bookingId = (int)($booking["id"] ?? 0);
    $lastSubject = trim((string)($booking["admin_last_reply_subject"] ?? ""));
    if ($lastSubject !== "") {
        return $lastSubject;
    }

    return sg_format_booking_subject_reference($bookingId) . "Svar fra Sykkelgarasjen Nesna";
}

function sg_admin_dashboard_query(array $overrides = []): string
{
    $params = [
        "q" => sg_admin_normalize_query($overrides["q"] ?? ($_GET["q"] ?? "")),
        "status" => sg_admin_normalize_status_filter($overrides["status"] ?? ($_GET["status"] ?? "all")),
        "date_scope" => sg_admin_normalize_date_scope($overrides["date_scope"] ?? ($_GET["date_scope"] ?? "all")),
        "sort" => sg_admin_normalize_sort($overrides["sort"] ?? ($_GET["sort"] ?? "preferred_date")),
        "direction" => sg_admin_normalize_direction($overrides["direction"] ?? ($_GET["direction"] ?? "asc")),
    ];

    $open = sg_admin_normalize_open_booking_id($overrides["open"] ?? ($_GET["open"] ?? 0));
    if ($open > 0) {
        $params["open"] = (string)$open;
    }

    return http_build_query(array_filter(
        $params,
        static fn(mixed $value): bool => $value !== ""
    ));
}

function sg_admin_redirect(string $query = ""): never
{
    $target = "admin.php";
    if ($query !== "") {
        $target .= "?" . $query;
    }

    header("Location: " . $target, true, 303);
    exit;
}

function sg_admin_fetch_booking_by_id(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            name,
            email,
            phone,
            address,
            bike_type,
            transport_assistance,
            wash_option,
            service_slug,
            service_name,
            preferred_date,
            preferred_time,
            message,
            newsletter_opt_in,
            notification_sent,
            notification_error,
            admin_status,
            admin_status_updated_at,
            admin_note,
            admin_last_reply_subject,
            admin_last_replied_at,
            created_at
         FROM booking_requests
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([
        ":id" => $bookingId,
    ]);

    $booking = $stmt->fetch();
    return is_array($booking) ? $booking : null;
}

function sg_admin_fetch_bookings(PDO $pdo, string $statusFilter, string $dateScope, string $sort, string $direction, string $queryText): array
{
    $orderBy = [
        "preferred_date" => "CASE WHEN preferred_date IS NULL THEN 1 ELSE 0 END, preferred_date " . strtoupper($direction) . ", created_at DESC, id DESC",
        "created_at" => "created_at " . strtoupper($direction) . ", id DESC",
        "status" => "CASE admin_status WHEN 'new' THEN 1 WHEN 'read' THEN 2 WHEN 'done' THEN 3 ELSE 4 END " . strtoupper($direction) . ", created_at DESC, id DESC",
        "last_reply" => "CASE WHEN admin_last_replied_at IS NULL THEN 1 ELSE 0 END, admin_last_replied_at " . strtoupper($direction) . ", created_at DESC, id DESC",
    ];

    $sql = "SELECT
                id,
                name,
                email,
                phone,
                address,
                bike_type,
                transport_assistance,
                wash_option,
                service_slug,
                service_name,
                preferred_date,
                preferred_time,
                message,
                newsletter_opt_in,
                notification_sent,
                notification_error,
                admin_status,
                admin_status_updated_at,
                admin_note,
                admin_last_reply_subject,
                admin_last_replied_at,
                created_at
            FROM booking_requests";
    $conditions = [];
    $params = [];
    $today = (new DateTimeImmutable("today", sg_get_app_timezone()))->format("Y-m-d");

    if ($statusFilter !== "all") {
        $conditions[] = "admin_status = :admin_status";
        $params[":admin_status"] = $statusFilter;
    }

    if ($dateScope === "upcoming") {
        $conditions[] = "preferred_date IS NOT NULL AND preferred_date >= :today";
        $params[":today"] = $today;
    } elseif ($dateScope === "past") {
        $conditions[] = "preferred_date IS NOT NULL AND preferred_date < :today";
        $params[":today"] = $today;
    } elseif ($dateScope === "no_date") {
        $conditions[] = "preferred_date IS NULL";
    }

    if ($queryText !== "") {
        $conditions[] = "(name LIKE :search OR email LIKE :search OR phone LIKE :search OR service_name LIKE :search OR message LIKE :search OR admin_note LIKE :search OR CAST(id AS CHAR) = :search_exact)";
        $params[":search"] = "%" . $queryText . "%";
        $params[":search_exact"] = $queryText;
    }

    if ($conditions !== []) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY " . $orderBy[$sort];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function sg_admin_fetch_status_counts(PDO $pdo): array
{
    $counts = [
        "new" => 0,
        "read" => 0,
        "done" => 0,
    ];

    $stmt = $pdo->query(
        "SELECT admin_status, COUNT(*) AS total
         FROM booking_requests
         GROUP BY admin_status"
    );

    foreach ($stmt->fetchAll() as $row) {
        $status = sg_admin_normalize_status_value($row["admin_status"] ?? "new");
        $counts[$status] = (int)($row["total"] ?? 0);
    }

    $counts["all"] = array_sum($counts);

    return $counts;
}

function sg_admin_fetch_blocked_dates(PDO $pdo): array
{
    $today = (new DateTimeImmutable("today", sg_get_app_timezone()))->format("Y-m-d");
    $stmt = $pdo->prepare(
        "SELECT blocked_date, reason, created_by, created_at
         FROM booking_blocked_dates
         WHERE blocked_date >= :today
         ORDER BY blocked_date ASC"
    );
    $stmt->execute([
        ":today" => $today,
    ]);

    return $stmt->fetchAll();
}

sg_send_admin_security_headers();
sg_start_admin_session();

$config = sg_load_config_or_fail();
$adminConfig = sg_admin_get_config($config);
$meta = sg_get_request_meta();
$fatalError = null;
$flash = null;
$bookings = [];
$blockedDates = [];
$statusCounts = [
    "all" => 0,
    "new" => 0,
    "read" => 0,
    "done" => 0,
];

$searchQuery = sg_admin_normalize_query($_GET["q"] ?? "");
$statusFilter = sg_admin_normalize_status_filter($_GET["status"] ?? "all");
$dateScope = sg_admin_normalize_date_scope($_GET["date_scope"] ?? "all");
$sort = sg_admin_normalize_sort($_GET["sort"] ?? "preferred_date");
$direction = sg_admin_normalize_direction($_GET["direction"] ?? "asc");
$openBookingId = sg_admin_normalize_open_booking_id($_GET["open"] ?? 0);
$dashboardQuery = sg_admin_dashboard_query([
    "q" => $searchQuery,
    "status" => $statusFilter,
    "date_scope" => $dateScope,
    "sort" => $sort,
    "direction" => $direction,
    "open" => $openBookingId,
]);

try {
    $pdo = sg_connect_pdo($config);
    sg_ensure_admin_tables($pdo);
} catch (PDOException $e) {
    sg_log_error("Admin bootstrap failed", [], $e);
    $fatalError = "Administrasjonssiden fikk ikke kontakt med databasen.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $fatalError === null) {
    $action = trim((string)($_POST["action"] ?? ""));
    $csrfToken = (string)($_POST["csrf_token"] ?? "");
    $postedBookingId = filter_input(INPUT_POST, "booking_id", FILTER_VALIDATE_INT);
    $redirectOpen = $postedBookingId && $postedBookingId > 0 ? (int)$postedBookingId : 0;

    if (!sg_admin_verify_csrf_token($csrfToken)) {
        sg_admin_set_flash("error", "Sikkerhetssjekken mislyktes. Prøv igjen.");
        sg_admin_redirect(sg_admin_dashboard_query(["open" => $redirectOpen]));
    }

    if ($action === "login") {
        if (!$adminConfig["configured"]) {
            sg_admin_set_flash("error", "Admin er ikke konfigurert ennå.");
            sg_admin_redirect();
        }

        if (sg_admin_count_recent_failed_attempts($pdo, $meta["ipBin"]) >= 5) {
            sg_admin_set_flash("error", "For mange mislykkede innloggingsforsøk. Vent litt og prøv igjen.");
            sg_admin_redirect();
        }

        $username = trim((string)($_POST["username"] ?? ""));
        $password = (string)($_POST["password"] ?? "");
        $usernameOk = $username !== "" && hash_equals($adminConfig["username"], $username);
        $passwordOk = $password !== "" && password_verify($password, $adminConfig["passwordHash"]);

        if ($usernameOk && $passwordOk) {
            sg_admin_record_login_attempt($pdo, $meta["ipBin"], $username, true);
            sg_admin_log_in($adminConfig["username"]);
            sg_admin_set_flash("success", "Innlogging vellykket.");
            sg_admin_redirect($dashboardQuery);
        }

        sg_admin_record_login_attempt($pdo, $meta["ipBin"], $username, false);
        sg_admin_set_flash("error", "Feil brukernavn eller passord.");
        sg_admin_redirect();
    }

    if (!sg_admin_is_authenticated()) {
        sg_admin_set_flash("error", "Du må logge inn for å bruke adminpanelet.");
        sg_admin_redirect();
    }

    if ($action === "logout") {
        sg_admin_log_out();
        header("Location: admin.php?logged_out=1", true, 303);
        exit;
    }

    if ($action === "update_status") {
        if (!$postedBookingId || $postedBookingId < 1) {
            sg_admin_set_flash("error", "Fant ikke bookingen som skulle oppdateres.");
            sg_admin_redirect($dashboardQuery);
        }

        $status = sg_admin_normalize_status_value($_POST["status"] ?? "new");
        $stmt = $pdo->prepare(
            "UPDATE booking_requests
             SET admin_status = :admin_status,
                 admin_status_updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ":admin_status" => $status,
            ":id" => $postedBookingId,
        ]);

        sg_admin_set_flash("success", "Booking #" . $postedBookingId . " ble oppdatert.");
        sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
    }

    if ($action === "save_note") {
        if (!$postedBookingId || $postedBookingId < 1) {
            sg_admin_set_flash("error", "Fant ikke bookingen som skulle lagres.");
            sg_admin_redirect($dashboardQuery);
        }

        $note = trim((string)($_POST["admin_note"] ?? ""));
        if (sg_string_length($note) > 5000) {
            sg_admin_set_flash("error", "Adminnotatet kan ikke være lengre enn 5000 tegn.");
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        $stmt = $pdo->prepare(
            "UPDATE booking_requests
             SET admin_note = :admin_note
             WHERE id = :id"
        );
        $stmt->execute([
            ":admin_note" => $note !== "" ? $note : null,
            ":id" => $postedBookingId,
        ]);

        sg_admin_set_flash("success", "Notatet ble lagret for booking #" . $postedBookingId . ".");
        sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
    }

    if ($action === "send_reply") {
        if (!$postedBookingId || $postedBookingId < 1) {
            sg_admin_set_flash("error", "Fant ikke bookingen du ville svare på.");
            sg_admin_redirect($dashboardQuery);
        }

        $booking = sg_admin_fetch_booking_by_id($pdo, $postedBookingId);
        if ($booking === null) {
            sg_admin_set_flash("error", "Bookingen finnes ikke lenger.");
            sg_admin_redirect($dashboardQuery);
        }

        $subject = trim((string)($_POST["reply_subject"] ?? ""));
        $body = trim((string)($_POST["reply_body"] ?? ""));

        if ($subject === "") {
            sg_admin_set_flash("error", "Legg inn et emne før du sender svarmail.");
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        if (sg_string_length($subject) > 255) {
            sg_admin_set_flash("error", "Emnet kan ikke være lengre enn 255 tegn.");
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        if ($body === "") {
            sg_admin_set_flash("error", "Skriv en melding før du sender svarmail.");
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        if (sg_string_length($body) > 8000) {
            sg_admin_set_flash("error", "Svarmailen kan ikke være lengre enn 8000 tegn.");
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        $mailResult = sg_send_admin_booking_reply($config, $booking, $subject, $body);
        if (!$mailResult["sent"]) {
            sg_admin_set_flash("error", "Svarmail kunne ikke sendes akkurat nå: " . (string)($mailResult["error"] ?? "Ukjent feil"));
            sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
        }

        $stmt = $pdo->prepare(
            "UPDATE booking_requests
             SET admin_last_reply_subject = :reply_subject,
                 admin_last_replied_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ":reply_subject" => sg_clean_mail_header_text($subject),
            ":id" => $postedBookingId,
        ]);

        sg_admin_set_flash("success", "Svarmail ble sendt til " . (string)$booking["email"] . ".");
        sg_admin_redirect(sg_admin_dashboard_query(["open" => $postedBookingId]));
    }

    if ($action === "block_date") {
        $blockedDate = sg_admin_validate_iso_date((string)($_POST["blocked_date"] ?? ""));
        $reason = trim((string)($_POST["reason"] ?? ""));
        $today = new DateTimeImmutable("today", sg_get_app_timezone());

        if ($blockedDate === null) {
            sg_admin_set_flash("error", "Velg en gyldig dato som skal sperres.");
            sg_admin_redirect($dashboardQuery);
        }

        if (sg_string_length($reason) > 255) {
            sg_admin_set_flash("error", "Begrunnelse kan ikke være lengre enn 255 tegn.");
            sg_admin_redirect($dashboardQuery);
        }

        $blockedDateTime = new DateTimeImmutable($blockedDate, sg_get_app_timezone());
        if ($blockedDateTime < $today) {
            sg_admin_set_flash("error", "Du kan bare sperre datoer fra i dag og framover.");
            sg_admin_redirect($dashboardQuery);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO booking_blocked_dates
             (blocked_date, reason, created_by, created_at)
             VALUES
             (:blocked_date, :reason, :created_by, NOW())
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                created_by = VALUES(created_by)"
        );
        $stmt->execute([
            ":blocked_date" => $blockedDate,
            ":reason" => $reason !== "" ? $reason : null,
            ":created_by" => (string)(($_SESSION["sg_admin"]["username"] ?? "") ?: "admin"),
        ]);

        sg_admin_set_flash("success", "Datoen " . sg_format_booking_date($blockedDate) . " er nå sperret for booking.");
        sg_admin_redirect($dashboardQuery);
    }

    if ($action === "remove_blocked_date") {
        $blockedDate = sg_admin_validate_iso_date((string)($_POST["blocked_date"] ?? ""));

        if ($blockedDate === null) {
            sg_admin_set_flash("error", "Fant ikke gyldig dato å åpne igjen.");
            sg_admin_redirect($dashboardQuery);
        }

        $stmt = $pdo->prepare(
            "DELETE FROM booking_blocked_dates
             WHERE blocked_date = :blocked_date"
        );
        $stmt->execute([
            ":blocked_date" => $blockedDate,
        ]);

        sg_admin_set_flash("success", "Datoen " . sg_format_booking_date($blockedDate) . " er åpnet for booking igjen.");
        sg_admin_redirect($dashboardQuery);
    }

    sg_admin_set_flash("error", "Ukjent handling.");
    sg_admin_redirect($dashboardQuery);
}

$flash = sg_admin_pull_flash();
if ($flash === null && isset($_GET["logged_out"]) && $_GET["logged_out"] === "1") {
    $flash = [
        "type" => "success",
        "message" => "Du er logget ut.",
    ];
}

if ($fatalError === null && sg_admin_is_authenticated()) {
    $bookings = sg_admin_fetch_bookings($pdo, $statusFilter, $dateScope, $sort, $direction, $searchQuery);
    $blockedDates = sg_admin_fetch_blocked_dates($pdo);
    $statusCounts = sg_admin_fetch_status_counts($pdo);
}

$csrfToken = sg_admin_get_csrf_token();
$statusLabels = sg_admin_status_labels();
?>
<!doctype html>
<html lang="nb">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Sykkelgarasjen Admin</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <main class="admin-shell">
    <section class="admin-panel admin-hero">
      <div>
        <p class="eyebrow">Sykkelgarasjen</p>
        <h1>Bookingadmin</h1>
        <p class="intro">Kompakt oversikt for bookinger, notater, status og svarmail direkte fra admin.</p>
      </div>
      <?php if (sg_admin_is_authenticated()): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="ghost-button" type="submit">Logg ut</button>
        </form>
      <?php endif; ?>
    </section>
    <?php if ($flash !== null): ?>
      <section class="admin-panel flash flash-<?= sg_admin_escape((string)($flash["type"] ?? "info")) ?>">
        <?= sg_admin_escape((string)($flash["message"] ?? "")) ?>
      </section>
    <?php endif; ?>

    <?php if ($fatalError !== null): ?>
      <section class="admin-panel admin-copy-panel">
        <h2>Teknisk feil</h2>
        <p><?= sg_admin_escape($fatalError) ?></p>
      </section>
    <?php elseif (!$adminConfig["configured"]): ?>
      <section class="admin-panel admin-copy-panel">
        <h2>Admin ikke konfigurert</h2>
        <p>Legg inn `admin_username` og `admin_password_hash` i serverkonfigurasjonen før innlogging kan tas i bruk.</p>
        <p>Passordet skal lagres som hash, ikke som klartekst.</p>
      </section>
    <?php elseif (!sg_admin_is_authenticated()): ?>
      <section class="admin-panel login-panel">
        <h2>Logg inn</h2>
        <form method="post" class="stack-form">
          <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
          <input type="hidden" name="action" value="login">

          <label class="field">
            <span>Brukernavn</span>
            <input type="text" name="username" autocomplete="username" required>
          </label>

          <label class="field">
            <span>Passord</span>
            <input type="password" name="password" autocomplete="current-password" required>
          </label>

          <button class="primary-button" type="submit">Logg inn</button>
        </form>
      </section>
    <?php else: ?>
      <section class="stats-grid">
        <article class="admin-panel stat-card">
          <span>Totalt</span>
          <strong><?= (int)$statusCounts["all"] ?></strong>
        </article>
        <article class="admin-panel stat-card stat-card-new">
          <span>Nye</span>
          <strong><?= (int)$statusCounts["new"] ?></strong>
        </article>
        <article class="admin-panel stat-card stat-card-read">
          <span>Lest</span>
          <strong><?= (int)$statusCounts["read"] ?></strong>
        </article>
        <article class="admin-panel stat-card stat-card-done">
          <span>Ferdig</span>
          <strong><?= (int)$statusCounts["done"] ?></strong>
        </article>
      </section>

      <section class="admin-grid">
        <aside class="admin-panel admin-sidebar">
          <div class="section-head">
            <div>
              <p class="eyebrow">Kalender</p>
              <h2>Sperrede datoer</h2>
            </div>
          </div>

          <form method="post" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="block_date">

            <label class="field">
              <span>Dato</span>
              <input type="date" name="blocked_date" required>
            </label>

            <label class="field">
              <span>Begrunnelse</span>
              <input type="text" name="reason" maxlength="255" placeholder="F.eks. ferie, stengt dag eller fullt verksted">
            </label>

            <button class="primary-button" type="submit">Sperr dato</button>
          </form>

          <div class="blocked-list">
            <?php if ($blockedDates === []): ?>
              <p class="muted">Ingen kommende datoer er sperret manuelt ennå.</p>
            <?php else: ?>
              <?php foreach ($blockedDates as $blocked): ?>
                <article class="blocked-item">
                  <div>
                    <strong><?= sg_admin_escape(sg_format_booking_date((string)$blocked["blocked_date"])) ?></strong>
                    <p><?= sg_admin_escape((string)(($blocked["reason"] ?? "") !== "" ? $blocked["reason"] : "Ingen begrunnelse lagt inn.")) ?></p>
                  </div>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="remove_blocked_date">
                    <input type="hidden" name="blocked_date" value="<?= sg_admin_escape((string)$blocked["blocked_date"]) ?>">
                    <button class="ghost-button" type="submit">Åpne</button>
                  </form>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </aside>

        <section class="admin-panel admin-main">
          <div class="section-head">
            <div>
              <p class="eyebrow">Oversikt</p>
              <h2>Bookinger</h2>
            </div>
            <p class="muted section-note">Trykk på en booking for å se detaljer, notat og svarmail.</p>
          </div>

          <form method="get" class="filter-bar">
            <label class="field">
              <span>Søk</span>
              <input type="search" name="q" value="<?= sg_admin_escape($searchQuery) ?>" placeholder="Navn, e-post, telefon, tjeneste eller booking-ID">
            </label>

            <label class="field compact-field">
              <span>Status</span>
              <select name="status">
                <option value="all"<?= $statusFilter === "all" ? " selected" : "" ?>>Alle</option>
                <option value="new"<?= $statusFilter === "new" ? " selected" : "" ?>>Ny</option>
                <option value="read"<?= $statusFilter === "read" ? " selected" : "" ?>>Lest</option>
                <option value="done"<?= $statusFilter === "done" ? " selected" : "" ?>>Ferdig</option>
              </select>
            </label>

            <label class="field compact-field">
              <span>Dato</span>
              <select name="date_scope">
                <option value="all"<?= $dateScope === "all" ? " selected" : "" ?>>Alle</option>
                <option value="upcoming"<?= $dateScope === "upcoming" ? " selected" : "" ?>>Kommende</option>
                <option value="past"<?= $dateScope === "past" ? " selected" : "" ?>>Tidligere</option>
                <option value="no_date"<?= $dateScope === "no_date" ? " selected" : "" ?>>Uten dato</option>
              </select>
            </label>

            <label class="field compact-field">
              <span>Sorter etter</span>
              <select name="sort">
                <option value="preferred_date"<?= $sort === "preferred_date" ? " selected" : "" ?>>Ønsket dato</option>
                <option value="created_at"<?= $sort === "created_at" ? " selected" : "" ?>>Mottatt</option>
                <option value="status"<?= $sort === "status" ? " selected" : "" ?>>Status</option>
                <option value="last_reply"<?= $sort === "last_reply" ? " selected" : "" ?>>Siste svar</option>
              </select>
            </label>

            <label class="field compact-field">
              <span>Retning</span>
              <select name="direction">
                <option value="asc"<?= $direction === "asc" ? " selected" : "" ?>>Stigende</option>
                <option value="desc"<?= $direction === "desc" ? " selected" : "" ?>>Synkende</option>
              </select>
            </label>

            <div class="filter-actions">
              <button class="ghost-button" type="submit">Oppdater visning</button>
            </div>
          </form>

          <div class="booking-list">
            <?php if ($bookings === []): ?>
              <p class="muted">Ingen bookinger matcher dette utvalget.</p>
            <?php else: ?>
              <?php foreach ($bookings as $booking): ?>
                <?php
                $bookingId = (int)$booking["id"];
                $bookingStatus = sg_admin_normalize_status_value($booking["admin_status"] ?? "new");
                $isOpen = $openBookingId === $bookingId;
                $preferredDate = sg_format_booking_date((string)($booking["preferred_date"] ?? ""));
                $createdAt = sg_admin_format_datetime((string)($booking["created_at"] ?? ""));
                $lastReplyAt = sg_admin_format_datetime((string)($booking["admin_last_replied_at"] ?? ""));
                $summaryMessage = trim((string)($booking["message"] ?? ""));
                ?>
                <details class="booking-card booking-card-<?= sg_admin_escape($bookingStatus) ?>"<?= $isOpen ? " open" : "" ?>>
                  <summary class="booking-summary">
                    <div class="summary-main">
                      <div class="summary-title-row">
                        <p class="booking-id">Booking #<?= $bookingId ?></p>
                        <h3><?= sg_admin_escape((string)$booking["name"]) ?></h3>
                      </div>
                      <p class="summary-service"><?= sg_admin_escape((string)$booking["service_name"]) ?></p>
                      <div class="summary-tags">
                        <span class="summary-tag"><?= sg_admin_escape($preferredDate) ?></span>
                        <?php if (trim((string)($booking["preferred_time"] ?? "")) !== ""): ?>
                          <span class="summary-tag"><?= sg_admin_escape((string)$booking["preferred_time"]) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string)($booking["phone"] ?? "")) !== ""): ?>
                          <span class="summary-tag"><?= sg_admin_escape((string)$booking["phone"]) ?></span>
                        <?php endif; ?>
                        <?php if ((string)($booking["transport_assistance"] ?? "no") !== "no"): ?>
                          <span class="summary-tag summary-tag-note"><?= sg_admin_escape(sg_format_transport_assistance((string)$booking["transport_assistance"])) ?></span>
                        <?php endif; ?>
                        <?php if ($summaryMessage !== ""): ?>
                          <span class="summary-tag summary-tag-note">Har beskrivelse</span>
                        <?php endif; ?>
                        <?php if (trim((string)($booking["admin_note"] ?? "")) !== ""): ?>
                          <span class="summary-tag summary-tag-note">Har notat</span>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="summary-side">
                      <span class="status-badge status-<?= sg_admin_escape($bookingStatus) ?>"><?= sg_admin_escape($statusLabels[$bookingStatus]) ?></span>
                      <p class="summary-meta-line"><?= sg_admin_escape((string)$booking["email"]) ?></p>
                      <p class="summary-meta-line">Mottatt <?= sg_admin_escape($createdAt) ?></p>
                      <?php if (!empty($booking["admin_last_replied_at"])): ?>
                        <p class="summary-meta-line">Sist svart <?= sg_admin_escape($lastReplyAt) ?></p>
                      <?php endif; ?>
                    </div>
                  </summary>

                  <div class="booking-card-body">
                    <div class="booking-top-grid">
                      <dl class="booking-meta">
                        <div>
                          <dt>Tjeneste</dt>
                          <dd><?= sg_admin_escape((string)$booking["service_name"]) ?></dd>
                        </div>
                        <div>
                          <dt>Ønsket dato</dt>
                          <dd><?= sg_admin_escape($preferredDate) ?></dd>
                        </div>
                        <div>
                          <dt>Tidspunkt</dt>
                          <dd><?= sg_admin_escape(sg_admin_compact_value((string)($booking["preferred_time"] ?? ""))) ?></dd>
                        </div>
                        <div>
                          <dt>Mottatt</dt>
                          <dd><?= sg_admin_escape($createdAt) ?></dd>
                        </div>
                        <div>
                          <dt>E-post</dt>
                          <dd><a href="mailto:<?= sg_admin_escape((string)$booking["email"]) ?>"><?= sg_admin_escape((string)$booking["email"]) ?></a></dd>
                        </div>
                        <div>
                          <dt>Telefon</dt>
                          <dd><?= sg_admin_escape(sg_admin_compact_value((string)($booking["phone"] ?? ""))) ?></dd>
                        </div>
                        <div>
                          <dt>Adresse</dt>
                          <dd><?= sg_admin_escape(sg_admin_compact_value((string)($booking["address"] ?? ""))) ?></dd>
                        </div>
                        <div>
                          <dt>Sykkeltype</dt>
                          <dd><?= sg_admin_escape(sg_admin_compact_value((string)($booking["bike_type"] ?? ""))) ?></dd>
                        </div>
                        <div>
                          <dt>Transport / frakt</dt>
                          <dd><?= sg_admin_escape(sg_format_transport_assistance((string)($booking["transport_assistance"] ?? "no"))) ?></dd>
                        </div>
                        <div>
                          <dt>Vask</dt>
                          <dd><?= sg_admin_escape(sg_format_wash_option((string)($booking["wash_option"] ?? "not_requested"))) ?></dd>
                        </div>
                        <div>
                          <dt>Nyhetsbrev</dt>
                          <dd><?= ((int)($booking["newsletter_opt_in"] ?? 0) === 1) ? "Ja" : "Nei" ?></dd>
                        </div>
                        <div>
                          <dt>E-postvarsel</dt>
                          <dd><?= ((int)($booking["notification_sent"] ?? 0) === 1) ? "Sendt" : "Feilet" ?></dd>
                        </div>
                        <div>
                          <dt>Siste svar</dt>
                          <dd><?= !empty($booking["admin_last_replied_at"]) ? sg_admin_escape($lastReplyAt) : "Ikke sendt ennå" ?></dd>
                        </div>
                        <div>
                          <dt>Emne siste svar</dt>
                          <dd><?= sg_admin_escape(sg_admin_compact_value((string)($booking["admin_last_reply_subject"] ?? ""), "Ikke sendt ennå")) ?></dd>
                        </div>
                      </dl>

                      <div class="booking-actions-panel">
                        <form method="post" class="status-form">
                          <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
                          <input type="hidden" name="action" value="update_status">
                          <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

                          <label class="field compact-field">
                            <span>Status</span>
                            <select name="status">
                              <?php foreach ($statusLabels as $statusValue => $statusLabel): ?>
                                <option value="<?= sg_admin_escape($statusValue) ?>"<?= $bookingStatus === $statusValue ? " selected" : "" ?>><?= sg_admin_escape($statusLabel) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>

                          <button class="primary-button" type="submit">Lagre status</button>
                        </form>

                        <div class="quick-links">
                          <a class="ghost-button quick-link" href="mailto:<?= sg_admin_escape((string)$booking["email"]) ?>">Åpne i e-post</a>
                          <?php if (trim((string)($booking["phone"] ?? "")) !== ""): ?>
                            <a class="ghost-button quick-link" href="tel:<?= sg_admin_escape((string)$booking["phone"]) ?>">Ring kunde</a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="booking-text-grid">
                      <section class="booking-message">
                        <h4>Beskrivelse fra kunde</h4>
                        <p><?= nl2br(sg_admin_escape($summaryMessage !== "" ? $summaryMessage : "Ingen ekstra beskrivelse.")) ?></p>
                      </section>

                      <section class="booking-message">
                        <h4>Adminnotat</h4>
                        <form method="post" class="stack-form">
                          <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
                          <input type="hidden" name="action" value="save_note">
                          <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                          <label class="field">
                            <span>Internt notat</span>
                            <textarea name="admin_note" rows="6" maxlength="5000" placeholder="Skriv internt notat, deler som må bestilles, avtaler eller oppfølging."><?= sg_admin_escape((string)($booking["admin_note"] ?? "")) ?></textarea>
                          </label>
                          <button class="ghost-button" type="submit">Lagre notat</button>
                        </form>
                      </section>
                    </div>

                    <section class="reply-panel">
                      <h4>Svar på booking</h4>
                      <form method="post" class="stack-form">
                        <input type="hidden" name="csrf_token" value="<?= sg_admin_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="send_reply">
                        <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

                        <label class="field">
                          <span>Til</span>
                          <input type="text" value="<?= sg_admin_escape((string)$booking["email"]) ?>" readonly>
                        </label>

                        <label class="field">
                          <span>Emne</span>
                          <input type="text" name="reply_subject" maxlength="255" value="<?= sg_admin_escape(sg_admin_default_reply_subject($booking)) ?>" required>
                        </label>

                        <label class="field">
                          <span>Melding</span>
                          <textarea name="reply_body" rows="8" maxlength="8000" placeholder="Skriv svaret som skal sendes til kunden."><?= sg_admin_escape("Hei " . (string)$booking["name"] . ",\n\n") ?></textarea>
                        </label>

                        <div class="reply-actions">
                          <button class="primary-button" type="submit">Send svarmail</button>
                          <?php if (!empty($booking["admin_last_replied_at"])): ?>
                            <p class="muted reply-meta">Siste svar sendt <?= sg_admin_escape($lastReplyAt) ?></p>
                          <?php endif; ?>
                        </div>
                      </form>
                    </section>

                    <?php if (!empty($booking["notification_error"])): ?>
                      <div class="booking-warning">
                        <strong>Varslingsfeil:</strong> <?= sg_admin_escape((string)$booking["notification_error"]) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
