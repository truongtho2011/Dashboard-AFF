<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/normalizers/accesstrade.php';

echo "=== BẮT ĐẦU CHẠY UNIT TEST ACCESS TRADE NORMALIZER ===\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $name, bool $condition, string $details = ''): void
{
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo " [FAIL] $name: $details\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
// CASE 1: Payload có merchant trực tiếp
// -------------------------------------------------------------
$payload1 = [
    'transaction_id' => 'TX_DIRECT_01',
    'merchant'       => 'shopee_vn',
    'product_id'     => 'SKU_123',
    'product_price'  => 250000,
    'reward'         => 12500,
    'status'         => 1,
];
$res1 = normalize_accesstrade_conversion($payload1);
assert_test('CASE 1: Direct merchant', $res1 !== null && $res1['merchant'] === 'shopee_vn' && $res1['product_price'] === 250000.0);

// -------------------------------------------------------------
// CASE 2: Không có merchant nhưng product_id có dạng PRODUCT@MERCHANT@PRODUCT
// -------------------------------------------------------------
$payload2 = [
    'transaction_id' => 'TX_AT_SPLIT_02',
    'product_id'     => '64cf0805f1b00bbb5ab93f41@aeonmall_eshop@08815002',
    'product_price'  => 49000,
    'reward'         => 1588,
];
$res2 = normalize_accesstrade_conversion($payload2);
assert_test('CASE 2: Extract merchant from @ in product_id', $res2 !== null && $res2['merchant'] === 'aeonmall_eshop');

// -------------------------------------------------------------
// CASE 3: transaction_id có khoảng trắng đầu/cuối
// -------------------------------------------------------------
$payload3 = [
    'transaction_id' => '   TX_SPACE_03   ',
    'merchant'       => 'anta',
];
$res3 = normalize_accesstrade_conversion($payload3);
assert_test('CASE 3: Trim transaction_id whitespace', $res3 !== null && $res3['transaction_id'] === 'TX_SPACE_03');

// -------------------------------------------------------------
// CASE 4: sub_params nằm trong _extra.sub_params
// -------------------------------------------------------------
$payload4 = [
    'transaction_id' => 'TX_SUB_04',
    '_extra' => [
        'sub_params' => [
            'sub1' => 'google_ads',
            'sub4' => 'oneatweb',
        ]
    ]
];
$res4 = normalize_accesstrade_conversion($payload4);
assert_test('CASE 4: Parse sub_params from _extra', $res4 !== null && $res4['sub1'] === 'google_ads' && $res4['sub4'] === 'oneatweb' && $res4['sub2'] === null);

// -------------------------------------------------------------
// CASE 5: UTM nằm trong _extra.parameters
// -------------------------------------------------------------
$payload5 = [
    'transaction_id' => 'TX_UTM_05',
    '_extra' => [
        'parameters' => [
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'summer_sale',
            'utm_content'  => 'banner_top',
        ]
    ]
];
$res5 = normalize_accesstrade_conversion($payload5);
assert_test('CASE 5: Parse UTM from _extra.parameters', $res5 !== null && $res5['utm_source'] === 'google' && $res5['utm_campaign'] === 'summer_sale');

// -------------------------------------------------------------
// CASE 6: transaction_time dạng ISO8601
// -------------------------------------------------------------
$payload6 = [
    'transaction_id'   => 'TX_DATE_06',
    'transaction_time' => '2026-08-18T05:07:44+07:00',
    'confirmed_time'   => '2026-09-18T05:07:44',
];
$res6 = normalize_accesstrade_conversion($payload6);
assert_test('CASE 6: Normalize ISO8601 datetime', $res6 !== null && $res6['sales_time'] === '2026-08-18 05:07:44' && $res6['confirmed_date'] === '2026-09-18 05:07:44');

// -------------------------------------------------------------
// CASE 7: Payload CPL có product_price = 0 nhưng reward > 0
// -------------------------------------------------------------
$payload7 = [
    'transaction_id'    => 'TX_CPL_07',
    'merchant'          => 'vpbank3t_vaytinchap',
    'transaction_value' => 0,
    'commission'        => 250000,
    'status'            => 0,
];
$res7 = normalize_accesstrade_conversion($payload7);
assert_test('CASE 7: CPL zero price with reward', $res7 !== null && $res7['product_price'] === 0.0 && $res7['reward'] === 250000.0 && $res7['status'] === 0);

// -------------------------------------------------------------
// CASE 8: Payload không có transaction_id -> return null
// -------------------------------------------------------------
$payload8a = ['merchant' => 'test'];
$payload8b = ['transaction_id' => '   '];
$res8a = normalize_accesstrade_conversion($payload8a);
$res8b = normalize_accesstrade_conversion($payload8b);
assert_test('CASE 8: Missing or empty transaction_id returns null', $res8a === null && $res8b === null);

// -------------------------------------------------------------
// CASE 9: Payload có product_quantity
// -------------------------------------------------------------
$payload9 = [
    'transaction_id'   => 'TX_QTY_09',
    'product_quantity' => 5,
];
$res9 = normalize_accesstrade_conversion($payload9);
assert_test('CASE 9: Fallback product_quantity', $res9 !== null && $res9['quantity'] === 5);

// -------------------------------------------------------------
// CASE 10: Payload có merchant = null (KHÔNG ĐƯỢC phép truy cập DB)
// -------------------------------------------------------------
$payload10 = [
    'transaction_id' => 'TX_NO_MERCHANT_10',
    'product_id'     => 'SKU_WITHOUT_AT_SYMBOL',
];
$res10 = normalize_accesstrade_conversion($payload10);
assert_test('CASE 10: Merchant is null without DB call', $res10 !== null && $res10['merchant'] === null);

// -------------------------------------------------------------
// KIỂM TRA CONTRACT 28 KEYS
// -------------------------------------------------------------
$expectedKeys = [
    'transaction_id', 'order_id', 'campaign_id', 'merchant', 'product_id',
    'quantity', 'product_category', 'product_price', 'reward', 'sales_time',
    'browser', 'conversion_platform', 'status', 'ip', 'referrer',
    'click_time', 'is_confirmed', 'utm_source', 'utm_medium', 'utm_campaign',
    'utm_content', 'sub1', 'sub2', 'sub3', 'sub4', 'customer_type',
    'confirmed_date', 'raw_data'
];

$keysCount = count(array_keys($res1));
$diffKeys = array_diff($expectedKeys, array_keys($res1));
assert_test('CONTRACT: Exactly 28 keys returned', $keysCount === 28 && empty($diffKeys), "Count=$keysCount, Diff=" . json_encode($diffKeys));

echo "\n=== KẾT QUẢ TỔNG KẾT: $passCount PASSED, $failCount FAILED ===\n";
