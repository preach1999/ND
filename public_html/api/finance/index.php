<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const DEFAULT_PERIOD = '2026-08';
const OPENING_BALANCE_CENTAVOS = 154627454;

function reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function reject(string $message, int $status = 400): never {
    reply(['error' => $message], $status);
}

function textValue(mixed $value, int $limit = 180): string {
    return is_string($value) ? mb_substr(trim($value), 0, $limit) : '';
}

function periodKey(mixed $value): string {
    return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
        ? $value : DEFAULT_PERIOD;
}

function periodDate(string $period): DateTimeImmutable {
    return DateTimeImmutable::createFromFormat('!Y-m', $period, new DateTimeZone('UTC'))
        ?: new DateTimeImmutable(DEFAULT_PERIOD . '-01', new DateTimeZone('UTC'));
}

function nextPeriod(string $period): string {
    return periodDate($period)->modify('+1 month')->format('Y-m');
}

function config(): array {
    $file = dirname(__DIR__, 3) . '/app_config/config.php';
    if (!is_file($file)) reject('The application has not been configured yet.', 503);
    $value = require $file;
    if (!is_array($value)) reject('The application configuration is invalid.', 503);
    return $value;
}

function credentials(): array {
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+(.+)$/i', $header, $match)) {
        $decoded = base64_decode($match[1], true);
        if ($decoded !== false && str_contains($decoded, ':')) return explode(':', $decoded, 2);
    }
    return ['', ''];
}

function authenticate(array $configuration): string {
    [$username, $password] = credentials();
    $expectedUser = (string)($configuration['security']['username'] ?? '');
    $expectedHash = strtolower((string)($configuration['security']['password_sha256'] ?? ''));
    $valid = $expectedUser !== ''
        && preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1
        && hash_equals($expectedUser, $username)
        && hash_equals($expectedHash, hash('sha256', $password));
    if (!$valid) {
        header('WWW-Authenticate: Basic realm="N&D Financial System", charset="UTF-8"');
        reject('Sign in is required.', 401);
    }
    return $username;
}

function connect(array $configuration): PDO {
    $db = $configuration['database'] ?? [];
    $host = (string)($db['host'] ?? 'localhost');
    $port = (int)($db['port'] ?? 3306);
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    if ($name === '' || $user === '') reject('The database configuration is incomplete.', 503);
    try {
        return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, (string)($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    } catch (PDOException) {
        reject('The financial database is temporarily unavailable.', 503);
    }
}

function install(PDO $pdo): void {
    $schema = [
        "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, type ENUM('sale','purchase','expense') NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY categories_type_name_unique (type,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS monthly_periods (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, period_key CHAR(7) NOT NULL, label VARCHAR(40) NOT NULL, opening_balance_centavos BIGINT NOT NULL, closing_balance_centavos BIGINT NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open', closed_at TIMESTAMP NULL, closed_by VARCHAR(100) NULL, UNIQUE KEY monthly_periods_period_key_unique (period_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transaction_number VARCHAR(40) NOT NULL, transaction_date DATE NOT NULL, type ENUM('sale','purchase','expense') NOT NULL, category VARCHAR(80) NOT NULL, description VARCHAR(180) NOT NULL, amount_centavos BIGINT UNSIGNED NOT NULL, account VARCHAR(60) NOT NULL DEFAULT 'Cash', reference_number VARCHAR(80) NULL, notes VARCHAR(500) NULL, status ENUM('active','voided') NOT NULL DEFAULT 'active', void_reason VARCHAR(180) NULL, source_row INT NULL, created_by VARCHAR(100) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY transactions_number_unique (transaction_number), KEY transactions_date_status_index (transaction_date,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, action VARCHAR(50) NOT NULL, entity VARCHAR(50) NOT NULL, entity_id BIGINT UNSIGNED NULL, summary VARCHAR(255) NOT NULL, old_values JSON NULL, new_values JSON NULL, user_label VARCHAR(100) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY audit_logs_created_index (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($schema as $sql) $pdo->exec($sql);

    $check = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $check->execute(['workbook_seed_v1']);
    if ($check->fetchColumn() !== false) return;

    $seedFile = dirname(__DIR__, 3) . '/app_config/seed.json';
    $seed = json_decode((string)file_get_contents($seedFile), true, 512, JSON_THROW_ON_ERROR);
    $groups = [
        'sale' => ['Daily Sales', 'Other Income'],
        'purchase' => ['Fuel Products', 'Inventory', 'Equipment', 'Other Purchases'],
        'expense' => ['Payroll', 'Fuel / Transportation', 'Office Supplies', 'Utilities', 'Government Fees', 'Insurance', 'Maintenance', 'Meals', 'Bank Payments', 'Other Expenses'],
    ];
    $pdo->beginTransaction();
    try {
        $category = $pdo->prepare('INSERT IGNORE INTO categories (name,type,active) VALUES (?,?,1)');
        foreach ($groups as $type => $names) foreach ($names as $name) $category->execute([$name, $type]);
        $pdo->prepare("INSERT IGNORE INTO monthly_periods (period_key,label,opening_balance_centavos,status) VALUES ('2026-08','August 2026',?,'open')")->execute([OPENING_BALANCE_CENTAVOS]);
        $insert = $pdo->prepare("INSERT IGNORE INTO transactions (transaction_number,transaction_date,type,category,description,amount_centavos,account,status,source_row,created_by) VALUES (?,?,?,?,?,?,'Cash','active',?,'Workbook import')");
        foreach ($seed['transactions'] as $index => $record) {
            [$date,$type,$categoryName,$description,$amount,$sourceRow] = $record;
            $insert->execute(['ND-202608-' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT), $date, $type, $categoryName, $description, $amount, $sourceRow]);
        }
        $details = json_encode(['file'=>'N&d Com. Dev. Inc. Financial Report 2026.xlsx','records'=>count($seed['transactions']),'includedPreviouslyOmittedSaleCentavos'=>7012400], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO audit_logs (action,entity,summary,new_values,user_label) VALUES ('workbook_imported','workbook',?,?,'System')")->execute(['Imported 64 August 2026 records and replaced the broken running-balance formula chain.', $details]);
        $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)')->execute(['workbook_seed_v1', gmdate('c')]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function normalized(array $row): array {
    foreach (['id','amount_centavos','source_row','opening_balance_centavos','closing_balance_centavos','active','transaction_count','total_centavos'] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int)$row[$key];
    }
    return $row;
}

function allRows(PDOStatement $statement): array {
    return array_map('normalized', $statement->fetchAll());
}

function snapshot(PDO $pdo, string $period, array $configuration): array {
    $start = $period . '-01';
    $end = nextPeriod($period) . '-01';
    $query = $pdo->prepare('SELECT * FROM monthly_periods WHERE period_key=?');
    $query->execute([$period]);
    $month = $query->fetch();
    $month = normalized($month ?: ['period_key'=>$period,'label'=>periodDate($period)->format('F Y'),'opening_balance_centavos'=>0,'status'=>'open']);

    $query = $pdo->prepare('SELECT * FROM transactions WHERE transaction_date>=? AND transaction_date<? ORDER BY transaction_date DESC,id DESC');
    $query->execute([$start, $end]);
    $transactions = allRows($query);
    $categories = allRows($pdo->query('SELECT * FROM categories WHERE active=1 ORDER BY type,name'));
    $periods = allRows($pdo->query('SELECT * FROM monthly_periods ORDER BY period_key DESC'));
    $audit = allRows($pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 30'));
    $query = $pdo->prepare("SELECT category,type,SUM(amount_centavos) total_centavos,COUNT(*) transaction_count FROM transactions WHERE transaction_date>=? AND transaction_date<? AND status='active' GROUP BY category,type ORDER BY total_centavos DESC");
    $query->execute([$start, $end]);
    $breakdown = allRows($query);

    $active = array_values(array_filter($transactions, fn(array $row): bool => $row['status'] === 'active'));
    $totals = ['sale'=>0,'purchase'=>0,'expense'=>0];
    foreach ($active as $row) $totals[$row['type']] += (int)$row['amount_centavos'];
    $opening = (int)($month['opening_balance_centavos'] ?? 0);
    $net = $totals['sale'] - $totals['purchase'] - $totals['expense'];
    return [
        'company'=>$configuration['company'], 'period'=>$month, 'periods'=>$periods,
        'transactions'=>$transactions, 'categories'=>$categories, 'auditLogs'=>$audit, 'categoryBreakdown'=>$breakdown,
        'summary'=>[
            'openingBalanceCentavos'=>$opening, 'salesCentavos'=>$totals['sale'], 'purchasesCentavos'=>$totals['purchase'],
            'expensesCentavos'=>$totals['expense'], 'totalOutflowCentavos'=>$totals['purchase']+$totals['expense'],
            'netChangeCentavos'=>$net, 'currentBalanceCentavos'=>$opening+$net,
            'transactionCount'=>count($active), 'voidedCount'=>count($transactions)-count($active),
        ],
        'checks'=>[
            'hasTransactions'=>count($active)>0,
            'allCategorized'=>count(array_filter($active, fn(array $row): bool => $row['category']===''))===0,
            'allDescriptionsComplete'=>count(array_filter($active, fn(array $row): bool => $row['description']===''))===0,
            'workbookFormulaIssueResolved'=>$period!=='2026-08'||$totals['sale']===245288670,
        ],
    ];
}

function assertOpen(PDO $pdo, string $period): void {
    $query = $pdo->prepare('SELECT status FROM monthly_periods WHERE period_key=?');
    $query->execute([$period]);
    $status = $query->fetchColumn();
    if ($status === false) throw new RuntimeException('The selected month has not been opened yet.');
    if ($status !== 'open') throw new RuntimeException('This month is already closed.');
}

function transactionInput(array $payload): array {
    $type = textValue($payload['type'] ?? '', 20);
    $date = textValue($payload['transactionDate'] ?? '', 10);
    $category = textValue($payload['category'] ?? '', 80);
    $description = textValue($payload['description'] ?? '', 180);
    $amount = filter_var($payload['amountCentavos'] ?? null, FILTER_VALIDATE_INT);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!in_array($type, ['sale','purchase','expense'], true)) throw new RuntimeException('Choose Sales, Purchase, or Expense.');
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid transaction date.');
    if ($category === '') throw new RuntimeException('Choose a category.');
    if ($description === '') throw new RuntimeException('Enter a description.');
    if ($amount === false || $amount <= 0) throw new RuntimeException('Enter an amount greater than zero.');
    return ['type'=>$type,'date'=>$date,'category'=>$category,'description'=>$description,'amount'=>$amount,
        'account'=>textValue($payload['account'] ?? '',60) ?: 'Cash',
        'reference'=>textValue($payload['referenceNumber'] ?? '',80) ?: null,
        'notes'=>textValue($payload['notes'] ?? '',500) ?: null];
}

try {
    $configuration = config();
    $actor = authenticate($configuration);
    $pdo = connect($configuration);
    install($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') reply(snapshot($pdo, periodKey($_GET['period'] ?? null), $configuration));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reject('Method not allowed.', 405);
    if (($_SERVER['HTTP_X_ND_REQUEST'] ?? '') !== '1') reject('The request could not be verified.', 403);
    $payload = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Invalid request.');
    $action = textValue($payload['action'] ?? '', 40);

    if ($action === 'create_transaction') {
        $record = transactionInput($payload);
        $period = substr($record['date'],0,7); assertOpen($pdo,$period);
        $number = 'ND-' . str_replace('-','',$record['date']) . '-' . strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO transactions (transaction_number,transaction_date,type,category,description,amount_centavos,account,reference_number,notes,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'active',?)")
            ->execute([$number,$record['date'],$record['type'],$record['category'],$record['description'],$record['amount'],$record['account'],$record['reference'],$record['notes'],$actor]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_logs (action,entity,entity_id,summary,new_values,user_label) VALUES ('transaction_created','transaction',?,?,?,?)")
            ->execute([$id,'Recorded '.$record['type'].': '.$record['description'],json_encode($record,JSON_UNESCAPED_UNICODE),$actor]);
        $pdo->commit(); reply(snapshot($pdo,$period,$configuration),201);
    }

    if ($action === 'update_transaction') {
        $id=filter_var($payload['id']??null,FILTER_VALIDATE_INT); if ($id===false||$id<=0) throw new RuntimeException('Transaction not found.');
        $query=$pdo->prepare('SELECT * FROM transactions WHERE id=?'); $query->execute([$id]); $old=$query->fetch();
        if (!$old||$old['status']!=='active') throw new RuntimeException('Only active transactions can be edited.');
        $period=substr((string)$old['transaction_date'],0,7); assertOpen($pdo,$period); $record=transactionInput($payload);
        if (substr($record['date'],0,7)!==$period) throw new RuntimeException('Move the transaction date only within the same month.');
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE transactions SET transaction_date=?,type=?,category=?,description=?,amount_centavos=?,account=?,reference_number=?,notes=? WHERE id=?')
            ->execute([$record['date'],$record['type'],$record['category'],$record['description'],$record['amount'],$record['account'],$record['reference'],$record['notes'],$id]);
        $pdo->prepare("INSERT INTO audit_logs (action,entity,entity_id,summary,old_values,new_values,user_label) VALUES ('transaction_updated','transaction',?,?,?,?,?)")
            ->execute([$id,'Updated '.$record['description'],json_encode($old,JSON_UNESCAPED_UNICODE),json_encode($record,JSON_UNESCAPED_UNICODE),$actor]);
        $pdo->commit(); reply(snapshot($pdo,$period,$configuration));
    }

    if ($action === 'void_transaction') {
        $id=filter_var($payload['id']??null,FILTER_VALIDATE_INT); $reason=textValue($payload['reason']??'',180);
        if ($id===false||$id<=0||$reason==='') throw new RuntimeException('Select a transaction and a reason for voiding.');
        $query=$pdo->prepare('SELECT * FROM transactions WHERE id=?'); $query->execute([$id]); $old=$query->fetch();
        if (!$old||$old['status']!=='active') throw new RuntimeException('This transaction is no longer active.');
        $period=substr((string)$old['transaction_date'],0,7); assertOpen($pdo,$period); $pdo->beginTransaction();
        $pdo->prepare("UPDATE transactions SET status='voided',void_reason=? WHERE id=?")->execute([$reason,$id]);
        $pdo->prepare("INSERT INTO audit_logs (action,entity,entity_id,summary,old_values,new_values,user_label) VALUES ('transaction_voided','transaction',?,?,?,?,?)")
            ->execute([$id,'Voided '.$old['description'].': '.$reason,json_encode($old,JSON_UNESCAPED_UNICODE),json_encode(['status'=>'voided','reason'=>$reason]),$actor]);
        $pdo->commit(); reply(snapshot($pdo,$period,$configuration));
    }

    if ($action === 'create_category') {
        $name=textValue($payload['name']??'',80); $type=textValue($payload['type']??'',20); $period=periodKey($payload['periodKey']??null);
        if (mb_strlen($name)<2) throw new RuntimeException('Enter a clear category name.');
        if (!in_array($type,['sale','purchase','expense'],true)) throw new RuntimeException('Choose where this category will be used.');
        $pdo->beginTransaction(); $query=$pdo->prepare('INSERT IGNORE INTO categories (name,type,active) VALUES (?,?,1)'); $query->execute([$name,$type]);
        if ($query->rowCount()===0) { $pdo->rollBack(); throw new RuntimeException('That category already exists.'); }
        $pdo->prepare("INSERT INTO audit_logs (action,entity,summary,new_values,user_label) VALUES ('category_created','category',?,?,?)")
            ->execute(["Added {$name} for {$type} transactions",json_encode(['name'=>$name,'type'=>$type]),$actor]);
        $pdo->commit(); reply(snapshot($pdo,$period,$configuration),201);
    }

    if ($action === 'close_period') {
        $period=periodKey($payload['periodKey']??null); assertOpen($pdo,$period); $current=snapshot($pdo,$period,$configuration);
        if (!$current['checks']['hasTransactions']) throw new RuntimeException('Record at least one transaction before closing the month.');
        if (in_array(false,$current['checks'],true)) throw new RuntimeException('Complete the month-end checks first.');
        $following=nextPeriod($period); $closing=$current['summary']['currentBalanceCentavos']; $pdo->beginTransaction();
        $pdo->prepare("UPDATE monthly_periods SET closing_balance_centavos=?,status='closed',closed_at=CURRENT_TIMESTAMP,closed_by=? WHERE period_key=? AND status='open'")->execute([$closing,$actor,$period]);
        $pdo->prepare("INSERT IGNORE INTO monthly_periods (period_key,label,opening_balance_centavos,status) VALUES (?,?,?,'open')")->execute([$following,periodDate($following)->format('F Y'),$closing]);
        $summary='Closed '.periodDate($period)->format('F Y').' and carried the balance to '.periodDate($following)->format('F Y').'.';
        $pdo->prepare("INSERT INTO audit_logs (action,entity,summary,new_values,user_label) VALUES ('period_closed','monthly_period',?,?,?)")
            ->execute([$summary,json_encode(['periodKey'=>$period,'followingPeriod'=>$following,'closingBalanceCentavos'=>$closing]),$actor]);
        $pdo->commit(); reply(snapshot($pdo,$period,$configuration));
    }
    reject('Unknown action.');
} catch (JsonException) {
    reject('Invalid JSON request.');
} catch (RuntimeException $error) {
    if (isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction()) $pdo->rollBack();
    reject($error->getMessage());
} catch (Throwable $error) {
    if (isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction()) $pdo->rollBack();
    error_log('N&D Financial System: '.$error->getMessage());
    reject('The request could not be completed.',500);
}
