<?php

declare(strict_types=1);

/**
 * Conversion Repository
 *
 * Chịu trách nhiệm duy nhất:
 * - INSERT conversion mới
 * - UPDATE conversion đã tồn tại
 * - Bảo vệ merchant hiện có khỏi bị ghi đè NULL
 *
 * Input phải là normalized data theo AccessTrade/Affiliate Data Contract.
 */

/**
 * Lưu một conversion vào bảng at_conversions.
 *
 * @return string 'inserted' hoặc 'updated'
 *
 * @throws Throwable Khi database operation thất bại.
 */
function save_conversion(PDO $pdo, array $data): string
{
    /*
    |--------------------------------------------------------------------------
    | REQUIRED CONTRACT
    |--------------------------------------------------------------------------
    */

    $requiredKeys = [
        'transaction_id',
        'order_id',
        'campaign_id',
        'merchant',
        'product_id',
        'quantity',
        'product_category',
        'product_price',
        'reward',
        'sales_time',
        'browser',
        'conversion_platform',
        'status',
        'ip',
        'referrer',
        'click_time',
        'is_confirmed',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'sub1',
        'sub2',
        'sub3',
        'sub4',
        'customer_type',
        'confirmed_date',
        'raw_data',
    ];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "Missing normalized conversion field: {$key}"
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSACTION ID
    |--------------------------------------------------------------------------
    */

    $transactionId = trim((string) $data['transaction_id']);

    if ($transactionId === '') {
        throw new InvalidArgumentException(
            'transaction_id cannot be empty'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING RECORD
    |--------------------------------------------------------------------------
    |
    | Đây là query mà at-sync.php hiện tại đã sử dụng.
    | Chỉ mở rộng SELECT thêm merchant để bảo vệ dữ liệu.
    |
    */

    $check = $pdo->prepare(
        'SELECT id, merchant
         FROM at_conversions
         WHERE transaction_id = :transaction_id
         LIMIT 1'
    );

    $check->execute([
        ':transaction_id' => $transactionId,
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | MERCHANT PROTECTION
    |--------------------------------------------------------------------------
    |
    | Nếu API/webhook lần này không có merchant nhưng DB đã có merchant,
    | giữ nguyên merchant cũ.
    |
    */

    $merchant = $data['merchant'];

    if (
        $existing
        && ($merchant === null || trim((string) $merchant) === '')
        && !empty($existing['merchant'])
    ) {
        $merchant = $existing['merchant'];
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZED VALUES
    |--------------------------------------------------------------------------
    */

    $values = [
        ':transaction_id'      => $transactionId,
        ':order_id'            => $data['order_id'],
        ':campaign_id'         => $data['campaign_id'],
        ':merchant'            => $merchant,
        ':product_id'          => $data['product_id'],
        ':quantity'            => (int) $data['quantity'],
        ':product_category'    => $data['product_category'],
        ':product_price'       => $data['product_price'],
        ':reward'              => $data['reward'],
        ':sales_time'          => $data['sales_time'],
        ':browser'             => $data['browser'],
        ':conversion_platform' => $data['conversion_platform'],
        ':status'              => (int) $data['status'],
        ':ip'                  => $data['ip'],
        ':referrer'            => $data['referrer'],
        ':click_time'          => $data['click_time'],
        ':is_confirmed'        => (int) $data['is_confirmed'],
        ':utm_source'          => $data['utm_source'],
        ':utm_medium'          => $data['utm_medium'],
        ':utm_campaign'        => $data['utm_campaign'],
        ':utm_content'         => $data['utm_content'],
        ':sub1'                => $data['sub1'],
        ':sub2'                => $data['sub2'],
        ':sub3'                => $data['sub3'],
        ':sub4'                => $data['sub4'],
        ':customer_type'       => $data['customer_type'],
        ':confirmed_date'      => $data['confirmed_date'],
        ':raw_data'            => $data['raw_data'],
    ];

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    if (!$existing) {
        $insert = $pdo->prepare(
            'INSERT INTO at_conversions (
                transaction_id,
                order_id,
                campaign_id,
                merchant,
                product_id,
                quantity,
                product_category,
                product_price,
                reward,
                sales_time,
                browser,
                conversion_platform,
                status,
                ip,
                referrer,
                click_time,
                is_confirmed,
                utm_source,
                utm_medium,
                utm_campaign,
                utm_content,
                sub1,
                sub2,
                sub3,
                sub4,
                customer_type,
                confirmed_date,
                raw_data
            ) VALUES (
                :transaction_id,
                :order_id,
                :campaign_id,
                :merchant,
                :product_id,
                :quantity,
                :product_category,
                :product_price,
                :reward,
                :sales_time,
                :browser,
                :conversion_platform,
                :status,
                :ip,
                :referrer,
                :click_time,
                :is_confirmed,
                :utm_source,
                :utm_medium,
                :utm_campaign,
                :utm_content,
                :sub1,
                :sub2,
                :sub3,
                :sub4,
                :customer_type,
                :confirmed_date,
                :raw_data
            )'
        );

        $insert->execute($values);

        return 'inserted';
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    $update = $pdo->prepare(
        'UPDATE at_conversions SET
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
         WHERE transaction_id = :transaction_id'
    );

    $update->execute($values);

    return 'updated';
}
