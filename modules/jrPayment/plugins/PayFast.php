<?php
/**
 * Payfast Jamroom Payments Plugin
 * @author App Inlet (Pty) Ltd
 * @filesource
 * $Id: Payfast.php,v 1.1.0 2013/01/24 15:30:00 $
 */

// make sure we are not being called directly
defined('APP_DIR') || exit();

require APP_DIR . '/modules/jrPayment/plugins/vendor/autoload.php';

use Payfast\PayfastCommon\PayfastCommon;

/**
 * Plugin Meta
 *
 * @param $_post array $_post
 *
 * @return array
 */
function jrPayment_plugin_payfast_meta($_post)
{
    return array(
        'title'         => 'Payfast',
        'description'   => '',
        'url'           => 'https://payfast.io',
        'admin'         => 'https://www.payfast.io',
        'point-of-sale' => false
    );
}

/**
 * the jrPayment_Payfast_setup function is used for initializing
 * the required Payfast tables
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_Payfast_setup()
{
    global $jamroom_db;
    // Settings table
    $tbl = "CREATE TABLE {$jamroom_db['payments_payfast']} (
      payfast_mode VARCHAR(8) NOT NULL DEFAULT 'sandbox',
      payfast_merchant_id VARCHAR(100) NOT NULL DEFAULT '',
      payfast_merchant_key VARCHAR(100) NOT NULL DEFAULT '',
      payfast_vault_return VARCHAR(255) NOT NULL DEFAULT '',
      payfast_debug_itn BOOLEAN NOT NULL DEFAULT TRUE,
      payfast_use_curl CHAR(3) NOT NULL DEFAULT 'no',
      payfast_curl_path VARCHAR(100) NOT NULL DEFAULT '/usr/bin/curl'
    )";
    dbVerifyTable($jamroom_db['payments_payfast'], $tbl);

    // Now make sure it is initialized (if it isn't already)
    $req = "SELECT * FROM {$jamroom_db['payments_payfast']} LIMIT 1";
    $_rt = dbQuery($req, 'SINGLE');
    if (strlen($_rt['payfast_mode']) === 0) {
        $req = "INSERT INTO {$jamroom_db['payments_payfast']} (payfast_mode) VALUES ('sandbox')";
        $cnt = dbQuery($req, 'COUNT');
        if (!isset($cnt) || $cnt != 1) {
            jmLogger(
                0,
                'CRI',
                "jrPayment_Payfast_setup() Unable to initialize {$jamroom_db['payments_payfast']} table - verify Jamroom Integrity"
            );

            return false;
        }
    }

    return true;
}

/**
 * Get a currency symbol
 * @return string
 */
function jrPayment_plugin_Payfast_get_currency_code()
{
    $config = jrPayment_get_plugin_config('Payfast');

    return $config['store_currency'];
}

/**
 * Get formatted currency
 *
 * @param $amount
 *
 * @return number|string
 */
function jrPayment_plugin_Payfast_currency_format($amount)
{
    return jrCore_number_format($amount / 100, 2);
}

/**
 * Plugin Config
 *
 * @param $_post array $_post
 *
 * @return bool
 */
function jrPayment_plugin_Payfast_config($_post)
{
    global $_conf;
    jrCore_page_notice(
        'success',
        "Set the IPN Notification URL to: <b>{$_conf['jrCore_base_url']}/{$_post['module_url']}/webhook/payfast</b><br>in your Payfast -> Profile -> Profile and Settings -> My Selling Tools -> Instant Payment Notification section.",
        false
    );

    // payfast_merchant_id
    $_tmp = array(
        'name'     => 'payfast_merchant_id',
        'type'     => 'text',
        'default'  => '',
        'validate' => 'printable',
        'label'    => 'Payfast Mechant Id',
    );
    jrCore_form_field_create($_tmp);

    // payfast_merchant_key
    $_tmp = array(
        'name'     => 'payfast_merchant_key',
        'type'     => 'text',
        'default'  => '',
        'validate' => 'printable',
        'label'    => 'Payfast Merchant Key',
    );
    jrCore_form_field_create($_tmp);

    // payfast_passphrase
    $_tmp = array(
        'name'     => 'payfast_passphrase',
        'type'     => 'text',
        'default'  => '',
        'validate' => 'printable',
        'label'    => 'Payfast Passphrase',
    );
    jrCore_form_field_create($_tmp);

    // payfast_sandbox_mode
    $_tmp = array(
        'name'     => 'payfast_sandbox_mode',
        'type'     => 'checkbox',
        'default'  => 'on',
        'validate' => 'onoff',
        'label'    => 'Payfast Sandbox Mode'
    );
    jrCore_form_field_create($_tmp);

    // payfast_vault_return
    $_tmp = array(
        'name'     => 'payfast_vault_return',
        'type'     => 'text',
        'default'  => '',
        'validate' => 'printable',
        'label'    => 'Payfast vault return',
    );
    jrCore_form_field_create($_tmp);

    // payfast_debug_itn
    $_tmp = array(
        'name'     => 'payfast_debug_itn',
        'type'     => 'checkbox',
        'default'  => 'off',
        'validate' => 'onoff',
        'label'    => 'Payfast debug itn'
    );
    jrCore_form_field_create($_tmp);

    //payfast_use_curl
    $_tmp = array(
        'name'     => 'payfast_use_curl',
        'type'     => 'text',
        'default'  => 'no',
        'validate' => 'printable',
        'label'    => 'Payfast use curl',
    );
    jrCore_form_field_create($_tmp);

    ///usr/bin/curl
    $_tmp = array(
        'name'     => '/usr/bin/curl',
        'type'     => 'text',
        'default'  => '/usr/bin/curl',
        'validate' => 'printable',
        'label'    => 'Payfast curl path ',
    );
    jrCore_form_field_create($_tmp);

    // Store Currency
    $_cur = array(
        'ZAR' => 'ZAR - South African Rands'
    );
    asort($_cur);
    $_tmp = array(
        'name'     => 'store_currency',
        'type'     => 'select',
        'options'  => $_cur,
        'default'  => 'ZAR',
        'validate' => 'core_string',
        'label'    => 'Store currency',
        'help'     => 'Select the currency you want to use on the site'
    );
    jrCore_form_field_create($_tmp);

    return true;
}

/**
 * the jrPayment_Payfast_setup function is used for initializing
 * the required Payfast tables
 *
 * @return bool returns true/false on success/fail
 */
function jrPayments_Payfast_getconfig()
{
    global $config;
    $tbl = $config['db_prefix'] . 'payments_payfast';
    $req = "SELECT * FROM {$tbl} LIMIT 1";
    $_rt = dbQuery($req, 'SINGLE');
    if (isset($_rt) && is_array($_rt)) {
        return $_rt;
    }

    return false;
}

/**
 * The jrPayment_Payfast_check_txn_id function is used to check
 * if a Payfast payment transaction has already occured
 *
 * @param string Transaction ID to check
 *
 * @return bool Returns true if txn_id already exists, false if not
 */
function jrPayment_Payfast_check_txn_id($txn_id)
{
    global $jamroom_db;
    // We want to verify that we have not already processed this txn_id before
    // since this is our "payment" post. Note that on an ECHECK subscription payment,
    // we will get the SAME txn_id back in when the payment clears, so we must check
    // for that here
    $req = "SELECT *
              FROM {$jamroom_db['payment_txns']}
             WHERE txn_trans_id = '" . dbEscapeString($txn_id) . "'
               AND txn_type = 'payfast'
             LIMIT 1";
    $_rt = dbQuery($req, 'SINGLE');
    if (isset($_rt['txn_trans_id']) && strlen($_rt['txn_trans_id']) > 0) {
        // Looks like the transaction already exists - check that the previous transaction was NOT a subscr_signup/subscr_payment
        if (isset($_rt['txn_text']) && strlen($_rt['txn_text']) > 0) {
            $_tmp = unserialize($_rt['txn_text']);
            switch (trim($_tmp['txn_type'])) {
                // subscr_signup means the previous txn was simply a signup - no payment,
                // so this incoming transaction is our payment transaction.  Note that the
                // previous can also show as a payment - allow that
                case 'subscr_signup':
                case 'subscr_payment':
                default:
                    return false;
            }
        }

        return true;
    }

    return false;
}

/**
 * Get our checkout URL
 *
 * @param $amount int Total amount in cents
 * @param $_cart array cart items
 *
 * @return string
 */
function jrPayment_plugin_Payfast_checkout_url($amount, $_cart)
{
    global $_conf;
    $config = jrPayment_get_plugin_config('Payfast');
    $cur    = 'ZAR';
    if (isset($config['store_currency']{1})) {
        $cur = $config['store_currency'];
    }

    $merchant_id  = $config['payfast_merchant_id'];
    $merchant_key = $config['payfast_merchant_key'];
    $passPhrase   = $config['payfast_passphrase'];

    if ($config['payfast_sandbox_mode'] == 'off') {
        $burl = 'https://www.payfast.co.za';
    } else {
        $burl = 'https://sandbox.payfast.co.za';
    }

    $url    = jrCore_get_module_url('jrPayment');
    $amount = ($amount / 100);

    $payArray     = array(
        'merchant_id'  => $merchant_id,
        'merchant_key' => $merchant_key,
        'return_url'   => urlencode("{$_conf['jrCore_base_url']}/{$url}/success/cart"),
        'cancel_url'   => urlencode("{$_conf['jrCore_base_url']}/{$url}/cancel/cart"),
        'notify_url'   => urlencode("{$_conf['jrCore_base_url']}/{$url}/webhook/Payfast"),
        'm_payment_id' => $_cart['cart_id'],
        'amount'       => $amount,
        'item_name'    => $_cart['cart_id'],
    );
    $secureString = '';
    foreach ($payArray as $k => $v) {
        $secureString .= $k . '=' . $v . '&';
    }
    $secureString = substr($secureString, 0, -1);
    if ($passPhrase !== null) {
        $secureString .= '&passphrase=' . urlencode(trim($passPhrase));
    }
    $md5 = md5($secureString);
    $url = $burl . '/eng/process?' . $secureString;
    $url .= '&signature=' . $md5 . '&user_agent=Jamroom 6.5.x';
    // And forward our user over to checkout
    header('Location: ' . $url);

    return $url;
}

function jrPayment_plugin_Payfast_checkout_onclick($cart_hash, $total, $_cart)
{
    global $_post, $_conf;
    $amnt = html_entity_decode(jrPayment_get_currency_code()) . jrPayment_currency_format($total);

    return "jrCore_window_location('{$_conf['jrCore_base_url']}/{$_post['module_url']}/checkout/{$cart_hash}/Payfast')";
}

/**
 * The jrPayment_Payfast_getdata function is used for sending
 * out the Payfast Mass Pay file from the database
 *
 * @params array Incoming data to be used in payout
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_Payfast_getdata($_data)
{
    global $jamroom_db;
    // Let's get our data
    $req = "SELECT *
              FROM {$jamroom_db['temp']}
             WHERE temp_key = 'jrPayfastMasspayData'
               AND temp_varchar = '" . dbEscapeString($_data['filekey']) . "'
             LIMIT 1";
    $_rt = dbQuery($req, 'SINGLE');
    if (!isset($_rt) || strlen($_rt['temp_text']) === 0) {
        jrNoticePage('error', 'Unable to retrieve Payfast Mass Pay data from the database - verify connectivity!');
    }
    // Looks good - let's send out our MASS PAY file
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Cache-Control: private");
    header("Pragma: no-cache");
    header("Content-type: application/csv");
    header("Content-Disposition: inline; filename=\"quota_id_{$_rt['temp_int']}_payout_{$_data['filekey']}.csv\"");
    header("Content-length: " . strlen($_rt['temp_text']));
    ob_start();
    echo $_rt['temp_text'];
    ob_end_flush();
    exit;
}

/**
 * The jrPayment_Payfast_txn_detail function is used to display Transaction details
 *
 * @params array transaction
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_Payfast_txn_detail($_txn)
{
    $dat[1]['title'] = 'Parameter';
    $dat[2]['title'] = 'Value';
    htmlPageSelect('header', $dat, 'jmLog_header');
    $_txn['txn_text'] = unserialize($_txn['txn_text']);
    ksort($_txn['txn_text']);
    foreach ($_txn['txn_text'] as $param => $value) {
        $dat[1]['title'] = $param;
        $dat[2]['title'] = $value;
        htmlPageSelect('row', $dat, 'jmLog_line');
    }
    htmlPageSelect('footer');
}

/**
 * The jrPayment_Payfast_txn_address function is used for returning
 * a "formatted" shipping address based on a purchase
 *
 * @params array transaction
 *
 * @return string Returns formatted address
 */
function jrPayment_Payfast_txn_address($_txn)
{
    if (!isset($_txn) || !is_array($_txn)) {
        return false;
    }
    $out = "{$_txn['address_name']}\n{$_txn['address_street']}\n{$_txn['address_city']}, {$_txn['address_state']} {$_txn['address_zip']}\n{$_txn['address_country']}";

    return $out;
}

/**
 * Parse incoming transaction
 *
 * @param $_post array
 *
 * @return array
 */
function jrPayment_plugin_Payfast_webhook_parse($_post)
{
    $config        = jrPayment_get_plugin_config('Payfast');
    $debugMode     = $config['payfast_debug_itn'];
    $payfastCommon = new PayfastCommon($debugMode);
    $passPhrase    = $config['payfast_passphrase'];
    // Validate incoming transaction
    $pfError       = false;
    $pfErrMsg      = '';
    $pfDone        = false;
    $pfData        = array();
    $pfParamString = '';

    $payfastCommon->pflog('Payfast ITN call received');

    //// Notify Payfast that information has been received
    if (!$pfError && !$pfDone) {
        header('HTTP/1.0 200 OK');
        flush();
    }
    jrCore_logger(
        'MAJ',
        "Payments: Payfast  transaction received ID : {$_post['pf_payment_id']} Amount R: {$_post['amount_gross']}",
        $_post,
        1
    );


    //// Get data sent by Payfast
    if (!$pfError && !$pfDone) {
        $payfastCommon->pflog('Get posted data');

        // Posted variables from ITN
        $pfData = $payfastCommon->pfGetData();

        $payfastCommon->pflog('Payfast Data: ' . print_r($pfData, true));

        if ($pfData === false) {
            $pfError  = true;
            $pfErrMsg = $payfastCommon::PF_ERR_BAD_ACCESS;
        }
    }
    // Example usage

    //// Verify security signature
    if (!$pfError && !$pfDone) {
        $payfastCommon->pflog('Verify security signature');

        // If signature different, log for debugging
        if (!$payfastCommon->pfValidSignature($pfData, $pfParamString, $passPhrase)) {
            $pfError  = true;
            $pfErrMsg = $payfastCommon::PF_ERR_INVALID_SIGNATURE;
        }
    }
}
