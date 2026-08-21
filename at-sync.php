<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| CẤU HÌNH & XÁC ĐỊNH KHOẢNG THỜI GIAN
|--------------------------------------------------------------------------
*/

$defaultDays = 3;

$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;

try {
    if ($from && $to) {
        $fromDate = new DateTime($from . ' 00:00:00', new DateTimeZone('UTC'));
        $toDate   = new DateTime($to . ' 23:59:59', new DateTimeZone('UTC'));
    } else {
        $toDate   = new DateTime('now', new DateTimeZone('UTC'));
        $fromDate = clone $toDate;
        $fromDate->modify("-{$defaultDays} days");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date format',
        'example' => 'at-sync.php?from=2026-07-01&to=2026-07-31',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$since  = $fromDate->format('Y-m-d\TH:i:s\Z');
$until  = $toDate->format('Y-m-d\TH:i:s\Z');
$apiUrl = 'https://api.accesstrade.vn/v1/transactions';

$page          = 1;
$limit         = 100;
$totalReceived = 0;
$inserted      = 0;
$updated       = 0;
$skipped       = 0;

/*
|--------------------------------------------------------------------------
| KẾT NỐI DATABASE
|--------------------------------------------------------------------------
*/

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| PREPARE STATEMENTS
|--------------------------------------------------------------------------
*/

$check = $pdo->prepare("SELECT id, merchant FROM at_conversions WHERE transaction_id = :transaction_id LIMIT 1");

$insert = $pdo->prepare("
    INSERT INTO at_conversions (
        transaction_id, order_id, campaign_id, merchant, product_id, quantity,
        product_category, product_price, reward, sales_time, browser,
        conversion_platform, status, ip, referrer, click_time, is_confirmed,
        utm_source, utm_medium, utm_campaign, utm_content,
        sub1, sub2, sub3, sub4, customer_type, confirmed_date, raw_data
    ) VALUES (
        :transaction_id, :order_id, :campaign_id, :merchant, :product_id, :quantity,
        :product_category, :product_price, :reward, :sales_time, :browser,
        :conversion_platform, :status, :ip, :referrer, :click_time, :is_confirmed,
        :utm_source, :utm_medium, :utm_campaign, :utm_content,
        :sub1, :sub2, :sub3, :sub4, :customer_type, :confirmed_date, :raw_data
    )
");

$update = $pdo->prepare("
    UPDATE at_conversions SET
        order_id = :order_id, campaign_id = :campaign_id, merchant = :merchant,
        product_id = :product_id, quantity = :quantity, product_category = :product_category,
        product_price = :product_price, reward = :reward, sales_time = :sales_time,
        browser = :browser, conversion_platform = :conversion_platform, status = :status,
        ip = :ip, referrer = :referrer, click_time = :click_time, is_confirmed = :is_confirmed,
        utm_source = :utm_source, utm_medium = :utm_medium, utm_campaign = :utm_campaign,
        utm_content = :utm_content, sub1 = :sub1, sub2 = :sub2, sub3 = :sub3, sub4 = :sub4,
        customer_type = :customer_type, confirmed_date = :confirmed_date, raw_data = :raw_data
    WHERE transaction_id = :transaction_id
");

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function parseDateTime(?string $dateStr): ?string {
    if (!$dateStr) return null;
    try {
        $dt = new DateTime($dateStr);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function getUtm(array $transaction, string $key): ?string {
    $extraParams = $transaction['_extra']['parameters'] ?? [];
    if (!is_array($extraParams)) $extraParams = [];

    $utm = $transaction['_utm'] ?? [];
    if (!is_array($utm)) $utm = [];

    $value = $transaction[$key]
        ?? $transaction['_' . $key]
        ?? $utm[$key]
        ?? $extraParams[$key]
        ?? null;

    if ($value === null || is_array($value)) return null;

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function getSubParam(array $transaction, string $key): ?string {
    $root = $transaction[$key] ?? null;
    if ($root !== null && !is_array($root) && trim((string)$root) !== '') {
        return trim((string)$root);
    }

    $extra = $transaction['_extra'] ?? null;
    if (is_array($extra) && isset($extra['sub_params']) && is_array($extra['sub_params'])) {
        $val = $extra['sub_params'][$key] ?? null;
        if ($val !== null && !is_array($val) && trim((string)$val) !== '') {
            return trim((string)$val);
        }
    }

    if (isset($transaction['sub_params']) && is_array($transaction['sub_params'])) {
        $val = $transaction['sub_params'][$key] ?? null;
        if ($val !== null && !is_array($val) && trim((string)$val) !== '') {
            return trim((string)$val);
        }
    }

    return null;
}

/**
 * Bóc tách merchant với fallback đa tầng tương thích hoàn toàn với at-postback.php
 */
function resolveMerchant(array $transaction, ?string $productId): ?string {
    $merchant = $transaction['merchant']
        ?? $transaction['merchant_name']
        ?? $transaction['advertiser']
        ?? $transaction['advertiser_name']
        ?? null;

    if ($merchant !== null && !is_array($merchant)) {
        $val = trim((string)$merchant);
        if ($val !== '') {
            return $val;
        }
    }

    // Nếu vẫn không có merchant và product_id chứa "@", bóc tách theo format: PRODUCT_ID@MERCHANT@PRODUCT_ID
    if ($productId !== null && str_contains($productId, '@')) {
        $parts = explode('@', $productId);
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            return trim($parts[1]);
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| LOOP PAGINATION
|--------------------------------------------------------------------------
*/

try {
    while (true) {
        $params = [
            'since' => $since,
            'until' => $until,
            'page'  => $page,
            'limit' => $limit,
        ];

        $url = $apiUrl . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $config['at_token'],
                'Content-Type: application/json',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            throw new RuntimeException('CURL error: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('AccessTrade API HTTP ' . $httpCode . ': ' . $response);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON response');
        }

        $transactions = $json['data'] ?? [];
        if (!is_array($transactions) || empty($transactions)) {
            break;
        }

        foreach ($transactions as $transaction) {
            $totalReceived++;

            if (!is_array($transaction)) {
                $skipped++;
                continue;
            }

            // 1. Transaction ID: trim giá trị, nếu rỗng bỏ qua
            $rawTxId = $transaction['transaction_id'] ?? null;
            if ($rawTxId === null || is_array($rawTxId)) {
                $skipped++;
                continue;
            }
            $transactionId = trim((string)$rawTxId);
            if ($transactionId === '') {
                $skipped++;
                continue;
            }

            // 2. Product ID
            $productId = isset($transaction['product_id']) && !is_array($transaction['product_id'])
                ? (trim((string)$transaction['product_id']) !== '' ? trim((string)$transaction['product_id']) : null)
                : null;

            // 3. Merchant: đa tầng fallback
            $merchant = resolveMerchant($transaction, $productId);

            // 4. Order ID (Fallback: transaction_id)
            $orderId = isset($transaction['order_id']) && !is_array($transaction['order_id']) && trim((string)$transaction['order_id']) !== ''
                ? trim((string)$transaction['order_id'])
                : $transactionId;

            // 5. Campaign ID (Fallback: campaign_no)
            $campaignId = isset($transaction['campaign_id']) && !is_array($transaction['campaign_id']) && trim((string)$transaction['campaign_id']) !== ''
                ? trim((string)$transaction['campaign_id'])
                : (isset($transaction['campaign_no']) && !is_array($transaction['campaign_no']) && trim((string)$transaction['campaign_no']) !== '' ? trim((string)$transaction['campaign_no']) : null);

            // 6. Quantity (Fallback: product_quantity -> quantity -> 1)
            $quantity = isset($transaction['product_quantity']) && is_numeric($transaction['product_quantity'])
                ? (int)$transaction['product_quantity']
                : (isset($transaction['quantity']) && is_numeric($transaction['quantity']) ? (int)$transaction['quantity'] : 1);

            // 7. Product Price (Fallback: product_price -> transaction_value -> 0)
            $productPrice = $transaction['product_price'] ?? $transaction['transaction_value'] ?? 0;

            // 8. Reward (Fallback: commission -> reward -> 0)
            $reward = $transaction['commission'] ?? $transaction['reward'] ?? 0;

            // 9. Sales Time (Fallback: transaction_time -> sales_time)
            $salesTimeRaw = $transaction['transaction_time'] ?? $transaction['sales_time'] ?? null;
            $salesTime = parseDateTime(is_string($salesTimeRaw) ? $salesTimeRaw : null);

            // 10. Confirmed Date (Fallback: confirmed_time -> confirmed_date)
            $confirmedDateRaw = $transaction['confirmed_time'] ?? $transaction['confirmed_date'] ?? null;
            $confirmedDate = parseDateTime(is_string($confirmedDateRaw) ? $confirmedDateRaw : null);

            // 11. Browser (Fallback: _extra.browser -> browser)
            $browser = $transaction['_extra']['browser'] ?? $transaction['browser'] ?? null;
            if (is_string($browser)) {
                $browser = trim($browser) !== '' ? trim($browser) : null;
            } else {
                $browser = null;
            }

            // 12. Parse UTMs
            $utmSource   = getUtm($transaction, 'utm_source');
            $utmMedium   = getUtm($transaction, 'utm_medium');
            $utmCampaign = getUtm($transaction, 'utm_campaign');
            $utmContent  = getUtm($transaction, 'utm_content');

            // 13. Parse Sub Params
            $sub1 = getSubParam($transaction, 'sub1');
            $sub2 = getSubParam($transaction, 'sub2');
            $sub3 = getSubParam($transaction, 'sub3');
            $sub4 = getSubParam($transaction, 'sub4');

            // 14. Raw data: giữ nguyên JSON transaction gốc từ AccessTrade
            $rawData = json_encode(
                $transaction,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            // Kiểm tra record tồn tại trong DB
            $check->execute([':transaction_id' => $transactionId]);
            $existing = $check->fetch();

            // QUAN TRỌNG: Nếu UPDATE một transaction đã có, và merchant từ API hiện tại là null,
            // KHÔNG ĐƯỢC ghi đè NULL lên merchant đã có trong database!
            if ($existing && $merchant === null && !empty($existing['merchant'])) {
                $merchant = $existing['merchant'];
            }

            $values = [
                ':transaction_id'      => $transactionId,
                ':order_id'            => $orderId,
                ':campaign_id'         => $campaignId,
                ':merchant'            => $merchant,
                ':product_id'          => $productId,
                ':quantity'            => $quantity,
                ':product_category'    => isset($transaction['product_category']) && is_string($transaction['product_category']) ? trim($transaction['product_category']) : null,
                ':product_price'       => $productPrice,
                ':reward'              => $reward,
                ':sales_time'          => $salesTime,
                ':browser'             => $browser,
                ':conversion_platform' => isset($transaction['conversion_platform']) && is_string($transaction['conversion_platform']) ? trim($transaction['conversion_platform']) : null,
                ':status'              => $transaction['status'] ?? 0,
                ':ip'                  => isset($transaction['ip']) && is_string($transaction['ip']) ? trim($transaction['ip']) : null,
                ':referrer'            => isset($transaction['referrer']) && is_string($transaction['referrer']) ? trim($transaction['referrer']) : null,
                ':click_time'          => parseDateTime(isset($transaction['click_time']) && is_string($transaction['click_time']) ? $transaction['click_time'] : null),
                ':is_confirmed'        => $transaction['is_confirmed'] ?? 0,
                ':utm_source'          => $utmSource,
                ':utm_medium'          => $utmMedium,
                ':utm_campaign'        => $utmCampaign,
                ':utm_content'         => $utmContent,
                ':sub1'                => $sub1,
                ':sub2'                => $sub2,
                ':sub3'                => $sub3,
                ':sub4'                => $sub4,
                ':customer_type'       => isset($transaction['customer_type']) && is_string($transaction['customer_type']) ? trim($transaction['customer_type']) : null,
                ':confirmed_date'      => $confirmedDate,
                ':raw_data'            => $rawData,
            ];

            if ($existing) {
                $update->execute($values);
                $updated++;
            } else {
                $insert->execute($values);
                $inserted++;
            }
        }

        if (count($transactions) < $limit) {
            break;
        }

        $page++;
    }

    echo json_encode([
        'success'         => true,
        'message'         => 'AccessTrade synchronization completed',
        'period'          => ['since' => $since, 'until' => $until],
        'pages_processed' => $page,
        'received'        => $totalReceived,
        'inserted'        => $inserted,
        'updated'         => $updated,
        'skipped'         => $skipped,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Synchronization failed',
        'error'   => $e->getMessage(),
        'page'    => $page,
        'period'  => ['since' => $since, 'until' => $until],
    ], JSON_UNESCAPED_UNICODE);
}
