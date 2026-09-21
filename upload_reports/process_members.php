<?php
/**
 * Process Member List uploads from upload_members.php.
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $error['message']]);
    }
});

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Not authenticated']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method']));
}

if (!isset($_FILES['members']) || $_FILES['members']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Please select a member list file.']));
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

function memberHeader($value) {
    $value = strtolower(trim((string)$value));
    return preg_replace('/[^a-z0-9]/', '', $value);
}

function memberValue($row, $headers, array $names) {
    foreach ($names as $name) {
        $key = memberHeader($name);
        if (isset($headers[$key])) {
            return trim((string)($row[$headers[$key]] ?? ''));
        }
    }
    return '';
}

function memberDate($value) {
    if ($value === '') return null;
    if (in_array(trim((string)$value), ['0000-00-00', '0000-00-00 00:00:00'], true)) return null;
    if (is_numeric($value) && $value > 40000 && $value < 60000) {
        return date('Y-m-d', (int)(($value - 25569) * 86400));
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function memberAmount($value) {
    if ($value === '') return null;
    $value = str_replace(['RM', 'SGD', ',', ' ', '"'], '', strtoupper($value));
    return is_numeric($value) ? (float)$value : null;
}

function memberCompanyId(PDO $pdo, $value) {
    $value = strtoupper(trim((string)$value));
    $stmt = $pdo->prepare('SELECT id FROM companies WHERE UPPER(company_code) = :value OR UPPER(company_name) = :value LIMIT 1');
    $stmt->execute(['value' => $value]);
    $companyId = $stmt->fetchColumn();
    return $companyId === false ? null : (int)$companyId;
}

try {
    $file = $_FILES['members'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'csv', 'txt', 'tsv'], true)) {
        throw new RuntimeException('Only .xlsx, .csv, .txt and .tsv files are supported.');
    }

    $hash = hash_file('sha256', $file['tmp_name']);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $duplicate = $pdo->prepare("SELECT id, status FROM import_batches WHERE file_hash = :hash LIMIT 1");
    $duplicate->execute(['hash' => $hash]);
    $existingBatch = $duplicate->fetch();
    if ($existingBatch && $existingBatch['status'] === 'completed') {
        echo json_encode(['status' => 'success', 'members' => ['skipped' => true, 'message' => 'File already imported.']]);
        exit;
    }
    if ($existingBatch) {
        $pdo->prepare('UPDATE import_batches SET file_hash = NULL WHERE id = :id')->execute(['id' => $existingBatch['id']]);
    }

    if ($extension === 'xlsx') {
        $reader = new Xlsx();
    } else {
        $reader = new Csv();
        $delimiter = $_POST['delimiter'] ?? ',';
        $reader->setDelimiter($delimiter === "\t" ? "\t" : ($delimiter !== '' ? $delimiter : ','));
    }

    $rows = $reader->load($file['tmp_name'])->getActiveSheet()->toArray(null, true, true, false);
    if (count($rows) < 2) throw new RuntimeException('File is empty.');

    $headers = [];
    foreach ($rows[0] as $index => $header) {
        $headers[memberHeader($header)] = $index;
    }

    $companyNames = ['company', 'companycode', 'companyname'];
    $memberIdNames = ['memberid', 'membercode', 'memberno', 'id'];
    $hasCompany = false;
    foreach ($companyNames as $name) $hasCompany = $hasCompany || isset($headers[memberHeader($name)]);
    $hasMemberId = false;
    foreach ($memberIdNames as $name) $hasMemberId = $hasMemberId || isset($headers[memberHeader($name)]);
    if (!$hasCompany || !$hasMemberId) {
        throw new RuntimeException('Required columns missing. Header must include Company and Member ID.');
    }

    $companyId = memberCompanyId($pdo, memberValue($rows[1], $headers, $companyNames));
    if (!$companyId) throw new RuntimeException('Company code was not found in the companies table.');

    $batch = $pdo->prepare("INSERT INTO import_batches (company_id, file_type, original_filename, file_hash, total_rows, status)
        VALUES (:company_id, 'MEMBERS', :filename, :hash, :total_rows, 'processing')");
    $batch->execute([
        'company_id' => $companyId,
        'filename' => $file['name'],
        'hash' => $hash,
        'total_rows' => count($rows) - 1,
    ]);
    $batchId = (int)$pdo->lastInsertId();

    $sql = "INSERT INTO members (
        company_id, import_batch_id, member_code, member_name, nric, mobile_no, email, joined_date,
        sponsor_code, sponsor_name, status, cl_code, cl_name, occupation, date_of_birth,
        source_of_funds, estimated_monthly_income, gender, marital_status, current_rank,
        highest_rank
    ) VALUES (
        :company_id, :batch_id, :member_code, :member_name, :nric, :mobile_no, :email, :joined_date,
        :sponsor_code, :sponsor_name, :status, :cl_code, :cl_name, :occupation, :date_of_birth,
        :source_of_funds, :estimated_monthly_income, :gender, :marital_status, :current_rank,
        :highest_rank
    ) ON DUPLICATE KEY UPDATE
        import_batch_id = VALUES(import_batch_id), member_name = VALUES(member_name), nric = VALUES(nric),
        mobile_no = VALUES(mobile_no), email = VALUES(email), joined_date = VALUES(joined_date),
        sponsor_code = VALUES(sponsor_code), sponsor_name = VALUES(sponsor_name), status = VALUES(status),
        cl_code = VALUES(cl_code), cl_name = VALUES(cl_name), occupation = VALUES(occupation),
        date_of_birth = VALUES(date_of_birth), source_of_funds = VALUES(source_of_funds),
        estimated_monthly_income = VALUES(estimated_monthly_income), gender = VALUES(gender),
        marital_status = VALUES(marital_status), current_rank = VALUES(current_rank),
        highest_rank = VALUES(highest_rank)";
    $statement = $pdo->prepare($sql);

    $pdo->beginTransaction();
    $inserted = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach (array_slice($rows, 1) as $rowNumber => $row) {
        $line = $rowNumber + 2;
        $company = memberValue($row, $headers, $companyNames);
        $rowCompanyId = memberCompanyId($pdo, $company);
        $memberId = memberValue($row, $headers, $memberIdNames);
        if (!$rowCompanyId || $memberId === '') {
            $failed++;
            $errors[] = "Row {$line}: Valid Company and Member ID are required.";
            continue;
        }

        $check = $pdo->prepare('SELECT id FROM members WHERE company_id = :company_id AND member_code = :member_code');
        $check->execute(['company_id' => $rowCompanyId, 'member_code' => $memberId]);
        $isUpdate = (bool)$check->fetchColumn();

        try {
            $statement->execute([
                'company_id' => $rowCompanyId,
                'batch_id' => $batchId,
                'member_code' => $memberId,
                'member_name' => memberValue($row, $headers, ['nameasperic', 'name', 'fullname']),
                'nric' => memberValue($row, $headers, ['nric', 'ic', 'icno']),
                'mobile_no' => memberValue($row, $headers, ['mobileno', 'mobile', 'phoneno']),
                'email' => memberValue($row, $headers, ['email', 'emailaddress']),
                'joined_date' => memberDate(memberValue($row, $headers, ['joineddate', 'joindate', 'registrationdate'])),
                'sponsor_code' => memberValue($row, $headers, ['sponsorid', 'sponsorcode']),
                'sponsor_name' => memberValue($row, $headers, ['sponsorname']),
                'status' => memberValue($row, $headers, ['status']),
                'cl_code' => memberValue($row, $headers, ['clcode']),
                'cl_name' => memberValue($row, $headers, ['clname']),
                'occupation' => memberValue($row, $headers, ['occupation']),
                'date_of_birth' => memberDate(memberValue($row, $headers, ['dateofbirth', 'dob', 'birthdate'])),
                'source_of_funds' => memberValue($row, $headers, ['sourceoffunds']),
                'estimated_monthly_income' => memberAmount(memberValue($row, $headers, ['estimatedmonthlyincome', 'monthlyincome'])),
                'gender' => memberValue($row, $headers, ['gender', 'sex']),
                'marital_status' => memberValue($row, $headers, ['maritalstatus']),
                'current_rank' => memberValue($row, $headers, ['currentrank', 'rank']),
                'highest_rank' => memberValue($row, $headers, ['highestrank']),
            ]);
            $isUpdate ? $updated++ : $inserted++;
        } catch (Throwable $error) {
            $failed++;
            $errors[] = "Row {$line}: " . $error->getMessage();
        }
    }

    $pdo->commit();
    $pdo->prepare('UPDATE import_batches SET successful_rows = :successful, failed_rows = :failed, status = :status, imported_at = NOW() WHERE id = :id')
        ->execute(['successful' => $inserted + $updated, 'failed' => $failed, 'status' => $failed ? 'completed_with_errors' : 'completed', 'id' => $batchId]);
    echo json_encode([
        'status' => 'success',
        'members' => [
            'total' => count($rows) - 1,
            'inserted' => $inserted,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 100),
        ],
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
}
