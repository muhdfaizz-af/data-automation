<?php
/**
 * Recruitment Report
 *
 * New Registration =
 * Registration Order + On Behalf Register Order
 *
 * SPC Upgrade =
 * SPC Upgrade Order
 *
 * Purchase Agents =
 * Unique members who made a Repurchase Order or
 * On Behalf Repurchase Order.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

/**
 * Starter Kit master codes and brand names.
 *
 * Code variants such as STK-CA-01 are consolidated under STK-CA.
 */
define('STARTER_KITS', [
    'STK-ZEKY' => 'Zeky',
    'STK-CA'   => 'Choco Albab',
    'STK-NF'   => 'Nafesa',
]);

define('REGISTRATION_ORDER_TYPES', [
    'Registration Order',
    'On Behalf Register Order',
]);

define('SPC_UPGRADE_ORDER_TYPE', 'SPC Upgrade Order');

define('PURCHASE_AGENT_ORDER_TYPES', [
    'Repurchase Order',
    'On Behalf Repurchase Order',
]);

// Authentication
if(empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

// Idle timeout
$idleLimit = 7200;

if (
    !empty($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $idleLimit
) {
    session_unset();
    session_destroy();

    header('Location: ../index.php?expired=1');
    exit;
}

$_SESSION['last_activity'] = time();

$adminUsername = $_SESSION['admin_username'] ?? '';
$activeNav = 'recruitment';
$navBasePath = '../';

// Create the PDO database connection.
function getDBConnection(): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

// Validate a date in Y-m-d format.
function isValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed !== false &&
        $parsed->format('Y-m-d') === $date;
}

// Convert a starter kit item-code variant to its master code
function identifyStarterKit(string $itemCode): ?string
{
    $itemCode = strtoupper(trim($itemCode));

    foreach (array_keys(STARTER_KITS) as $masterCode) {
        if (
            $itemCode === $masterCode ||
            str_starts_with($itemCode, $masterCode . '-')
        ) {
            return $masterCode;
        }
    }

    return null;
}

// Return an empty report structure
function emptyRecruitmentMetrics(): array
{
    $starterKits = [];

    foreach (STARTER_KITS as $code => $brand) {
        $starterKits[$code] = [
            'brand' => $brand,
            'new_registration' => [
                'MY' => 0,
                'SG' => 0,
            ],
            'spc_upgrade' => [
                'MY' => 0,
                'SG' => 0,
            ],
            'total' => 0,
        ];
    }

    return [
        'starter_kits' => $starterKits,
        'totals' => [
            'new_registration' => [
                'MY' => 0,
                'SG' => 0,
            ],
            'spc_upgrade' => [
                'MY' => 0,
                'SG' => 0,
            ],
            'overall' => 0,
        ],
        'purchase_agents' => [
            'MY' => 0,
            'SG' => 0,
        ],
    ];
}

// Load Daily or Montly Recruitment metrics
function getRecruitmentMetrics(
    PDO $pdo,
    string $from,
    string $to
): array {
    $metrics = emptyRecruitmentMetrics();

    $fromDate = $from . ' 00:00:00';

    $toExclusive = (new DateTimeImmutable($to))
        ->modify('+1 day')
        ->format('Y-m-d 00:00:00');

    /*
     * Load orders containing a qualifying Starter Kit.
     *
     * The individual rows are consolidated in PHP because multiple
     * item rows can belong to the same order. The order ID is stored
     * in a set to ensure one order is counted only once.
     */
    $starterKitSql = "
        SELECT
            o.id AS database_order_id,
            o.order_type,
            c.company_code,
            oi.item_code

        FROM orders o

        INNER JOIN companies c
            ON c.id = o.company_id

        INNER JOIN order_items oi
            ON oi.order_id = o.id

        WHERE o.order_datetime >= :from_date
          AND o.order_datetime < :to_exclusive
          AND o.order_status = 'Confirmed'
          AND c.company_code IN ('MY', 'SG')

          AND o.order_type IN (
              'Registration Order',
              'On Behalf Register Order',
              'SPC Upgrade Order'
          )

          AND (
              UPPER(TRIM(oi.item_code)) = 'STK-ZEKY'
              OR UPPER(TRIM(oi.item_code)) LIKE 'STK-ZEKY-%'

              OR UPPER(TRIM(oi.item_code)) = 'STK-CA'
              OR UPPER(TRIM(oi.item_code)) LIKE 'STK-CA-%'

              OR UPPER(TRIM(oi.item_code)) = 'STK-NF'
              OR UPPER(TRIM(oi.item_code)) LIKE 'STK-NF-%'
          )
    ";

    $statement = $pdo->prepare($starterKitSql);

    $statement->execute([
        'from_date'     => $fromDate,
        'to_exclusive'  => $toExclusive,
    ]);

    /*
     * Sets prevent duplicate counting.
     *
     * Example:
     * $orderSets['STK-CA']['new_registration']['MY'][123] = true;
     */
    $orderSets = [];

    foreach (array_keys(STARTER_KITS) as $starterKitCode) {
        $orderSets[$starterKitCode] = [
            'new_registration' => [
                'MY' => [],
                'SG' => [],
            ],
            'spc_upgrade' => [
                'MY' => [],
                'SG' => [],
            ],
        ];
    }

    while ($row = $statement->fetch()) {
        $starterKit = identifyStarterKit(
            (string)$row['item_code']
        );

        if ($starterKit === null) {
            continue;
        }

        $company = strtoupper(
            trim((string)$row['company_code'])
        );

        if (!in_array($company, ['MY', 'SG'], true)) {
            continue;
        }

        $orderType = trim((string)$row['order_type']);
        $orderId = (int)$row['database_order_id'];

        if (
            in_array(
                $orderType,
                REGISTRATION_ORDER_TYPES,
                true
            )
        ) {
            $orderSets[$starterKit]
                ['new_registration']
                [$company]
                [$orderId] = true;
        } elseif ($orderType === SPC_UPGRADE_ORDER_TYPE) {
            $orderSets[$starterKit]
                ['spc_upgrade']
                [$company]
                [$orderId] = true;
        }
    }

    // Convert the order-ID sets into numeric counts
    foreach (STARTER_KITS as $starterKitCode => $brand) {
        foreach (['MY', 'SG'] as $company) {
            $newRegistrationCount = count(
                $orderSets[$starterKitCode]
                    ['new_registration']
                    [$company]
            );

            $spcUpgradeCount = count(
                $orderSets[$starterKitCode]
                    ['spc_upgrade']
                    [$company]
            );

            $metrics['starter_kits']
                [$starterKitCode]
                ['new_registration']
                [$company] = $newRegistrationCount;

            $metrics['starter_kits']
                [$starterKitCode]
                ['spc_upgrade']
                [$company] = $spcUpgradeCount;

            $metrics['totals']
                ['new_registration']
                [$company] += $newRegistrationCount;

            $metrics['totals']
                ['spc_upgrade']
                [$company] += $spcUpgradeCount;
        }

        $kitMetrics = $metrics['starter_kits'][$starterKitCode];

        $metrics['starter_kits']
            [$starterKitCode]
            ['total'] =
                $kitMetrics['new_registration']['MY'] +
                $kitMetrics['new_registration']['SG'] +
                $kitMetrics['spc_upgrade']['MY'] +
                $kitMetrics['spc_upgrade']['SG'];
    }

    // Starter kit formula
    $metrics['totals']['overall'] =
        $metrics['totals']['new_registration']['MY'] +
        $metrics['totals']['new_registration']['SG'] +
        $metrics['totals']['spc_upgrade']['MY'] +
        $metrics['totals']['spc_upgrade']['SG'];

  /*
    * Purchase Agent:
    *
    * Daily:
    * The agent must register and make a Repurchase Order or
    * On Behalf Repurchase Order on the same day.
    *
    * Monthly:
    * The agent must register and make a Repurchase Order or
    * On Behalf Repurchase Order within the same monthly period.
    *
    * The $fromDate and $toExclusive values determine whether
    * this function is calculating Daily or Monthly results.
    */
    $purchaseAgentSql = "
        SELECT
            registration_company.company_code,

            COUNT(
                DISTINCT UPPER(TRIM(registration.member_code))
            ) AS purchase_agents

        FROM orders registration

        INNER JOIN companies registration_company
            ON registration_company.id = registration.company_id

        WHERE registration.order_datetime >= :registration_from
            AND registration.order_datetime < :registration_to_exclusive
            AND registration.order_status = 'Confirmed'

            AND registration.order_type IN (
                'Registration Order',
                'On Behalf Register Order'
            )

            AND registration.member_code IS NOT NULL
            AND TRIM(registration.member_code) <> ''

            AND registration_company.company_code IN ('MY', 'SG')

            AND EXISTS (
                SELECT 1

                FROM orders purchase

                WHERE purchase.member_code IS NOT NULL
                    AND TRIM(purchase.member_code) <> ''

                    AND UPPER(TRIM(purchase.member_code)) =
                        UPPER(TRIM(registration.member_code))

                    AND purchase.order_datetime >= :purchase_from
                    AND purchase.order_datetime < :purchase_to_exclusive

                    AND purchase.order_status = 'Confirmed'

                    AND purchase.order_type IN (
                        'Repurchase Order',
                        'On Behalf Repurchase Order'
                    )
                )

                GROUP BY registration_company.company_code
    ";

    $statement = $pdo->prepare($purchaseAgentSql);

    $statement->execute([
        'registration_from' =>
            $fromDate,

        'registration_to_exclusive' =>
            $toExclusive,

        'purchase_from' =>
            $fromDate,

        'purchase_to_exclusive' =>
            $toExclusive,
    ]);

    while ($row = $statement->fetch()) {
        $company = strtoupper(
            trim((string)$row['company_code'])
        );

        if(isset($metrics['purchase_agents'][$company])) {
            $metrics['purchase_agents'][$company] =
                (int)$row['purchase_agents'];
        }
    }

    return $metrics;
}

// Render one Daily or Montly table

function renderRecruitmentTable(
    string $heading,
    array $metrics
): void {
    ?>
    <section class="report-block">
        <h2><?= htmlspecialchars($heading) ?></h2>

        <div class="table-wrap">
            <table class="recruitment-table">
                <thead>
                    <tr>
                        <th rowspan="2">Starter Kit</th>
                        <th colspan="2">New Registration</th>
                        <th colspan="2">SPC Upgrade</th>
                        <th rowspan="2">Total</th>
                        <th colspan="2">Purchase Agent</th>
                    </tr>
                    <tr>
                        <th>MY</th>
                        <th>SG</th>
                        <th>MY</th>
                        <th>SG</th>
                        <th>MY</th>
                        <th>SG</th>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    $rowNumber = 0;
                    $starterKitCount = count($metrics['starter_kits']);
                    ?>

                    <?php foreach ($metrics['starter_kits']as $code => $starterKit): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($code) ?></strong>
                                <small><?= htmlspecialchars($starterKit['brand']) ?></small>
                            </td>
                            <td>
                                <?= number_format($starterKit['new_registration']['MY']) ?>
                            </td>
                            <td>
                                <?= number_format($starterKit['new_registration']['SG']) ?>
                            </td>
                            <td>
                                <?= number_format($starterKit['spc_upgrade']['MY']) ?>
                            </td>
                            <td>
                                <?= number_format($starterKit['spc_upgrade']['SG']) ?>
                            </td>
                            <td>
                                <?= number_format($starterKit['total']) ?>
                            </td>

                            <?php if ($rowNumber === 0): ?>
                                <td rowspan="<?= $starterKitCount ?>">
                                    <?= number_format($metrics['purchase_agents']['MY']) ?>
                                </td>

                                <td rowspan="<?= $starterKitCount ?>">
                                    <?= number_format($metrics['purchase_agents']['SG']) ?>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <?php $rowNumber++; ?>
                    <?php endforeach; ?>

                    <tr class="total-row">
                        <td>Total</td>
                        <td>
                            <?= number_format($metrics['totals']['new_registration']['MY']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['totals']['new_registration']['SG']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['totals']['spc_upgrade']['MY']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['totals']['spc_upgrade']['SG']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['totals']['overall']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['purchase_agents']['MY']) ?>
                        </td>
                        <td>
                            <?= number_format($metrics['purchase_agents']['SG']) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

// Default report date set to yesterday
$defaultReportDate = (new DateTimeImmutable('today'))
    ->modify('-1 day')
    ->format('Y-m-d');

$reportDate = is_string($_GET['report_date'] ?? null)
    ? $_GET['report_date']
    : $defaultReportDate;

$errors = [];

if(!isValidDate($reportDate)) {
    $errors[] = 'Please select a valid report date.';
}

// Daily range contains one day, monthly range contains the complete calendar month.
$dailyFrom = $reportDate;
$dailyTo = $reportDate;

$monthlyFrom = '';
$monthlyTo = '';

if (empty($errors)) {
    $selectedDate = new DateTimeImmutable($reportDate);

    // The monthly range uses the complete selected calendar month
    $monthlyFrom = $selectedDate
        ->modify('first day of this month')
        ->format('Y-m-d');

    $monthlyTo = $selectedDate->format('Y-m-d');
}

$dailyMetrics = emptyRecruitmentMetrics();
$monthlyMetrics = emptyRecruitmentMetrics();

$pdo = getDBConnection();

if (!$pdo) {
    $errors[] = 'Unable to connect to the database.';
}

if (empty($errors) && $pdo) {
    try {
        $dailyMetrics = getRecruitmentMetrics(
            $pdo,
            $dailyFrom,
            $dailyTo,
        );

        $monthlyMetrics = getRecruitmentMetrics(
            $pdo,
            $monthlyFrom,
            $monthlyTo
        );
    } catch (Throwable $e) {
        error_log(
            'Recruitment report failed: ' .
            $e->getMessage()
        );

        $errors[] = 'Unable to load the Recruitment report.';
    }
}

$dailyHeading = empty($errors)
    ? strtoupper(date('d F Y', strtotime($reportDate)))
    : '';

$monthlyHeading = empty($errors)
    ? strtoupper(date('F Y', strtotime($reportDate)))
    : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Recruitment Report — S ASIA SALES REPORT</title>

<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link
    href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<style>
:root {
    --red: #E0202E;--red-dark: #8E1620;
    --ink: #1B1B1F;--gray-700: #4A4A52;--gray-500: #8A8A93;
    --gray-300: #D8D8DE;--gray-100: #F2F2F4;--bg: #F5F5F7;
    --white: #FFFFFF;
    --radius-lg: 18px;--radius-md: 12px;
    --shadow-card: 0 8px 24px rgba(30, 30, 40, .06);--sidebar-w: 260px;
    --sidebar-w-collapsed: 82px;--topbar-h: 64px;
}
*,*::before,*::after {box-sizing: border-box;margin: 0;padding: 0;}
body {background: var(--bg);color: var(--ink);font-family: "Plus Jakarta Sans", sans-serif;}

/* ── LAYOUT ── */
.layout {display:flex;min-height:calc(100vh - var(--topbar-h));margin-top:var(--topbar-h);}
.main {min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease;}
body.sidebar-collapsed .main {margin-left: var(--sidebar-w-collapsed);}

/* ── PAGE HEADER ── */
.page-header {margin-bottom: 24px;}
.page-header h1 {margin-bottom: 5px;font-size: 25px;font-weight: 800;}
.page-header p {color: var(--gray-500);font-size: 13px;}

/* ── CARD SECTION ── */
.card {margin-bottom: 24px;padding: 24px;border: 1px solid var(--gray-100);border-radius: var(--radius-lg);background: var(--white);box-shadow: var(--shadow-card);}
.card-title {margin-bottom: 4px;font-size: 16px;font-weight: 800;}
.card-subtitle {color: var(--gray-500);font-size: 12px;}

/* ── FILTER FORM ── */
.filter-form {display: flex;gap: 16px;align-items: flex-end;margin-top: 20px;}
.field {display: flex;width: min(100%, 340px);flex-direction: column;gap: 7px;}
.field label {color: var(--gray-700);font-size: 11px;font-weight: 800;text-transform: uppercase;}
.field input {min-height: 44px;padding: 10px 12px;border: 1.5px solid var(--gray-300);border-radius: 9px;background: var(--white);color: var(--ink);font: inherit;font-size: 13px;}

/* ── APPLY BUTTON ── */
.apply-button {min-height: 44px;padding: 10px 22px;border: 0;border-radius: 9px;background: var(--red);color: var(--white);cursor: pointer;font-size: 13px;font-weight: 800;}
.apply-button:hover {background: var(--red-dark);}

/* ── DEFINITION SECTION ── */
.definition,
.error-box {margin-bottom: 20px;padding: 14px 17px;border-radius: 10px;font-size: 12px;line-height: 1.7;}
.definition {border-left: 4px solid var(--red);background: var(--white);box-shadow: var(--shadow-card);}
.error-box {border: 1px solid #FECACA;background: #FEF2F2;color: #991B1B;}
.error-box ul {padding-left: 18px;}

/* ── REPORT CARD ── */
.report-card {min-width:0;padding:26px;overflow:hidden;}
.report-title {margin-bottom: 6px;text-align: center;font-size: 26px;font-weight: 800;letter-spacing: .5px;text-transform: uppercase;}
.report-subtitle {margin-bottom: 26px;color: var(--gray-500);text-align: center;font-size: 12px;}
.report-block + .report-block {margin-top: 38px;}
.report-block h2 {margin-bottom: 17px;text-align: center;font-size: 21px;font-weight: 800;text-transform: uppercase;}

/* ── TABLE SECTION ── */
.table-wrap {width:100%;min-width:0;overflow-x:auto;border:1px solid #E6E6EA;border-radius:12px;background:var(--white);}
.recruitment-table {width:100%;min-width:850px;border-collapse:separate;border-spacing:0;}
.recruitment-table th,.recruitment-table td {padding:13px 14px;border:0;border-bottom:1px solid #ECECF0;text-align:center;vertical-align:middle;font-size:12px;}
.recruitment-table th {background:#F7F7F9;color:var(--gray-700);font-size:10.5px;font-weight:800;letter-spacing:.3px;text-transform:uppercase;}
.recruitment-table thead tr:first-child th {border-bottom:1px solid #DEDEE4;}
.recruitment-table tbody tr {transition:background-color .15s ease;}
.recruitment-table tbody tr:not(.total-row):hover td {background:#FAFAFB;}
.recruitment-table tbody tr:last-child td {border-bottom:0;}
.recruitment-table td:first-child {text-align: left;}
.recruitment-table td:first-child small {display: block;margin-top: 3px;color: var(--gray-500);font-size: 9.5px;}
.total-row td {background:#F7F7F9 !important;font-weight:800;}
@media (max-width: 900px) {.main,body.sidebar-collapsed .main {margin-left: 0;padding: 20px;}}
@media (max-width: 600px) {.filter-form {flex-direction: column;align-items: stretch;}.field {width: 100%;}.apply-button {width: 100%;}}
</style>
</head>

<script>
    (function() {
        try {
            if (
                window.innerWidth >= 900 &&
                localStorage.getItem('adminSidebarCollapsed') === '1'
            ) {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (error) {
            // The report still works if localStorage is unavailable.
        }
})();
</script>

<?php
$pageTitle = 'Recruitment Report';

include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="layout">
<main class="main">

    <header class="page-header">
        <h1>Recruitment Report</h1>
        <p>Daily and monthly registrations by Starter Kit, SPC upgrades, and unique purchasing agents.</p>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="error-box" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="definition">
        <strong>New Registration:</strong>
        Registration Order + On Behalf Register Order.

        <strong>Purchase Agent:</strong>
        unique members with a Repurchase Order or
        On Behalf Repurchase Order.
    </div>

    <section class="card">
        <div class="card-title">Report Filter</div>

        <div class="card-subtitle">
            The selected date produces one daily report and one complete
            calendar-month report.
        </div>

        <form method="get" action="reqruitment.php" class="filter-form">
            <div class="field">
                <label for="report_date">Report Date</label>
                <input type="date" id="report_date" name="report_date" value="<?= htmlspecialchars($reportDate) ?>" required>
            </div>
            <button type="submit" name="apply" value="1" class="apply-button">Generate Report</button>
        </form>
    </section>

    <?php if (empty($errors)): ?>
        <section class="card report-card">
            <h2 class="report-title">
                Registration <?= htmlspecialchars($monthlyHeading) ?>
            </h2>
            <div class="report-subtitle">Counts are based on confirmed Tax Invoice orders.</div>
            <?php renderRecruitmentTable($dailyHeading, $dailyMetrics); ?>
            <?php renderRecruitmentTable($monthlyHeading, $monthlyMetrics); ?>
        </section>
    <?php endif; ?>
</main>
</div>

</body>
</html>