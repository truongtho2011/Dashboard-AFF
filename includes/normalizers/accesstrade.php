<?php

declare(strict_types=1);

/**
 * AccessTrade Conversion Normalizer
 *
 * Chuyển đổi payload từ AccessTrade (Webhook hoặc API Sync) thành Data Contract chuẩn 28 trường.
 * Hàm thuần túy (Pure Function), không truy cập Database và không có side-effects.
 */

if (!function_exists('normalize_accesstrade_conversion')) {

    /**
     * Parse chuỗi Datetime thành định dạng chuẩn Y-m-d H:i:s
     */
    function at_norm_datetime(?string $dateStr): ?string
    {
        if ($dateStr === null || trim($dateStr) === '') {
            return null;
        }

        try {
            $dt = new DateTime(trim($dateStr));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Trích xuất giá trị chuỗi an toàn (loại bỏ khoảng trắng, trả về null nếu rỗng)
     */
    function at_norm_string($val): ?string
    {
        if ($val === null || is_array($val)) {
            return null;
        }

        $str = trim((string)$val);
        return $str === '' ? null : $str;
    }

    /**
     * Bóc tách tham số UTM từ đa nguồn trong payload AccessTrade
     */
    function at_norm_utm(array $payload, string $key): ?string
    {
        $extraParams = $payload['_extra']['parameters'] ?? [];
        if (!is_array($extraParams)) {
            $extraParams = [];
        }

        $utm = $payload['_utm'] ?? [];
        if (!is_array($utm)) {
            $utm = [];
        }

        $val = $payload[$key]
            ?? $payload['_' . $key]
            ?? $utm[$key]
            ?? $extraParams[$key]
            ?? null;

        return at_norm_string($val);
    }

    /**
     * Bóc tách tham số Sub parameter (sub1-sub4) từ root hoặc _extra.sub_params
     */
    function at_norm_sub_param(array $payload, string $key): ?string
    {
        // 1. Root
        $rootVal = at_norm_string($payload[$key] ?? null);
        if ($rootVal !== null) {
            return $rootVal;
        }

        // 2. Nested trong _extra.sub_params
        $extra = $payload['_extra'] ?? null;
        if (is_string($extra)) {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }
        if (is_array($extra) && isset($extra['sub_params']) && is_array($extra['sub_params'])) {
            $val = at_norm_string($extra['sub_params'][$key] ?? null);
            if ($val !== null) {
                return $val;
            }
        }

        // 3. Nested trực tiếp trong sub_params
        if (isset($payload['sub_params']) && is_array($payload['sub_params'])) {
            $val = at_norm_string($payload['sub_params'][$key] ?? null);
            if ($val !== null) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Bóc tách merchant với fallback đa tầng và bóc tách ký tự @ từ product_id
     */
    function at_norm_merchant(array $payload, ?string $productId): ?string
    {
        // 1. Ưu tiên alias
        $merchant = $payload['merchant']
            ?? $payload['merchant_name']
            ?? $payload['advertiser']
            ?? $payload['advertiser_name']
            ?? null;

        $merchantVal = at_norm_string($merchant);
        if ($merchantVal !== null) {
            return $merchantVal;
        }

        // 2. Fallback bóc tách từ product_id dạng PRODUCT_ID@MERCHANT@PRODUCT_ID
        if ($productId !== null && str_contains($productId, '@')) {
            $parts = explode('@', $productId);
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                return trim($parts[1]);
            }
        }

        return null;
    }

    /**
     * Hàm chuẩn hóa AccessTrade Conversion chính
     *
     * @param array $payload Dữ liệu thô từ Webhook hoặc API Sync của AccessTrade
     * @param array $context Ngữ cảnh request (ip, referrer, source, headers, ...)
     * @return array|null Trả về mảng đúng 28 key chuẩn, hoặc null nếu transaction_id rỗng
     */
    function normalize_accesstrade_conversion(array $payload, array $context = []): ?array
    {
        // 1. Transaction ID: Bắt buộc, trim, rỗng trả về null
        $transactionId = at_norm_string($payload['transaction_id'] ?? null);
        if ($transactionId === null) {
            return null;
        }

        // 2. Product ID
        $productId = at_norm_string($payload['product_id'] ?? null);

        // 3. Merchant
        $merchant = at_norm_merchant($payload, $productId);

        // 4. Order ID (Fallback: transaction_id)
        $orderId = at_norm_string($payload['order_id'] ?? null) ?? $transactionId;

        // 5. Campaign ID (Fallback: campaign_no)
        $campaignId = at_norm_string($payload['campaign_id'] ?? null)
            ?? at_norm_string($payload['campaign_no'] ?? null);

        // 6. Quantity (Fallback: product_quantity -> quantity -> 1)
        $quantity = 1;
        if (isset($payload['product_quantity']) && is_numeric($payload['product_quantity'])) {
            $quantity = (int)$payload['product_quantity'];
        } elseif (isset($payload['quantity']) && is_numeric($payload['quantity'])) {
            $quantity = (int)$payload['quantity'];
        }

        // 7. Product Category
        $productCategory = at_norm_string($payload['product_category'] ?? null);

        // 8. Product Price / GMV (Fallback: product_price -> transaction_value -> 0.0)
        $productPriceRaw = $payload['product_price'] ?? $payload['transaction_value'] ?? 0;
        $productPrice = is_numeric($productPriceRaw) ? (float)$productPriceRaw : 0.0;

        // 9. Reward / Commission (Fallback: commission -> reward -> 0.0)
        $rewardRaw = $payload['commission'] ?? $payload['reward'] ?? 0;
        $reward = is_numeric($rewardRaw) ? (float)$rewardRaw : 0.0;

        // 10. Sales Time (Fallback: transaction_time -> sales_time)
        $salesTimeRaw = at_norm_string($payload['transaction_time'] ?? null)
            ?? at_norm_string($payload['sales_time'] ?? null);
        $salesTime = at_norm_datetime($salesTimeRaw);

        // 11. Browser (Fallback: _extra.browser -> browser)
        $browser = null;
        if (isset($payload['_extra']) && is_array($payload['_extra']) && isset($payload['_extra']['browser'])) {
            $browser = at_norm_string($payload['_extra']['browser']);
        }
        if ($browser === null) {
            $browser = at_norm_string($payload['browser'] ?? null);
        }

        // 12. Conversion Platform
        $conversionPlatform = at_norm_string($payload['conversion_platform'] ?? null);

        // 13. Status (0: Pending, 1: Approved, 2: Rejected)
        $status = isset($payload['status']) && is_numeric($payload['status'])
            ? (int)$payload['status']
            : 0;

        // 14. IP & Referrer (Ưu tiên context, fallback payload)
        $ip = at_norm_string($context['ip'] ?? $payload['ip'] ?? null);
        $referrer = at_norm_string($context['referrer'] ?? $payload['referrer'] ?? null);

        // 15. Click Time
        $clickTimeRaw = at_norm_string($payload['click_time'] ?? null);
        $clickTime = at_norm_datetime($clickTimeRaw);

        // 16. Is Confirmed
        $isConfirmed = isset($payload['is_confirmed']) && is_numeric($payload['is_confirmed'])
            ? (int)$payload['is_confirmed']
            : 0;

        // 17. UTMs
        $utmSource   = at_norm_utm($payload, 'utm_source');
        $utmMedium   = at_norm_utm($payload, 'utm_medium');
        $utmCampaign = at_norm_utm($payload, 'utm_campaign');
        $utmContent  = at_norm_utm($payload, 'utm_content');

        // 18. Sub parameters
        $sub1 = at_norm_sub_param($payload, 'sub1');
        $sub2 = at_norm_sub_param($payload, 'sub2');
        $sub3 = at_norm_sub_param($payload, 'sub3');
        $sub4 = at_norm_sub_param($payload, 'sub4');

        // 19. Customer Type
        $customerType = at_norm_string($payload['customer_type'] ?? null);

        // 20. Confirmed Date (Fallback: confirmed_time -> confirmed_date)
        $confirmedDateRaw = at_norm_string($payload['confirmed_time'] ?? null)
            ?? at_norm_string($payload['confirmed_date'] ?? null);
        $confirmedDate = at_norm_datetime($confirmedDateRaw) ?? $confirmedDateRaw;

        // 21. Raw Data (Nguyên bản payload JSON của AccessTrade)
        $rawData = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return [
            'transaction_id'      => $transactionId,
            'order_id'            => $orderId,
            'campaign_id'         => $campaignId,
            'merchant'            => $merchant,
            'product_id'          => $productId,
            'quantity'            => $quantity,
            'product_category'    => $productCategory,
            'product_price'       => $productPrice,
            'reward'              => $reward,
            'sales_time'          => $salesTime,
            'browser'             => $browser,
            'conversion_platform' => $conversionPlatform,
            'status'              => $status,
            'ip'                  => $ip,
            'referrer'            => $referrer,
            'click_time'          => $clickTime,
            'is_confirmed'        => $isConfirmed,
            'utm_source'          => $utmSource,
            'utm_medium'          => $utmMedium,
            'utm_campaign'        => $utmCampaign,
            'utm_content'         => $utmContent,
            'sub1'                => $sub1,
            'sub2'                => $sub2,
            'sub3'                => $sub3,
            'sub4'                => $sub4,
            'customer_type'       => $customerType,
            'confirmed_date'      => $confirmedDate,
            'raw_data'            => $rawData,
        ];
    }
}
