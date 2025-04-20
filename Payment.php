<?php

namespace App\Services\Gateway\rupantorpay;

use Facades\App\Services\BasicCurl;
use Facades\App\Services\BasicService;
use Exception;

class Payment
{
    public static function prepareData($order, $gateway)
    {
        $rupantorpayParams = $gateway->parameters;
        
        $requestData = [
            'fullname'      => optional($order->user)->username ?? "John Doe",
            'email'         => optional($order->user)->email ?? "john@example.com",
            'amount'        => round($order->final_amount, 2),
            'success_url'   => route('ipn', [$gateway->code, $order->transaction]),
            'cancel_url'    => route('failed'),
            'webhook_url'   => route('ipn', [$gateway->code, $order->transaction]),
            'metadata'      => [
                'trx_id'    => $order->transaction
            ]
        ];
        
        try {
            $redirect_url = self::initPayment($requestData, $rupantorpayParams);
            $send['redirect'] = true;
            $send['redirect_url'] = $redirect_url;
        } catch (\Exception $e) {
            $send['error'] = true;
            $send['message'] = $e->getMessage();
        }
        return json_encode($send);
    }
    
    public static function ipn($request, $gateway, $order = null, $trx = null, $type = null)
    {
        $rupantorpayParams = $gateway->parameters;
        
        if (!$request->transaction_id) {
            $data['status'] = 'error';
            $data['msg'] = 'Transaction ID not found';
            $data['redirect'] = route('failed');
            return $data;
        }
        
        $response = self::verifyPayment($request->transaction_id, $rupantorpayParams);
        
        if (isset($response['status']) && $response['status'] === 'COMPLETED') {
            BasicService::preparePaymentUpgradation($order);
            
            $data['status'] = 'success';
            $data['msg'] = 'Transaction was successful.';
            $data['redirect'] = route('success');
        } else {
            $data['status'] = 'error';
            $data['msg'] = isset($response['message']) ? $response['message'] : 'Unexpected error!';
            $data['redirect'] = route('failed');
        }
        return $data;
    }
    
    public static function initPayment($requestData, $rupantorpayParams)
    {
        $apiUrl = "https://payment.rupantorpay.com/api/payment/checkout";
        
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                "X-API-KEY: " . $rupantorpayParams->api_key,
                "Content-Type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error #:" . $err);
        } 
        
        $result = json_decode($response, true);
        
        if ($httpCode != 200 || !isset($result['status'])) {
            throw new Exception($result['message'] ?? "Payment initialization failed");
        }
        
        if ($result['status'] == 1 && isset($result['payment_url'])) {
            return $result['payment_url'];
        } else {
            throw new Exception($result['message'] ?? "Failed to get payment URL");
        }
    }
    
    public static function verifyPayment($transaction_id, $rupantorpayParams)
    {
        $verifyUrl = "https://payment.rupantorpay.com/api/payment/verify-payment";

        $requestData = [
            'transaction_id' => $transaction_id
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => [
                "X-API-KEY: " . $rupantorpayParams->api_key,
                "Content-Type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error #:" . $err);
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode != 200) {
            throw new Exception($result['message'] ?? "Payment verification failed");
        }
        
        return $result;
    }
}
