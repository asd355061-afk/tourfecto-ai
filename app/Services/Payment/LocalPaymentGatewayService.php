<?php

namespace App\Services\Payment;

/**
 * Local Payment Gateway Service
 * 
 * Provides integration with local Middle Eastern payment gateways:
 * - Fawry (Egypt)
 * - Mada (Saudi Arabia)
 * - KNET (Kuwait)
 * - BenefitPay (Bahrain)
 * - STC Pay (Saudi Arabia)
 * 
 * @package App\Services\Payment
 */
class LocalPaymentGatewayService
{
    private array $supportedGateways = [
        'fawry' => [
            'name' => 'Fawry',
            'country' => 'Egypt',
            'currencies' => ['EGP'],
            'methods' => ['card', 'cash']
        ],
        'mada' => [
            'name' => 'Mada',
            'country' => 'Saudi Arabia',
            'currencies' => ['SAR'],
            'methods' => ['card']
        ],
        'knet' => [
            'name' => 'KNET',
            'country' => 'Kuwait',
            'currencies' => ['KWD'],
            'methods' => ['knet_card']
        ],
        'benefitpay' => [
            'name' => 'BenefitPay',
            'country' => 'Bahrain',
            'currencies' => ['BHD'],
            'methods' => ['mobile', 'card']
        ],
        'stcpay' => [
            'name' => 'STC Pay',
            'country' => 'Saudi Arabia',
            'currencies' => ['SAR', 'AED', 'EGP'],
            'methods' => ['wallet']
        ]
    ];
    
    /**
     * Get supported gateways list
     * 
     * @param string|null $country Filter by country
     * @return array Supported gateways
     */
    public function getSupportedGateways(?string $country = null): array
    {
        if ($country === null) {
            return $this->supportedGateways;
        }
        
        $country = strtolower($country);
        return array_filter($this->supportedGateways, function($gateway) use ($country) {
            return strtolower($gateway['country']) === $country;
        });
    }
    
    /**
     * Check if gateway is supported
     * 
     * @param string $gateway Gateway name
     * @return bool True if supported
     */
    public function isGatewaySupported(string $gateway): bool
    {
        return isset($this->supportedGateways[strtolower($gateway)]);
    }
    
    // ==================== FAWRY INTEGRATION ====================
    
    /**
     * Initialize Fawry payment
     * 
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializeFawryPayment(array $paymentData): array
    {
        $merchantCode = getenv('FAWRY_MERCHANT_CODE');
        $secretKey = getenv('FAWRY_SECRET_KEY');
        $environment = getenv('FAWRY_ENV') ?: 'test'; // test or production
        
        if (!$merchantCode || !$secretKey) {
            return [
                'success' => false,
                'error' => 'Fawry credentials not configured'
            ];
        }
        
        $baseUrl = $environment === 'production' 
            ? 'https://atfawry.fawry.com' 
            : 'https://atfawry.fawry.com';
        
        // Prepare payment request
        $request = [
            'merchantCode' => $merchantCode,
            'merchantRefNo' => $paymentData['order_id'] ?? uniqid('fawry_', true),
            'paymentAmount' => number_format($paymentData['amount'], 2, '.', ''),
            'currency' => $paymentData['currency'] ?? 'EGP',
            'customerMobile' => $paymentData['customer_phone'] ?? '',
            'customerEmail' => $paymentData['customer_email'] ?? '',
            'customerName' => $paymentData['customer_name'] ?? '',
            'productDescription' => $paymentData['description'] ?? 'Payment',
            'expiryDate' => date('Ymd', strtotime('+7 days')),
            'language' => $paymentData['language'] ?? 'ar'
        ];
        
        // Generate security hash
        $hashString = sprintf(
            '%s%s%s%s%s',
            $request['merchantCode'],
            $request['merchantRefNo'],
            $request['paymentAmount'],
            $request['currency'],
            $secretKey
        );
        $request['securityHash'] = hash('sha256', $hashString);
        
        // Send to Fawry API
        $response = $this->callFawryApi($baseUrl . '/echannelsweb/merchantV2/createPaymentRequest', $request);
        
        if ($response['success']) {
            return [
                'success' => true,
                'gateway' => 'fawry',
                'payment_url' => $response['result']['paymentUrl'] ?? null,
                'reference_number' => $response['result']['refNumber'] ?? null,
                'merchant_ref_no' => $request['merchantRefNo'],
                'amount' => $paymentData['amount'],
                'currency' => $request['currency'],
                'expires_at' => $request['expiryDate']
            ];
        }
        
        return $response;
    }
    
    /**
     * Verify Fawry payment status
     * 
     * @param string $merchantRefNo Merchant reference number
     * @return array Payment verification result
     */
    public function verifyFawryPayment(string $merchantRefNo): array
    {
        $merchantCode = getenv('FAWRY_MERCHANT_CODE');
        $secretKey = getenv('FAWRY_SECRET_KEY');
        $environment = getenv('FAWRY_ENV') ?: 'test';
        
        $baseUrl = $environment === 'production' 
            ? 'https://atfawry.fawry.com' 
            : 'https://atfawry.fawry.com';
        
        $request = [
            'merchantCode' => $merchantCode,
            'merchantRefNo' => $merchantRefNo
        ];
        
        $hashString = sprintf('%s%s%s', $merchantCode, $merchantRefNo, $secretKey);
        $request['securityHash'] = hash('sha256', $hashString);
        
        $response = $this->callFawryApi($baseUrl . '/echannelsweb/merchantV2/getPaymentStatus', $request);
        
        if ($response['success']) {
            $status = $response['result']['paymentStatus'] ?? '';
            
            return [
                'success' => true,
                'gateway' => 'fawry',
                'merchant_ref_no' => $merchantRefNo,
                'status' => $status,
                'is_paid' => in_array($status, ['CAPTURED', 'AUTHORIZED']),
                'payment_amount' => $response['result']['paymentAmount'] ?? null,
                'payment_date' => $response['result']['paymentDate'] ?? null,
                'raw_response' => $response['result']
            ];
        }
        
        return $response;
    }
    
    private function callFawryApi(string $url, array $params): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        if (($result['statusCode'] ?? '') !== '200') {
            return [
                'success' => false,
                'error' => $result['message'] ?? 'Unknown error',
                'status_code' => $result['statusCode'] ?? null
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'http_code' => $httpCode
        ];
    }
    
    // ==================== MADA INTEGRATION ====================
    
    /**
     * Initialize Mada payment
     * 
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializeMadaPayment(array $paymentData): array
    {
        $apiKey = getenv('MADA_API_KEY');
        $apiSecret = getenv('MADA_API_SECRET');
        $environment = getenv('MADA_ENV') ?: 'test';
        
        if (!$apiKey || !$apiSecret) {
            return [
                'success' => false,
                'error' => 'Mada credentials not configured'
            ];
        }
        
        $baseUrl = $environment === 'production'
            ? 'https://apigw.mada-pay.com/v1'
            : 'https://apigw-sandbox.mada-pay.com/v1';
        
        $request = [
            'amount' => number_format($paymentData['amount'], 2, '.', ''),
            'currency' => 'SAR',
            'order_id' => $paymentData['order_id'] ?? uniqid('mada_', true),
            'customer' => [
                'email' => $paymentData['customer_email'] ?? '',
                'phone' => $paymentData['customer_phone'] ?? '',
                'name' => $paymentData['customer_name'] ?? ''
            ],
            'callback_url' => $paymentData['callback_url'] ?? '',
            'metadata' => $paymentData['metadata'] ?? []
        ];
        
        $authToken = base64_encode("{$apiKey}:{$apiSecret}");
        
        $response = $this->callMadaApi($baseUrl . '/payments', $request, $authToken);
        
        if ($response['success']) {
            return [
                'success' => true,
                'gateway' => 'mada',
                'payment_url' => $response['result']['redirect_url'] ?? null,
                'payment_id' => $response['result']['id'] ?? null,
                'order_id' => $request['order_id'],
                'amount' => $paymentData['amount'],
                'currency' => 'SAR'
            ];
        }
        
        return $response;
    }
    
    /**
     * Verify Mada payment status
     * 
     * @param string $paymentId Mada payment ID
     * @return array Payment verification result
     */
    public function verifyMadaPayment(string $paymentId): array
    {
        $apiKey = getenv('MADA_API_KEY');
        $apiSecret = getenv('MADA_API_SECRET');
        $environment = getenv('MADA_ENV') ?: 'test';
        
        $baseUrl = $environment === 'production'
            ? 'https://apigw.mada-pay.com/v1'
            : 'https://apigw-sandbox.mada-pay.com/v1';
        
        $authToken = base64_encode("{$apiKey}:{$apiSecret}");
        
        $response = $this->callMadaApi($baseUrl . "/payments/{$paymentId}", [], $authToken, 'GET');
        
        if ($response['success']) {
            $status = $response['result']['status'] ?? '';
            
            return [
                'success' => true,
                'gateway' => 'mada',
                'payment_id' => $paymentId,
                'status' => $status,
                'is_paid' => $status === 'captured',
                'amount' => $response['result']['amount'] ?? null,
                'currency' => $response['result']['currency'] ?? 'SAR',
                'raw_response' => $response['result']
            ];
        }
        
        return $response;
    }
    
    private function callMadaApi(string $url, array $params, string $authToken, string $method = 'POST'): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $authToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        if ($httpCode >= 400) {
            return [
                'success' => false,
                'error' => $result['message'] ?? 'API error',
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'http_code' => $httpCode
        ];
    }
    
    // ==================== KNET INTEGRATION ====================
    
    /**
     * Initialize KNET payment
     * 
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializeKnetPayment(array $paymentData): array
    {
        $terminalId = getenv('KNET_TERMINAL_ID');
        $resourceId = getenv('KNET_RESOURCE_ID');
        $secret = getenv('KNET_SECRET');
        $environment = getenv('KNET_ENV') ?: 'test';
        
        if (!$terminalId || !$resourceId || !$secret) {
            return [
                'success' => false,
                'error' => 'KNET credentials not configured'
            ];
        }
        
        $baseUrl = $environment === 'production'
            ? 'https://knet.knet.com.kw'
            : 'https://knetbo.knet.com.kw';
        
        $tranDate = date('YmdHis');
        $amount = number_format($paymentData['amount'], 3, '.', '');
        
        $request = [
            'terminalId' => $terminalId,
            'resourceId' => $resourceId,
            'tranDate' => $tranDate,
            'amount' => $amount,
            'currency' => 'KWD',
            'orderId' => $paymentData['order_id'] ?? uniqid('knet_', true),
            'callbackUrl' => $paymentData['callback_url'] ?? ''
        ];
        
        // Generate hash
        $hashString = sprintf(
            '%s%s%s%s%s%s',
            $terminalId,
            $resourceId,
            $tranDate,
            $amount,
            'KWD',
            $secret
        );
        $request['tranKey'] = base64_encode(hash('sha256', $hashString, true));
        
        $response = $this->callKnetApi($baseUrl . '/knetbo/transaction/initiate', $request);
        
        if ($response['success']) {
            return [
                'success' => true,
                'gateway' => 'knet',
                'payment_url' => $response['result']['redirectUrl'] ?? null,
                'transaction_id' => $response['result']['transactionId'] ?? null,
                'order_id' => $request['orderId'],
                'amount' => $paymentData['amount'],
                'currency' => 'KWD'
            ];
        }
        
        return $response;
    }
    
    /**
     * Verify KNET payment status
     * 
     * @param string $transactionId KNET transaction ID
     * @return array Payment verification result
     */
    public function verifyKnetPayment(string $transactionId): array
    {
        $terminalId = getenv('KNET_TERMINAL_ID');
        $resourceId = getenv('KNET_RESOURCE_ID');
        $secret = getenv('KNET_SECRET');
        
        $tranDate = date('YmdHis');
        
        $request = [
            'terminalId' => $terminalId,
            'resourceId' => $resourceId,
            'tranDate' => $tranDate,
            'transactionId' => $transactionId
        ];
        
        $hashString = sprintf(
            '%s%s%s%s',
            $terminalId,
            $resourceId,
            $tranDate,
            $secret
        );
        $request['tranKey'] = base64_encode(hash('sha256', $hashString, true));
        
        $response = $this->callKnetApi('https://knet.knet.com.kw/knetbo/transaction/status', $request);
        
        if ($response['success']) {
            $status = $response['result']['status'] ?? '';
            
            return [
                'success' => true,
                'gateway' => 'knet',
                'transaction_id' => $transactionId,
                'status' => $status,
                'is_paid' => $status === 'CAPTURED',
                'amount' => $response['result']['amount'] ?? null,
                'currency' => 'KWD',
                'raw_response' => $response['result']
            ];
        }
        
        return $response;
    }
    
    private function callKnetApi(string $url, array $params): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        if (($result['responseCode'] ?? '') !== '000') {
            return [
                'success' => false,
                'error' => $result['responseText'] ?? 'Unknown error',
                'response_code' => $result['responseCode'] ?? null
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'http_code' => $httpCode
        ];
    }
    
    // ==================== BENEFITPAY INTEGRATION ====================
    
    /**
     * Initialize BenefitPay payment
     * 
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializeBenefitPayPayment(array $paymentData): array
    {
        $apiKey = getenv('BENEFITPAY_API_KEY');
        $apiSecret = getenv('BENEFITPAY_API_SECRET');
        $merchantId = getenv('BENEFITPAY_MERCHANT_ID');
        $environment = getenv('BENEFITPAY_ENV') ?: 'test';
        
        if (!$apiKey || !$apiSecret || !$merchantId) {
            return [
                'success' => false,
                'error' => 'BenefitPay credentials not configured'
            ];
        }
        
        $baseUrl = $environment === 'production'
            ? 'https://apis.benefit.com.bh'
            : 'https://apis-sandbox.benefit.com.bh';
        
        $request = [
            'merchantId' => $merchantId,
            'orderId' => $paymentData['order_id'] ?? uniqid('benefit_', true),
            'amount' => number_format($paymentData['amount'], 3, '.', ''),
            'currency' => 'BHD',
            'customerEmail' => $paymentData['customer_email'] ?? '',
            'customerMobile' => $paymentData['customer_phone'] ?? '',
            'customerName' => $paymentData['customer_name'] ?? '',
            'description' => $paymentData['description'] ?? 'Payment',
            'callbackUrl' => $paymentData['callback_url'] ?? ''
        ];
        
        $timestamp = time();
        $signature = hash_hmac('sha256', json_encode($request), $apiSecret);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature
        ];
        
        $response = $this->callBenefitPayApi($baseUrl . '/v1/payments', $request, $headers);
        
        if ($response['success']) {
            return [
                'success' => true,
                'gateway' => 'benefitpay',
                'payment_url' => $response['result']['checkoutUrl'] ?? null,
                'payment_id' => $response['result']['paymentId'] ?? null,
                'order_id' => $request['orderId'],
                'amount' => $paymentData['amount'],
                'currency' => 'BHD'
            ];
        }
        
        return $response;
    }
    
    /**
     * Verify BenefitPay payment status
     * 
     * @param string $paymentId BenefitPay payment ID
     * @return array Payment verification result
     */
    public function verifyBenefitPayPayment(string $paymentId): array
    {
        $apiKey = getenv('BENEFITPAY_API_KEY');
        $apiSecret = getenv('BENEFITPAY_API_SECRET');
        
        $timestamp = time();
        $signature = hash_hmac('sha256', $paymentId, $apiSecret);
        
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature
        ];
        
        $response = $this->callBenefitPayApi(
            'https://apis.benefit.com.bh/v1/payments/' . $paymentId,
            [],
            $headers,
            'GET'
        );
        
        if ($response['success']) {
            $status = $response['result']['status'] ?? '';
            
            return [
                'success' => true,
                'gateway' => 'benefitpay',
                'payment_id' => $paymentId,
                'status' => $status,
                'is_paid' => $status === 'SUCCESS',
                'amount' => $response['result']['amount'] ?? null,
                'currency' => 'BHD',
                'raw_response' => $response['result']
            ];
        }
        
        return $response;
    }
    
    private function callBenefitPayApi(string $url, array $params, array $headers, string $method = 'POST'): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        if ($httpCode >= 400) {
            return [
                'success' => false,
                'error' => $result['message'] ?? 'API error',
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'http_code' => $httpCode
        ];
    }
    
    // ==================== STC PAY INTEGRATION ====================
    
    /**
     * Initialize STC Pay payment
     * 
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializeStcPayPayment(array $paymentData): array
    {
        $apiKey = getenv('STCPAY_API_KEY');
        $apiSecret = getenv('STCPAY_API_SECRET');
        $merchantId = getenv('STCPAY_MERCHANT_ID');
        $environment = getenv('STCPAY_ENV') ?: 'test';
        
        if (!$apiKey || !$apiSecret || !$merchantId) {
            return [
                'success' => false,
                'error' => 'STC Pay credentials not configured'
            ];
        }
        
        $baseUrl = $environment === 'production'
            ? 'https://open.stcpay.com/v1'
            : 'https://sandbox-open.stcpay.com/v1';
        
        $request = [
            'merchant_id' => $merchantId,
            'order_id' => $paymentData['order_id'] ?? uniqid('stc_', true),
            'amount' => number_format($paymentData['amount'], 2, '.', ''),
            'currency' => $paymentData['currency'] ?? 'SAR',
            'customer' => [
                'email' => $paymentData['customer_email'] ?? '',
                'phone' => $paymentData['customer_phone'] ?? '',
                'name' => $paymentData['customer_name'] ?? ''
            ],
            'callback_url' => $paymentData['callback_url'] ?? '',
            'description' => $paymentData['description'] ?? 'Payment'
        ];
        
        $authToken = base64_encode("{$apiKey}:{$apiSecret}");
        
        $response = $this->callStcPayApi($baseUrl . '/payments', $request, $authToken);
        
        if ($response['success']) {
            return [
                'success' => true,
                'gateway' => 'stcpay',
                'payment_url' => $response['result']['checkout_url'] ?? null,
                'payment_id' => $response['result']['id'] ?? null,
                'order_id' => $request['order_id'],
                'amount' => $paymentData['amount'],
                'currency' => $request['currency']
            ];
        }
        
        return $response;
    }
    
    /**
     * Verify STC Pay payment status
     * 
     * @param string $paymentId STC Pay payment ID
     * @return array Payment verification result
     */
    public function verifyStcPayPayment(string $paymentId): array
    {
        $apiKey = getenv('STCPAY_API_KEY');
        $apiSecret = getenv('STCPAY_API_SECRET');
        
        $authToken = base64_encode("{$apiKey}:{$apiSecret}");
        
        $response = $this->callStcPayApi(
            'https://open.stcpay.com/v1/payments/' . $paymentId,
            [],
            $authToken,
            'GET'
        );
        
        if ($response['success']) {
            $status = $response['result']['status'] ?? '';
            
            return [
                'success' => true,
                'gateway' => 'stcpay',
                'payment_id' => $paymentId,
                'status' => $status,
                'is_paid' => in_array($status, ['paid', 'captured']),
                'amount' => $response['result']['amount'] ?? null,
                'currency' => $response['result']['currency'] ?? 'SAR',
                'raw_response' => $response['result']
            ];
        }
        
        return $response;
    }
    
    private function callStcPayApi(string $url, array $params, string $authToken, string $method = 'POST'): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $authToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response'];
        }
        
        if ($httpCode >= 400) {
            return [
                'success' => false,
                'error' => $result['message'] ?? 'API error',
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Unified payment initialization method
     * Automatically routes to the correct gateway
     * 
     * @param string $gateway Gateway name
     * @param array $paymentData Payment data
     * @return array Payment initialization result
     */
    public function initializePayment(string $gateway, array $paymentData): array
    {
        $gateway = strtolower($gateway);
        
        switch ($gateway) {
            case 'fawry':
                return $this->initializeFawryPayment($paymentData);
            case 'mada':
                return $this->initializeMadaPayment($paymentData);
            case 'knet':
                return $this->initializeKnetPayment($paymentData);
            case 'benefitpay':
                return $this->initializeBenefitPayPayment($paymentData);
            case 'stcpay':
                return $this->initializeStcPayPayment($paymentData);
            default:
                return [
                    'success' => false,
                    'error' => "Unsupported gateway: {$gateway}"
                ];
        }
    }
    
    /**
     * Unified payment verification method
     * Automatically routes to the correct gateway
     * 
     * @param string $gateway Gateway name
     * @param string $paymentId Payment/Transaction ID
     * @return array Payment verification result
     */
    public function verifyPayment(string $gateway, string $paymentId): array
    {
        $gateway = strtolower($gateway);
        
        switch ($gateway) {
            case 'fawry':
                return $this->verifyFawryPayment($paymentId);
            case 'mada':
                return $this->verifyMadaPayment($paymentId);
            case 'knet':
                return $this->verifyKnetPayment($paymentId);
            case 'benefitpay':
                return $this->verifyBenefitPayPayment($paymentId);
            case 'stcpay':
                return $this->verifyStcPayPayment($paymentId);
            default:
                return [
                    'success' => false,
                    'error' => "Unsupported gateway: {$gateway}"
                ];
        }
    }
}
