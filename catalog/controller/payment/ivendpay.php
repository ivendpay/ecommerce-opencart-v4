<?php

namespace Opencart\Catalog\Controller\Extension\Ivendpay\Payment;

class Ivendpay extends \Opencart\System\Engine\Controller
{
    protected $secretKey;

    public function index()
    {
        $this->load->language('extension/ivendpay/payment/ivendpay');
        $this->load->model('checkout/order');
        $this->ivendpayLibrary();

        $data = [];
        $data['white_label'] = false;

        if (!isset($data['button_confirm'])) {
            $data['button_confirm'] = $this->language->get('button_confirm');
        }
		$data['fail'] = $this->session->data['fail'] ?? false;
        $data['action'] = $this->url->link('extension/ivendpay/payment/ivendpay|checkout', '', true);

        return $this->load->view('extension/ivendpay/payment/ivendpay', $data);
    }

    public function checkout()
    {
        $this->ivendpayLibrary();
        $this->load->model('checkout/order');
        $this->load->model('extension/ivendpay/payment/ivendpay');

        $orderId = $this->session->data['order_id'];
        $order_info = $this->model_checkout_order->getOrder($orderId);

        $amount = $order_info['total'] * $this->currency->getvalue($order_info['currency_code']);

        $siteTitle = is_array($this->config->get('config_meta_title')) ? implode(',', $this->config->get('config_meta_title')) : $this->config->get('config_meta_title');

        $request = array(
            'amount_fiat'   => number_format($amount, 8, '.', ''),
            'currency_fiat' => mb_strtoupper($order_info['currency_code']),
            'title'         => $siteTitle.' Order #'.$order_info['order_id'],
            'order_id'      => $order_info['order_id'],
            'key'           => $this->secretKey,
        );

        $request['encode'] = base64_encode(sha1($this->secretKey.json_encode($request, JSON_UNESCAPED_UNICODE).$this->secretKey,1));

        // send request for create order and get url for select coin
        $response = $this->createOrderCurl($request);

        $encode = '';
        if (empty($response['error'])) {
            $requestCheck = [
                'order_id' => $order_info['order_id'],
                'url'      => @$response['url']
            ];

            $encode = base64_encode(sha1($this->secretKey.json_encode($requestCheck, JSON_UNESCAPED_UNICODE).$this->secretKey,1));
        }

        if (empty($response['error']) &&
            $encode === $response['encode'] &&
            $response['order_id'] === $order_info['order_id'])
        {
            $isCreate = $this->model_extension_ivendpay_payment_ivendpay->createOrder(
                $response['order_id'],
                $request['amount_fiat'],
                $request['currency_fiat'],
                $response['url']
            );

            if ($isCreate) {
                $this->model_checkout_order->addHistory(
                    $order_info['order_id'],
                    $this->config->get('payment_ivendpay_order_status_id'),
                    date('H:i'). ' Select coin: '.$response['url']
                );
                $this->cart->clear();
                $this->session->data['fail'] = false;
                $this->response->redirect($response['url']);
            }
        } else {
            $this->log->write("Order #" . $order_info['order_id'] . " is not valid. " . $response['error']);
			$this->session->data['fail'] = $response['error'];
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    public function invoice()
    {
        $this->load->model('checkout/order');
        $this->load->language('extension/ivendpay/payment/ivendpay');
        $this->load->model('extension/ivendpay/payment/ivendpay');
        $this->ivendpayLibrary();

        $orderId = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

        if (! $orderId){
            $this->response->redirect($this->url->link('common/home', '', true));
        }

        $order = $this->model_extension_ivendpay_payment_ivendpay->getOrder($orderId);
        if (! $order){
            $this->response->redirect($this->url->link('common/home', '', true));
        }

        $this->response->redirect($order['url']);
    }

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function success()
    {
        if (isset($this->session->data['order_id'])) {
            $this->load->model('checkout/order');
            $this->load->model('extension/ivendpay/payment/ivendpay');

            $order = $this->model_extension_ivendpay_payment_ivendpay->getOrder($this->session->data['order_id']);
        } else {
            $order = '';
        }

        if (empty($order)) {
            $this->response->redirect($this->url->link('common/home', '', true));
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        }
    }

    public function callback()
    {
        $this->ivendpayLibrary();

        if (! $this->checkHeaderApiKey()) {
            $this->setJsonMessage('callback error X-API-KEY');
        }

        $response = $this->getCallbackData();
        if (empty($response['data'])) {
            foreach ($_POST as $key => $value) {
                $key = str_replace([':_', ',_'], [':', ','], $key);
                $response = [];
                $response['data'] = json_decode(html_entity_decode(stripslashes($key)), true);

                if (! empty($response['data']['payment_status']) && ! empty($response['data']['invoice'])) {
                    break;
                }
            }

            if (empty($response['data'])) {
                $this->setJsonMessage('callback error. Empty post');
            }
        }

        $post_data = $response['data'];
        $findInvoice = false;

        if (! empty($post_data['payment_status']) && ! empty($post_data['invoice'])) {
            $findInvoice = true;
        }

        if (! $findInvoice) {
            $this->setJsonMessage('callback error. Invoice from request not found');
        }

        $this->load->model('checkout/order');
        $this->load->model('extension/ivendpay/payment/ivendpay');

        if (isset($response['type']) &&
            $response['type'] === 'invoice' &&
            $response['status'] === 'success')
        {

            if (empty($post_data['order_id'])) {
                $this->setJsonMessage('callback error. Empty field order id');
            }

            $orderId = $post_data['order_id'];
            $order_info = $this->model_checkout_order->getOrder($orderId);

            if ($order_info) {
                $is = $this->model_extension_ivendpay_payment_ivendpay->setOrderInvoice(
                    $post_data['order_id'],
                    $post_data['invoice'],
                    $post_data['amount'],
                    $post_data['currency'],
                    $post_data['payment_url'],
                    $post_data['status']
                );

                if ($is) {
                    $this->model_checkout_order->addHistory(
                        $orderId,
                        $this->config->get('payment_ivendpay_order_status_id'),
                        date('H:i'). ' Payment Invoice: '.$post_data['payment_url']
                    );

                    $this->setJsonMessage('setOrderInvoice. Order id: '. $orderId, 200);
                }
            } else {
                $this->setJsonMessage('callback error. setOrderInvoice. Order id not found');
            }

        } else {

            $invoice = $post_data['invoice'];
            $row = $this->model_extension_ivendpay_payment_ivendpay->getInvoice($invoice);

            if (! $row) {
                $this->setJsonMessage('callback error. Invoice not found');
            }

            $details = $this->get_remote_order_details($invoice);
            if (! $details) {
                $this->setJsonMessage('callback error. Remote invoice not found');
            }

            switch ($details['status']) {
                case 'PAID':
                    $orderStatus = 'payment_ivendpay_paid_status_id';
                    break;
                case 'CANCELED':
                case 'TIMEOUT':
                    $orderStatus = 'payment_ivendpay_canceled_status_id';
                    break;
                default:
                    $orderStatus = NULL;
            }

            if (! empty($orderStatus)) {
                $is = $this->model_extension_ivendpay_payment_ivendpay->changeOrderStatus(
                    $row['order_id'],
                    $details['status']
                );

                if ($is) {
                    $this->model_checkout_order->addHistory(
                        $row['order_id'],
                        $this->config->get($orderStatus),
                        date('H:i'). ' Change Order for Invoice: '.$invoice
                    );

                    $this->setJsonMessage('changeOrderStatus. Order id: '. $row['order_id'], 200);
                }
            }
        }
    }

    private function ivendpayLibrary()
    {
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('payment_ivendpay');
        $this->secretKey = $settings['payment_ivendpay_api_key'];
    }

    private function setJsonMessage($message, $code = 400)
    {
        if ($code === 200) {
            $this->response->addHeader('HTTP/1.1 200 OK');
        } else {
            $this->log->write($message);
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
        }

        exit(1);
    }

    private function isSetup()
    {
        return !empty($this->secretKey);
    }

    private function curlOptions($url, $dataValue = [], $method = 'POST')
    {
        return [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($dataValue),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "X-API-KEY: " . $this->secretKey
            ]
        ];
    }

    private function createOrderCurl($dataValue) {
        if (!$this->isSetup()) {
            return array('error' => 'Account details must be configured before using this module.');
        }

        $curl = curl_init();

        curl_setopt_array($curl, $this->curlOptions(
            'https://invoice.ivendpay.com/api/order',
            $dataValue
        ));

        $response = json_decode(curl_exec($curl), true);

        if ($response['status'] !== 'success' ||
            empty($response['data']['url']) ||
            empty($response['data']['order_id']))
        {
            return array('error' => $response['message']);
        }

        return $response['data'];
    }

    private function getCallbackData()
    {
        return json_decode(html_entity_decode(file_get_contents('php://input')), true);
    }

    private function checkHeaderApiKey()
    {
        $checkHeaderApiKey = false;
        $headers = apache_request_headers();

        foreach ($headers as $header => $value) {
            if (mb_strtoupper($header) === 'X-API-KEY') {
                if ($value === $this->secretKey) {
                    $checkHeaderApiKey = true;
                    break;
                }
            }
        }

        return $checkHeaderApiKey;
    }

    private function get_remote_order_details($invoice) {
        $apiKey = $this->secretKey;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://gate.ivendpay.com/api/v3/bill/" . $invoice,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "X-API-KEY: " . $apiKey
            ]
        ]);

        $response = json_decode(curl_exec($curl), true);

        if (empty($response['data'][0]['status'])) {
            return false;
        }

        return $response['data'][0];
    }
}
