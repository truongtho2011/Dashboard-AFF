<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function write_postback_log(array $requestData): void
{
    $log = [
        'time'          => date('Y-m-d H:i:s'),
        'timestamp'     => microtime(true),
        'method'        => $_SERVER['REQUEST_METHOD'] ?? null,
        'request_uri'   => $_SERVER['REQUEST_URI'] ?? null,
        'remote_addr'   => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'get'           => $_GET,
        'post'          => $_POST,
        'raw_body'      => file_get_contents('php://input'),
        'data'          => $requestData,
    ];

    @file_put_contents(
        __DIR__ . '/postback.log',
        json_encode(
            $log,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        ) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function get_request_headers_safe(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headerName = str_replace(
                ' ',
                '-',
                ucwords(
                    strtolower(
                        str_replace('_', ' ', substr($name, 5))
                    )
                )
            );
            $headers[$headerName] = $value;
        }
    }

    return $headers;
}

/**
 * Lấy tham số an toàn từ mảng data
 */
function get_param(array $data, string $key): ?string
{
    if (!array_key_exists($key, $data)) {
        return null;
    }

    if (is_array($data[$key])) {
        return json_encode(
            $data[$key],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    $value = trim((string)$data[$key]);

    return $value === '' ? null : $value;
}

/**
 * Lấy Sub parameter an toàn (Ưu tiên root, fallback nested _extra.sub_params)
 */
function get_sub_param(array $data, string $key): ?string
{
    // 1. Ưu tiên root ($data['sub1'], $data['sub2']...)
    $rootVal = get_param($data, $key);
    if ($rootVal !== null) {
        return $rootVal;
    }

    // 2. Nested trong _extra.sub_params
    $extra = $data['_extra'] ?? null;
    if (is_string($extra)) {
        $decoded = json_decode($extra, true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    if (is_array($extra)) {
        $subParams = $extra['sub_params'] ?? null;
        if (is_array($subParams) && array_key_exists($key, $subParams)) {
            $val = trim((string)$subParams[$key]);
            return $val === '' ? null : $val;
        }
    }

    // 3. Nested trực tiếp trong sub_params
    if (isset($data['sub_params']) && is_array($data['sub_params']) && array_key_exists($key, $data['sub_params'])) {
        $val = trim((string)$data['sub_params'][$key]);
        return $val === '' ? null : $val;
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Nhận & Xử lý dữ liệu request
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

// Parse Body nếu đối tác gửi JSON format
$jsonBodyData = [];
if (!empty($rawBody)) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBodyData = $decoded;
    }
}

// Merge GET + POST + JSON Payload
$data = array_merge($_GET, $_POST, $jsonBodyData);

// Ghi log ngay lập tức
write_postback_log([
    'params'  => $data,
    'headers' => get_request_headers_safe(),
    'rawBody' => $rawBody,
]);


/*
|--------------------------------------------------------------------------
| Thực thi Database
|--------------------------------------------------------------------------
*/

try {

    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] .
        ';dbname=' . $config['db_name'] .
        ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    if (empty($data) && trim((string)$rawBody) === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No request data received',
        ]);
        exit;
    }

    $transaction_id = get_param($data, 'transaction_id');

    $raw_data = json_encode(
        [
            'received_at' => date('Y-m-d H:i:s'),
            'method'      => $_SERVER['REQUEST_METHOD'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'get'         => $_GET,
            'post'        => $_POST,
            'headers'     => get_request_headers_safe(),
            'raw_body'    => $rawBody,
            'merged_data' => $data,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($transaction_id === null) {
        echo json_encode([
            'success' => true,
            'message' => 'Request logged but transaction_id missing',
        ]);
        exit;
    }

    // Kiểm tra transaction_id
    $check = $pdo->prepare("
        SELECT id
        FROM at_conversions
        WHERE transaction_id = :transaction_id
        LIMIT 1
    ");

    $check->execute([':transaction_id' => $transaction_id]);
    $existing = $check->fetch();

    // 1. Order ID (Fallback: transaction_id)
    $order_id = get_param($data, 'order_id') ?? $transaction_id;

    // 2. Campaign ID (Fallback: campaign_no)
    $campaign_id = get_param($data, 'campaign_id') ?? get_param($data, 'campaign_no');

    // 3. Product ID
    $product_id = get_param($data, 'product_id');

    // 4. Merchant: Ưu tiên merchant -> merchant_name -> advertiser -> advertiser_name
    $merchant = get_param($data, 'merchant')
        ?? get_param($data, 'merchant_name')
        ?? get_param($data, 'advertiser')
        ?? get_param($data, 'advertiser_name');

    // Nếu vẫn không có merchant và product_id chứa "@", bóc tách theo format: PRODUCT_ID@MERCHANT@PRODUCT_ID
    if ($merchant === null && $product_id !== null && str_contains($product_id, '@')) {
        $parts = explode('@', $product_id);
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            $merchant = trim($parts[1]);
        }
    }

    // 5. Product Price (Fallback: transaction_value -> 0)
    $product_price = get_param($data, 'product_price') ?? get_param($data, 'transaction_value') ?? 0;

    // 6. Reward (Fallback: commission -> 0)
    $reward = get_param($data, 'reward') ?? get_param($data, 'commission') ?? 0;

    // 7. Sales Time (Fallback: transaction_time)
    $sales_time = get_param($data, 'sales_time') ?? get_param($data, 'transaction_time');

    // 8. Confirmed Date (Fallback: confirmed_time)
    $confirmed_date = get_param($data, 'confirmed_date') ?? get_param($data, 'confirmed_time');

    // 9. Sub Parameters (Ưu tiên root, fallback _extra.sub_params)
    $sub1 = get_sub_param($data, 'sub1');
    $sub2 = get_sub_param($data, 'sub2');
    $sub3 = get_sub_param($data, 'sub3');
    $sub4 = get_sub_param($data, 'sub4');

    $paramsToBind = [
        ':transaction_id'      => $transaction_id,
        ':order_id'            => $order_id,
        ':campaign_id'         => $campaign_id,
        ':merchant'            => $merchant,
        ':product_id'          => $product_id,
        ':quantity'            => get_param($data, 'quantity'),
        ':product_category'    => get_param($data, 'product_category'),
        ':product_price'       => $product_price,
        ':reward'              => $reward,
        ':sales_time'          => $sales_time,
        ':browser'             => get_param($data, 'browser'),
        ':conversion_platform' => get_param($data, 'conversion_platform'),
        ':status'              => get_param($data, 'status'),
        ':ip'                  => get_param($data, 'ip'),
        ':referrer'            => get_param($data, 'referrer'),
        ':click_time'          => get_param($data, 'click_time'),
        ':is_confirmed'        => get_param($data, 'is_confirmed'),
        ':utm_source'          => get_param($data, 'utm_source'),
        ':utm_medium'          => get_param($data, 'utm_medium'),
        ':utm_campaign'        => get_param($data, 'utm_campaign'),
        ':utm_content'         => get_param($data, 'utm_content'),
        ':sub1'                => $sub1,
        ':sub2'                => $sub2,
        ':sub3'                => $sub3,
        ':sub4'                => $sub4,
        ':customer_type'       => get_param($data, 'customer_type'),
        ':confirmed_date'      => $confirmed_date,
        ':raw_data'            => $raw_data,
    ];

    if ($existing) {
        // UPDATE
        $sql = "
            UPDATE at_conversions SET
                order_id = :order_id,
                campaign_id = :campaign_id,
                merchant = :merchant,
                product_id = :product_id,
                quantity = :quantity,
                product_category = :product_category,
                product_price = :product_price,
                reward = :reward,
                sales_time = :sales_time,
                browser = :browser,
                conversion_platform = :conversion_platform,
                status = :status,
                ip = :ip,
                referrer = :referrer,
                click_time = :click_time,
                is_confirmed = :is_confirmed,
                utm_source = :utm_source,
                utm_medium = :utm_medium,
                utm_campaign = :utm_campaign,
                utm_content = :utm_content,
                sub1 = :sub1,
                sub2 = :sub2,
                sub3 = :sub3,
                sub4 = :sub4,
                customer_type = :customer_type,
                confirmed_date = :confirmed_date,
                raw_data = :raw_data
            WHERE transaction_id = :transaction_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsToBind);

        echo json_encode([
            'success' => true,
            'message' => 'Conversion updated',
            'id'      => $existing['id'],
        ]);
        exit;
    }

    // INSERT
    $sql = "
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
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramsToBind);

    echo json_encode([
        'success'        => true,
        'message'        => 'Postback received',
        'id'             => $pdo->lastInsertId(),
        'transaction_id' => $transaction_id,
    ]);

} catch (Throwable $e) {

    @file_put_contents(
        __DIR__ . '/postback.log',
        json_encode(
            [
                'time'    => date('Y-m-d H:i:s'),
                'type'    => 'ERROR',
                'message' => $e->getMessage(),
                'data'    => $data ?? [],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
}
