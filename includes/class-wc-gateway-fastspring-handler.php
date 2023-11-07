<?php
if (!defined('ABSPATH'))
{
    exit;
}

// Polyfill for nginx
if (!function_exists('getallheaders'))
{
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value)
        {
            if (substr($name, 0, 5) == 'HTTP_')
            {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}


/**
 * Base class to handle ajax and webhook request from FastSpring.
 *
 * @since 1.0.0
 */
class WC_Gateway_FastSpring_Handler
{

    /**
     * Gateway options
     *
     * @var array FastSpring gateway options
     */
    protected static $settings;

    /**
     * Constructor
     */
    public function __construct()
    {
        self::set_settings();
        $this->init();
    }

    /**
     * Fetch plugin option
     *
     * @param $o Option key
     * @return mixed option value
     */
    public static function get_setting($o)
    {
        return isset(self::$settings[$o]) ? (self::$settings[$o] === 'yes' ? true : (self::$settings[$o] === 'no' ? false : self::$settings[$o])) : null;
    }

    /**
     * Set plugin option
     */
    public static function set_settings()
    {
        self::$settings  = get_option('woocommerce_fastspring_settings', array());
    }

    /**
     * If API credentials provided we can check for order completion on popup close
     */
    public function get_order_status($id)
    {
        if (empty(self::get_setting('api_username')) || empty(self::get_setting('api_password')))
        {
            $this->log('No API credentials - skipping API order confirm');
            return 'pending';
        }

        $url = 'https://api.fastspring.com/orders/' . $id;

        $context = stream_context_create(array(
            'http' => array(
                'user_agent' => 'Mozilla/5.0', // Not important what it is but must be set
                'header' => "Authorization: Basic " . base64_encode(
                    self::get_setting('api_username') . ':' . self::get_setting('api_password')
                ),
            )
        ));

        $data = @json_decode(file_get_contents($url, false, $context));

        if ($data && $data->completed === true)
        {
            $this->log(sprintf('API order %s completion checked', $id));
            return 'completed';
        }
        $this->log(sprintf('API order %s not found', $id));
        return 'pending';
    }

    /**
     * AjAX call to mark order as complete (but pending payment) and return payment page
     */
    public function ajax_get_receipt()
    {
        $payload = json_decode(file_get_contents('php://input'));

        $allowed = wp_verify_nonce($payload->security, 'wc-fastspring-receipt');

        if (!$allowed)
        {
            wp_send_json_error('Access denied');
        }

        $order_id = absint(WC()->session->get('current_order'));

        $this->log(sprintf('Generating receipt for order %s', $order_id));

        $order = wc_get_order($order_id);
        $data = ['order_id' => $order->get_id()];

        // Check for double calls
        $order_status = $order->get_status();

        // Popup closed with payment
        if ($order && $payload->reference)
        {

            // Get API order status if available
            $status = $this->get_order_status($payload->id);

            // Remove cart
            WC()->cart->empty_cart();

            $order->set_transaction_id($payload->reference);
            $order->update_meta_data('fs_order_id', $payload->id);

            if ($status === 'completed' && $order->payment_complete($payload->reference))
            {
                $this->log(sprintf('Marking order ID %s as completed', $order->get_id()));
                $order->add_order_note(sprintf(__('FastSpring payment approved (ID: %1$s)', 'woocommerce'), $order->get_id()));
            }
            // We could have a race condition where FS already called webhook so lets not assume its pending
            elseif ($order_status != 'completed')
            {
                $order->update_status('pending', __('Order pending payment approval.', 'woocommerce'));
            }

            $data = ["redirect_url" => WC_Gateway_FastSpring_Handler::get_return_url($order), 'order_id' => $order_id];

            wp_send_json($data);
        }
        else
        {
            wp_send_json_error('Order not found - Order ID was' . $order_id);
        }
    }

    /**
     * Get receipt URL
     *
     * @param object $order A Woo order
     * @return string Receipt URL
     */
    public static function get_return_url($order = null)
    {
        if ($order)
        {
            $return_url = $order->get_checkout_order_received_url();
            self::log(sprintf('Receipt URL for order set to %s', $return_url));
        }
        else
        {
            $return_url = wc_get_endpoint_url('order-received', '', wc_get_page_permalink('checkout'));
            self::log(sprintf('Receipt URL set to %s', $return_url));
        }

        if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes')
        {
            $return_url = str_replace('http:', 'https:', $return_url);
        }

        $filtered = apply_filters('woocommerce_get_return_url', $return_url, $order);

        self::log(sprintf('Final filtered receipt URL set to %s', $filtered));

        return $filtered;
    }

    /**
     * Handle the FastSpring webhook
     */
    public function init()
    {
        add_action('wc_ajax_wc_fastspring_get_receipt', array($this, 'ajax_get_receipt'));
        //add_action('wc_ajax_wc_fastspring_get_payload', array($this, 'ajax_get_payload'));

        add_action('woocommerce_api_wc_gateway_fastspring', array($this, 'listen_webhook_request'));
        add_action('woocommerce_fastspring_handle_webhook_request', array($this, 'handle_webhook_request'));
    }

    /**
     * Listens for webhook request
     */
    public function listen_webhook_request()
    {
        $events = json_decode(file_get_contents('php://input'));

        if (!$this->is_valid_webhook_request())
        {
            $this->log('Invalid webhook request - check secret');
            return wp_send_json_error();
        }

        foreach ($events as $event)
        {
            do_action('woocommerce_fastspring_handle_webhook_request', $event);
        }
    }

    /**
     * Finds one WC order by FastSpring custom tag
     *
     * @throws Exception
     *
     * @param string $id FastSpring transaction ID
     * @return WC_Order WooCommerce order
     */
    public function find_order_by_fastspring_tag($payload)
    {
        $id = @$payload->data->tags->store_order_id;
        $this->log(sprintf('Order tag found for %s', $id));

        if (!isset($id))
        {
            $this->log('No order ID found in webhook');
            throw new Exception('No order ID found in webhook');
        }

        $order = wc_get_order($id);

        if (!$order)
        {
            $this->log(sprintf('No order found with transaction ID %s', $id));
            throw new Exception(sprintf('Unable to locate order with FS transaction ID %s', $id));
        }
        return $order;
    }

    /**
     * Handles the validated FS webhook request
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return array JSON response
     */
    public function handle_webhook_request($payload)
    {
        try
        {
            switch ($payload->type)
            {

                case 'order.completed':
                    $this->handle_webhook_request_order_completed($payload);
                    break;

                case 'return.created':
                    $this->handle_webhook_request_order_refunded($payload);
                    break;

                case 'subscription.canceled':
                    $this->handle_webhook_request_subscription_canceled($payload);
                    break;

                case 'subscription.deactivated':
                    $this->handle_webhook_request_subscription_deactivate($payload);
                    break;

                case 'subscription.activated':
                    $this->handle_webhook_request_subscription_activate($payload);
                    break;

                case 'subscription.updated':
                    //$this->handle_webhook_request_subscription_canceled($payload);
                    //break;

                default:
                    $this->log(sprintf('No webhook handler found for %s', $payload->type));
                    break;
            }

            $this->log(json_encode($payload));
            return wp_send_json_success();
        }
        catch (Exception $e)
        {
            return wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Handles the order.completed webhook
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_completed($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);

        // Only mark complete if not already - webhook can hit multiple times
        if ($order->get_status() !== 'completed' && $order->payment_complete($payload->reference))
        {
            $this->log(sprintf('Marking order ID %s as complete', $order->get_id()));
            $order->add_order_note(sprintf(__('FastSpring payment approved (ID: %1$s)', 'woocommerce'), $order->get_id()));
        }
        else
        {
            $this->log(sprintf('Failed marking order ID %s as complete', $order->get_id()));
        }
    }

    /**
     * Handles the order.failed webhook
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_refunded($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking order ID %s as refunded', $order->get_id()));
        $order->update_status('refunded');
    }

    /**
     * Handles subscription cancellation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_canceled($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as canceled', $order->get_id()));
        $order->update_status('cancelled');
    }

    /**
     * Handles subscription (re)activation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_activate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as (re)activated', $order->get_id()));
        $order->update_status('active');
    }

    /**
     * Handles subscription deactivation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_deactivate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as deactivated', $order->get_id()));
        $order->update_status('on-hold');
    }

    /**
     * Check with FastSpring whether posted data is valid FastSpring webhook
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return bool True if payload is valid FastSpring webhook
     */
    public function is_valid_webhook_request()
    {
        $this->log(sprintf('%s: %s', __FUNCTION__, 'Checking FastSpring webhook validity'));

        $secret = self::get_setting('webhook_secret');

        $headers = getallheaders();
        $hash = base64_encode(hash_hmac('sha256', file_get_contents('php://input'), $secret, true));

        $sig = $_SERVER['HTTP_X_FS_SIGNATURE'];

        if (!$sig)
        {
            $this->log('No secret provided by FastSpring webhook');
            return true;
        }

        if (!$secret)
        {
            $this->log('Invalid webhook secret');
            return false;
        }

        return $sig === $hash;
    }

    /**
     * Logs
     *
     * @param string $message
     */
    public static function log($message)
    {
        WC_FastSpring::log($message);
    }
}

new WC_Gateway_FastSpring_Handler();
