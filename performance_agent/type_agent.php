<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../index.php');
    exit;
}

$idleLimit = 7200;
if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $idleLimit) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?expired=1');
    exit;
}

$_SESSION['last_activity'] = time();
$adminUsername = $_SESSION['admin_username'] ?? '';
$typeAgentError = '';

function typeAgentLogError(Throwable $error): void
{
    error_log(
        sprintf(
            "[%s] [type_agent] Database report failed: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $error->getMessage(),
            $error->getTraceAsString()
        ),
        3,
        __DIR__ . '/../error.log'
    );
}

function typeAgentGetConnection(): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        ),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

$buyerTypes = [
    'NEW BUYER' => 'First purchase was made during the current calendar month.',
    'ACTIVE BUYER' => 'Purchased within the current or previous two calendar months.',
    'REPEAT BUYER' => 'Additional label for active buyers who purchased in each of the last three consecutive calendar months.',
    'DORMANT BUYER' => 'Last purchased 3 to less than 6 calendar months ago.',
    'WARM LEAD' => 'Last purchased 6 to less than 9 calendar months ago.',
    'COOL LEAD' => 'Last purchased 9 to less than 12 calendar months ago.',
    'COLD LEAD' => 'Last purchased 12 or more calendar months ago.',
];

$selectedType = $_GET['type'] ?? 'ALL';
if (!is_string($selectedType) || ($selectedType !== 'ALL' && !isset($buyerTypes[$selectedType]))) {
    $selectedType = 'ALL';
}

$requestedPage = $_GET['page'] ?? '1';
$buyerCurrentPage = is_string($requestedPage) && ctype_digit($requestedPage)
    ? max(1, min((int)$requestedPage, 1000000000))
    : 1;
$pageDirection = $_GET['direction'] ?? '';
if (is_string($pageDirection) && $pageDirection === 'previous') {
    $buyerCurrentPage = max(1, $buyerCurrentPage - 1);
} elseif (is_string($pageDirection) && $pageDirection === 'next') {
    $buyerCurrentPage++;
}

$buyers = [];
$typeCounts = array_fill_keys(array_keys($buyerTypes), 0);
$pageSize = 100;
$totalBuyers = 0;
$lastPageBuyers = [];
$pageOffset = ($buyerCurrentPage - 1) * $pageSize;
$asOf = new DateTimeImmutable('today');
$currentMonthStart = $asOf->modify('first day of this month');
$recentStart = $currentMonthStart->modify('-2 months');
$nextMonthStart = $currentMonthStart->modify('+1 month');
$monthTwoBeforeRecentStart = $recentStart->modify('-2 months');

try {
    $pdo = typeAgentGetConnection();
    $sql = "
        SELECT
            UPPER(TRIM(o.member_code)) AS member_code,
            COALESCE(
                MAX(NULLIF(TRIM(o.member_name), '')),
                MAX(NULLIF(TRIM(m.member_name), '')),
                ''
            ) AS member_name,
            MIN(o.order_datetime) AS first_purchase,
            MAX(o.order_datetime) AS last_purchase,
            MAX(CASE
                WHEN o.order_datetime >= :current_month_start
                 AND o.order_datetime < :next_month_start THEN 1 ELSE 0
            END) AS bought_current_month,
            MAX(CASE
                WHEN o.order_datetime >= :recent_start
                 AND o.order_datetime < :current_month_start_for_previous THEN 1 ELSE 0
            END) AS bought_previous_month,
            MAX(CASE
                WHEN o.order_datetime >= :two_months_ago_start
                 AND o.order_datetime < :recent_start_for_two_months_ago THEN 1 ELSE 0
            END) AS bought_two_months_ago
        FROM orders o
        LEFT JOIN members m ON m.member_code = TRIM(o.member_code)
        WHERE LOWER(TRIM(COALESCE(o.order_status, ''))) = 'confirmed'
          AND o.member_code IS NOT NULL
          AND TRIM(o.member_code) <> ''
          AND o.order_datetime < :orders_before
        GROUP BY UPPER(TRIM(o.member_code))
        ORDER BY member_name, member_code
    ";
    $statement = $pdo->prepare($sql);
    $statement->execute([
        'current_month_start' => $currentMonthStart->format('Y-m-d 00:00:00'),
        'next_month_start' => $nextMonthStart->format('Y-m-d 00:00:00'),
        'recent_start' => $recentStart->format('Y-m-d 00:00:00'),
        'current_month_start_for_previous' => $currentMonthStart->format('Y-m-d 00:00:00'),
        'two_months_ago_start' => $monthTwoBeforeRecentStart->format('Y-m-d 00:00:00'),
        'recent_start_for_two_months_ago' => $recentStart->format('Y-m-d 00:00:00'),
        'orders_before' => $nextMonthStart->format('Y-m-d 00:00:00'),
    ]);

    while ($buyer = $statement->fetch()) {
        $firstPurchase = new DateTimeImmutable($buyer['first_purchase']);
        $lastPurchase = new DateTimeImmutable($buyer['last_purchase']);
        $isRepeatBuyer = (int)$buyer['bought_current_month'] === 1
            && (int)$buyer['bought_previous_month'] === 1
            && (int)$buyer['bought_two_months_ago'] === 1;

        if ($firstPurchase >= $currentMonthStart && $firstPurchase < $nextMonthStart) {
            $buyerType = 'NEW BUYER';
        } elseif ($lastPurchase >= $recentStart) {
            $buyerType = 'ACTIVE BUYER';
        } else {
            $monthsSincePurchase = (
                ((int)$currentMonthStart->format('Y') - (int)$lastPurchase->format('Y')) * 12
            ) + ((int)$currentMonthStart->format('n') - (int)$lastPurchase->format('n'));

            if ($monthsSincePurchase < 6) {
                $buyerType = 'DORMANT BUYER';
            } elseif ($monthsSincePurchase < 9) {
                $buyerType = 'WARM LEAD';
            } elseif ($monthsSincePurchase < 12) {
                $buyerType = 'COOL LEAD';
            } else {
                $buyerType = 'COLD LEAD';
            }
        }

        $buyer['buyer_type'] = $buyerType;
        $buyer['is_repeat_buyer'] = $isRepeatBuyer;
        $buyer['first_purchase_display'] = $firstPurchase->format('d M Y');
        $buyer['last_purchase_display'] = $lastPurchase->format('d M Y');

        $typeCounts[$buyerType]++;
        if ($isRepeatBuyer) {
            $typeCounts['REPEAT BUYER']++;
        }
        if (
            $selectedType === 'ALL'
            || ($selectedType === 'REPEAT BUYER' && $isRepeatBuyer)
            || ($selectedType !== 'REPEAT BUYER' && $selectedType === $buyerType)
        ) {
            $totalBuyers++;
            if ($totalBuyers > $pageOffset && $totalBuyers <= $pageOffset + $pageSize) {
                $buyers[] = $buyer;
            }

            $lastPageBuyers[] = $buyer;
            if (count($lastPageBuyers) > $pageSize) {
                array_shift($lastPageBuyers);
            }
        }
    }
} catch (Throwable $error) {
    typeAgentLogError($error);
    $typeAgentError = 'Buyer data could not be loaded. Please try again or contact an administrator.';
}

$totalPages = max(1, (int)ceil($totalBuyers / $pageSize));
if ($buyerCurrentPage > $totalPages) {
    $buyerCurrentPage = $totalPages;
    $buyers = $lastPageBuyers;
}

function typeAgentEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Type of Agent &mdash; S ASIA SALES REPORT</title>
<link rel="icon" href="../images/icon-sasia.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --red:#E0202E;--red-dark:#8E1620;--ink:#1B1B1F;--gray-700:#4A4A52;
    --gray-500:#8A8A93;--gray-300:#D8D8DE;--gray-100:#F2F2F4;
    --bg:#F5F5F7;--white:#FFFFFF;--sidebar-w:260px;
    --sidebar-w-collapsed:82px;--topbar-h:64px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--bg);color:var(--ink);font-family:'Plus Jakarta Sans',sans-serif}
button,select{font:inherit}
.layout{display:flex;margin-top:var(--topbar-h)}
.main{min-width:0;flex:1;margin-left:var(--sidebar-w);padding:28px 32px 48px;transition:margin-left .25s ease}
body.sidebar-collapsed .main{margin-left:var(--sidebar-w-collapsed)}
.page-header{margin-bottom:24px}
.page-header h1{margin-bottom:4px;font-size:1.5rem;font-weight:800}
.page-header p,.muted{color:var(--gray-500);font-size:.8125rem;line-height:1.6}
.card{margin-bottom:22px;padding:22px;border:1px solid var(--gray-100);border-radius:16px;background:var(--white);box-shadow:0 8px 24px rgba(30,30,40,.06)}
.filter-row{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}
.field{display:flex;min-width:260px;flex:1;flex-direction:column;gap:7px}
.field label{color:var(--gray-700);font-size:.6875rem;font-weight:800;letter-spacing:.35px;text-transform:uppercase}
.field select{width:100%;min-height:44px;padding:10px 12px;border:1.5px solid var(--gray-300);border-radius:9px;background:#fff;color:var(--ink)}
.field select:focus{border-color:var(--red);outline:none;box-shadow:0 0 0 3px rgba(224,32,46,.1)}
.apply-button{min-height:44px;padding:10px 20px;border:0;border-radius:9px;background:var(--red);color:#fff;cursor:pointer;font-size:.8125rem;font-weight:800}
.apply-button:hover{background:var(--red-dark)}
.type-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0 22px}
.type-card{padding:16px;border:1px solid var(--gray-100);border-radius:12px;background:#fff}
.type-name{font-size:.7rem;font-weight:800;letter-spacing:.25px}
.type-count{margin:7px 0 5px;font-size:1.5rem;font-weight:800;font-variant-numeric:tabular-nums}
.type-definition{color:var(--gray-500);font-size:.68rem;line-height:1.5}
.table-wrap{overflow-x:auto;border:1px solid #E6E6EA;border-radius:12px;background:#fff}
table{width:100%;min-width:680px;border-collapse:collapse}
th,td{padding:12px 14px;border-bottom:1px solid #ECECF0;text-align:left;font-size:.75rem}
th{background:#F7F7F9;color:var(--gray-700);font-size:.67rem;font-weight:800;letter-spacing:.3px;text-transform:uppercase}
tbody tr:last-child td{border-bottom:0}
.badge{display:inline-block;padding:5px 8px;border-radius:20px;background:#FCE8EB;color:#8E1620;font-size:.65rem;font-weight:800;white-space:nowrap}
.empty-state{padding:26px 12px;color:var(--gray-500);font-size:.8125rem;text-align:center}
.error-box{margin-bottom:18px;padding:13px 15px;border:1px solid #FECACA;border-radius:10px;background:#FFF0F0;color:#991B1B;font-size:.8125rem;font-weight:600}
.result-heading{margin-bottom:4px;font-size:1rem;font-weight:800}
.result-subtitle{margin-bottom:15px;color:var(--gray-500);font-size:.75rem}
.pagination{display:flex;align-items:center;justify-content:center;gap:9px;margin-top:18px}
.pagination button{min-width:40px;height:38px;padding:0 12px;border:1px solid var(--gray-300);border-radius:8px;background:#fff;color:var(--ink);cursor:pointer;font-weight:800}
.pagination button:hover:not(:disabled){border-color:var(--red);color:var(--red)}
.pagination button:disabled{opacity:.45;cursor:not-allowed}
.pagination-submit{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.pagination input{width:76px;height:38px;padding:6px 8px;border:1px solid var(--gray-300);border-radius:8px;text-align:center}
.pagination input:focus{border-color:var(--red);outline:none;box-shadow:0 0 0 3px rgba(224,32,46,.1)}
.pagination-label{color:var(--gray-500);font-size:.75rem}
@media(max-width:1100px){.type-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:900px){.main,body.sidebar-collapsed .main{margin-left:0;padding:20px}}
@media(max-width:620px){.type-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-row{align-items:stretch}.apply-button{width:100%}}
@media(max-width:400px){.type-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php
$pageTitle = 'Type of Agent';
$activeNav = 'agentperformance';
$navBasePath = '../';
include __DIR__ . '/../includes/topnav.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="layout">
<main class="main">
    <header class="page-header">
        <h1>Type of Agent</h1>
        <p>Buyer classification based on confirmed purchase history.</p>
    </header>

    <?php if ($typeAgentError !== ''): ?>
        <div class="error-box" role="alert"><?= typeAgentEscape($typeAgentError) ?></div>
    <?php endif; ?>

    <section class="card">
        <form method="get" class="filter-row">
            <div class="field">
                <label for="type">Select Buyer Type</label>
                <select id="type" name="type">
                    <option value="ALL" <?= $selectedType === 'ALL' ? 'selected' : '' ?>>All Buyer Types</option>
                    <?php foreach ($buyerTypes as $type => $definition): ?>
                        <option value="<?= typeAgentEscape($type) ?>" <?= $selectedType === $type ? 'selected' : '' ?>>
                            <?= typeAgentEscape($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="apply-button">Apply Filter</button>
        </form>

        <?php if ($typeAgentError === ''): ?>
        <div class="type-grid" aria-label="Buyer counts by category">
            <?php foreach ($buyerTypes as $type => $definition): ?>
                <article class="type-card">
                    <div class="type-name"><?= typeAgentEscape($type) ?></div>
                    <div class="type-count"><?= number_format($typeCounts[$type]) ?></div>
                    <div class="type-definition"><?= typeAgentEscape($definition) ?></div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="muted">
            The active window is the current calendar month and the two preceding months.
            NEW BUYER applies only during the calendar month of the buyer's first purchase.
            REPEAT BUYER is an additional label for ACTIVE BUYERs with at least one purchase
            in each of those three consecutive months. Only confirmed orders are included.
        </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="result-heading">
            <?= $selectedType === 'ALL' ? 'All Buyers' : typeAgentEscape($selectedType) ?>
        </h2>
        <?php if ($typeAgentError === ''): ?>
                <p class="result-subtitle">
                    Showing <?= number_format($totalBuyers === 0 ? 0 : (($buyerCurrentPage - 1) * $pageSize) + 1) ?>
                    to <?= number_format(min($buyerCurrentPage * $pageSize, $totalBuyers)) ?>
                    of <?= number_format($totalBuyers) ?> buyers
                </p>
        <?php endif; ?>
        <div class="table-wrap">
            <?php if ($typeAgentError !== ''): ?>
                <div class="empty-state">The report is unavailable because buyer data could not be loaded.</div>
            <?php elseif (!$buyers): ?>
                <div class="empty-state">No buyers found for this category.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member Code</th>
                            <th>Buyer Name</th>
                            <th>Buyer Type</th>
                            <th>First Purchase</th>
                            <th>Last Purchase</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($buyers as $index => $buyer): ?>
                            <tr>
                                <td><?= number_format((($buyerCurrentPage - 1) * $pageSize) + $index + 1) ?></td>
                                <td><?= typeAgentEscape((string)$buyer['member_code']) ?></td>
                                <td><?= typeAgentEscape((string)$buyer['member_name']) ?: '&mdash;' ?></td>
                                <td>
                                    <span class="badge"><?= typeAgentEscape($buyer['buyer_type']) ?></span>
                                    <?php if ($buyer['is_repeat_buyer']): ?>
                                        <span class="badge">REPEAT BUYER</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= typeAgentEscape($buyer['first_purchase_display']) ?></td>
                                <td><?= typeAgentEscape($buyer['last_purchase_display']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($typeAgentError === '' && $totalBuyers > 0): ?>
            <form method="get" class="pagination pagination-form" aria-label="Buyer list pages">
                <input type="hidden" name="type" value="<?= typeAgentEscape($selectedType) ?>">
                <button type="submit" class="pagination-submit" tabindex="-1" aria-hidden="true"></button>
                <button
                    type="submit"
                    name="direction"
                    value="previous"
                    aria-label="Previous page"
                    <?= $buyerCurrentPage <= 1 ? 'disabled' : '' ?>
                >&lt;</button>
                <label class="pagination-label" for="buyer-page">Page</label>
                <input
                    type="number"
                    id="buyer-page"
                    name="page"
                    value="<?= $buyerCurrentPage ?>"
                    min="1"
                    max="<?= $totalPages ?>"
                    aria-label="Page number"
                    required
                >
                <span class="pagination-label">of <?= number_format($totalPages) ?></span>
                <button
                    type="submit"
                    name="direction"
                    value="next"
                    aria-label="Next page"
                    <?= $buyerCurrentPage >= $totalPages ? 'disabled' : '' ?>
                >&gt;</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</div>
<script>
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('.filter-row, .pagination-form')) return;

    event.preventDefault();
    const main = form.closest('main.main');
    const button = event.submitter || form.querySelector('.apply-button');
    if (!main || !button) return;

    const originalLabel = button.textContent;
    const buttons = form.querySelectorAll('button');
    const previousDisabledStates = Array.from(buttons, (formButton) => formButton.disabled);
    buttons.forEach((formButton) => { formButton.disabled = true; });
    if (form.matches('.filter-row')) button.textContent = 'Loading...';
    main.setAttribute('aria-busy', 'true');

    try {
        const formData = new FormData(form);
        if (event.submitter instanceof HTMLButtonElement && event.submitter.name) {
            formData.set(event.submitter.name, event.submitter.value);
        }
        const params = new URLSearchParams(formData);
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = params.toString();
        url.searchParams.set('ajax', '1');

        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error(`Request failed with status ${response.status}.`);

        const html = await response.text();
        const nextDocument = new DOMParser().parseFromString(html, 'text/html');
        const nextMain = nextDocument.querySelector('main.main');
        if (!nextMain || !nextMain.querySelector('form.filter-row')) {
            throw new Error('The report response was invalid.');
        }

        main.replaceWith(nextMain);
        document.title = nextDocument.title || document.title;
    } catch (error) {
        const errorBox = document.createElement('div');
        errorBox.className = 'error-box';
        errorBox.setAttribute('role', 'alert');
        errorBox.textContent = 'Unable to update the report. Please try again.';
        main.prepend(errorBox);
        buttons.forEach((formButton, index) => {
            formButton.disabled = previousDisabledStates[index];
        });
        button.textContent = originalLabel;
        main.removeAttribute('aria-busy');
    }
});
</script>
</body>
</html>