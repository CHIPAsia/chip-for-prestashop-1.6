<?php
/**
 * CHIP for PrestaShop 1.6 - CHIP Collect API client.
 *
 * PHP 5.6 compatible. Singleton keyed by secret_key + brand_id (see CHIP-API-SPEC.md).
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChipApi
{
    const API_BASE = 'https://gate.chip-in.asia/api/v1';

    /** @var string */
    protected $secret_key;

    /** @var string */
    protected $brand_id;

    /** @var int */
    protected $timeout = 15;

    /** @var array of ChipApi instances keyed by md5(secret_key . brand_id) */
    protected static $instances = array();

    protected function __construct($secret_key, $brand_id)
    {
        $this->secret_key = $secret_key;
        $this->brand_id = $brand_id;
    }

    /**
     * Singleton: credentials berbeza => instance berbeza.
     *
     * @param string $secret_key
     * @param string $brand_id
     * @return ChipApi
     */
    public static function getInstance($secret_key, $brand_id)
    {
        $key = md5($secret_key . '|' . $brand_id);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($secret_key, $brand_id);
        }

        return self::$instances[$key];
    }

    /**
     * Build full API URL with optional query params.
     * Cache-bust `time` is always added (see CHIP-API-SPEC.md).
     * brand_id is sent in the JSON body (create purchase), not the query string
     * (matches the WooCommerce gateway behavior).
     *
     * @param string $path e.g. /purchases/
     * @param array $params
     * @return string
     */
    protected function buildUrl($path, $params = array())
    {
        $url = self::API_BASE . '/' . ltrim($path, '/');
        $params['time'] = time();
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Perform HTTP request (GET/POST) with JSON body.
     * Prefers cURL (proper JSON body), falls back to stream context.
     *
     * @param string $method
     * @param string $path
     * @param array $body
     * @param array $query
     * @return array|false decoded JSON response, or false on failure
     */
    protected function request($method, $path, $body = array(), $query = array())
    {
        $url = $this->buildUrl($path, $query);
        $json = Tools::jsonEncode($body);
        $headers = array(
            'Authorization: Bearer ' . $this->secret_key,
            'Content-Type: application/json',
            'Accept: application/json',
        );

        if (function_exists('curl_init')) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
            }
            $response = curl_exec($curl);
            curl_close($curl);
        } else {
            $context = stream_context_create(array(
                'http' => array(
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $json,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ),
            ));
            $response = @file_get_contents($url, false, $context);
        }

        if ($response === false || $response === '') {
            PrestaShopLogger::addLog('CHIP: API request failed for ' . $path, 3, null, 'ChipApi', null, true);

            return false;
        }

        $decoded = Tools::jsonDecode($response, true);
        if ($decoded === null) {
            PrestaShopLogger::addLog('CHIP: API returned invalid JSON for ' . $path, 3, null, 'ChipApi', null, true);

            return false;
        }

        // Some endpoints (e.g. /public_key/) return a plain string (PEM key).
        return $decoded;
    }

    /**
     * Create a purchase (payment).
     *
     * @param array $params payment params (see CHIP-API-SPEC.md / WooCommerce gateway)
     * @return array purchase data (false on error)
     */
    public function createPurchase($params)
    {
        return $this->request('POST', '/purchases/', $params);
    }

    /**
     * Fetch a purchase by ID.
     *
     * @param string $purchase_id
     * @return array purchase data (false on error)
     */
    public function getPurchase($purchase_id)
    {
        if (empty($purchase_id)) {
            return false;
        }

        return $this->request('GET', '/purchases/' . $purchase_id . '/', array());
    }

    /**
     * Fetch the public key used to verify webhook signatures.
     * Result is cached in Configuration (CHIP_PUBLIC_KEY) for 1 hour.
     *
     * @return string|false PEM public key
     */
    public function getPublicKey()
    {
        $cached = Configuration::get('CHIP_PUBLIC_KEY');
        if (!empty($cached)) {
            return $cached;
        }

        $response = $this->request('GET', '/public_key/');
        if ($response === false || $response === null) {
            PrestaShopLogger::addLog('CHIP: Unable to fetch public key', 3, null, 'ChipApi', null, true);

            return false;
        }

        // The endpoint returns the PEM key either as a raw JSON string
        // ("-----BEGIN PUBLIC KEY-----\n...") or as {"key": "..."}.
        $key = '';
        if (is_array($response)) {
            $key = isset($response['key']) ? (string) $response['key'] : '';
        } elseif (is_string($response)) {
            $key = $response;
        }
        if ($key === '') {
            PrestaShopLogger::addLog('CHIP: Unable to fetch public key', 3, null, 'ChipApi', null, true);

            return false;
        }

        // Normalize escaped newlines (mirrors WooCommerce get_public_key behavior)
        $key = str_replace(array('\r\n', '\n'), array("\r\n", "\n"), $key);
        Configuration::updateValue('CHIP_PUBLIC_KEY', $key, false, 0, 0);

        return $key;
    }

    /**
     * Verify the X-Signature header against the raw callback body.
     *
     * @param string $content raw request body
     * @param string $signature base64 encoded signature from X-Signature header
     * @return bool
     */
    public function verifySignature($content, $signature)
    {
        $public_key = $this->getPublicKey();
        if ($public_key === false) {
            return false;
        }

        $decoded = base64_decode($signature);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($content, $decoded, $public_key, 'sha256WithRSAEncryption') === 1;
    }
}
