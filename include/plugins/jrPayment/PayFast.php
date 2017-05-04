<?php

/**
 * PayFast Jamroom Payments Plug in
 * @package Talldude_Library
 * @subpackage Payment_Plugins
 * Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 * @author Ron Darby -- ron.darby@payfast.co.za
 * @filesource
 * $Id: PayFast.php,v 1.0.0 2013/01/24 15:30:00 $
 */
// make sure we are not being called directly
defined('IN_JAMROOM') or exit();


include('payfast_common.inc');

/**
 * the jrPayment_PayFast_options function tells jamroom the "options"
 * implemented in this plugin
 *
 * @return array Returns array of info on success
 */
  
function jrPayment_PayFast_options()
{
    $_out = array(
      'vault'         => 1
    );
    return $_out;
}
/**
 * the jrPayment_PayFast_currency function tells jamroom the supported
 * currencies for vault and subscription payments
 *
 * @param string Type of currency to check - "vault" or "subscription"
 *
 * @return array Returns array of info on success
 */
function jrPayment_PayFast_currency($type = 'vault')
{

    $_cur = array(
      'ZAR' => 'South African Rands (ZAR)'
    );
    return $_cur;
}
/**
 * the jrPayment_PayFast_setup function is used for initializing
 * the required PayFast tables
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_setup()
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
    dbVerifyTable($jamroom_db['payments_payfast'],$tbl);
    
    // Now make sure it is initialized (if it isn't already)
    $req = "SELECT * FROM {$jamroom_db['payments_payfast']} LIMIT 1";
    $_rt = dbQuery($req,'SINGLE');
    if (strlen($_rt['payfast_mode']) === 0) {
        $req = "INSERT INTO {$jamroom_db['payments_payfast']} (payfast_mode) VALUES ('sandbox')";
        $cnt = dbQuery($req,'COUNT');
        if (!isset($cnt) || $cnt != 1) {
            jmLogger(0,'CRI',"jrPayment_PayFast_setup() Unable to initialize {$jamroom_db['payments_payfast']} table - verify Jamroom Integrity");
            return false;
        }
    }
    return true;
}
/**
 * the jrPayment_PayFast_setup function is used for initializing
 * the required PayFast tables
 *
 * @return bool returns true/false on success/fail
 */
function jrPayments_PayFast_getconfig()
{
    global $config;
    $tbl = $config['db_prefix'] . 'payments_payfast';
    $req = "SELECT * FROM {$tbl} LIMIT 1";
    $_rt = dbQuery($req,'SINGLE');
    if (isset($_rt) && is_array($_rt)) {
        return $_rt;
    }
    return false;
}
/**
 * The jrPayment_PayFast_check_txn_id function is used to check
 * if a PayFast payment transaction has already occured
 *
 * @param string Transaction ID to check
 *
 * @return bool Returns true if txn_id already exists, false if not
 */
function jrPayment_PayFast_check_txn_id($txn_id)
{
    global $jamroom_db;
    // We want to verify that we have not already processed this txn_id before
    // since this is our "payment" post. Note that on an ECHECK subscription payment,
    // we will get the SAME txn_id back in when the payment clears, so we must check
    // for that here
    $req = "SELECT *
              FROM {$jamroom_db['payment_txns']}
             WHERE txn_trans_id = '". dbEscapeString($txn_id) ."'
               AND txn_type = 'payfast'
             LIMIT 1";
    $_rt = dbQuery($req,'SINGLE');
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
                    return false;
                    break;
            }
        }
        return true;
    }
    return false;
}
/**
 * the jrPayment_PayFast_config function is used for configuring
 * the required PayFast tables
 *
 * @param Array Input data as retrieved from getForm (if available)
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_config($_rt = false)
{
    global $jamroom_db, $jamroom, $config;
    // get our config
    if ((!isset($_rt) || !is_array($_rt)) || $_rt === false) {
        $_rt = jrPayments_PayFast_getconfig();
    }
    $_mode = array(
      'sandbox' => 'PayFast Test Mode (use Sandbox)',
      'live'    => 'PayFast Live Mode (real transactions)',
    );
    $_debug_itn = array(
      '1' =>'Debug PayFast ITN On',
      '0'=>'Debug PayFast ITN Off'
    );
    // see what our IPN Url is
    jmSelect('PayFast Mode','payfast_mode',$_mode,$_rt['payfast_mode'],'Is the PayFast module running in &quot;sandbox&quot; or &quot;live&quot; mode?');
    jmInput('PayFast Primary Merchant ID','payfast_merchant_id','description',$_rt['payfast_primary_email'],'Enter your PayFast Merchant ID.');
    jmInput('PayFast Primary Merchant Key','payfast_merchant_key','description',$_rt['payfast_primary_email'],'Enter your PayFast Merchant Key.');
    jmSelect('PayFast Debug','payfast_debug_itn',$_debug_itn,$_rt['payfast_debug_itn'],'Is the PayFast module set to &quot;debug&quot; the itn?');
    
   
   // See fi we have transitioned yet
    if ((!isset($_rt['payfast_vault_return']) || strlen($_rt['payfast_vault_return']) === 0) && (isset($config['vault_return']) && strlen($config['vault_return']) > 0)) {
        $_rt['payfast_vault_return'] = $config['vault_return'];
    }
    jmInput('Vault Return Template','payfast_vault_return','description',$_rt['payfast_vault_return'],"You can specify a custom &quot;return&quot; template here, that after a successful vault purchase, will be displayed to the user. The template must be a valid template name, and be located in the Active Skin Directory (skins/{$config['index_template']}). You may also enter a fully qualified URL, up to 255 characters. Leave blank to use the default return message.");
    jmDivider();
    jmYesNo('Use SSL','payfast_use_curl',"If this is set to &quot;yes&quot;, then all ITN communication between Jamroom and PayFast will be encrypted via SSL. Note that &quot;cURL&quot; is required for this functionality, so be sure and enter the correct path to the cURL binary on your system in the cURL Command Path Setting.  Note that this is <b>not required</b> for IPN to function correctly, as no Credit Card or detailed information is ever sent via IPN.",$_rt['payfast_use_curl']);
    jmInput('cURL Command Path','payfast_curl_path','description',$_rt['payfast_curl_path'],'Enter the full path to the cURL binary on your system (usually /usr/bin/curl or /usr/local/bin/curl)');
    return true;
}
/**
 * the jrPayment_PayFast_update function is used for updating
 * the required PayFast tables
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_update(&$_args)
{
    if (!is_array($_args)) {
        return false;
    }
    global $jamroom, $jamroom_db;
    // Validate our post
    $url = 'payment.php?mode=config&section=PayFast';
    // See if we are running in Jamroom Demo mode - if so, we are going
    // to automatically set some values
    if (jrIsDemoMode()) {
        $_args['payfast_mode']          = 'sandbox';
        $_args['payfast_use_curl']      = 'no'; 
        $_args['payfast_curl_path']     = '/usr/bin/curl';        
    }
    // PayFast Mode
    if (!isset($_args['payfast_mode']) || ($_args['payfast_mode'] != 'sandbox' && $_args['payfast_mode'] != 'live')) {
        addToForm('e_text','You have selected an invalid PayFast Mode - please select a valid PayFast Mode','payment');
        setFormHighlight('payfast_mode');
        jrLocation($url);
    }
   
 
    // Check on cURL settings
    if ((isset($_args['payfast_use_curl']) && $_args['payfast_use_curl'] == 'yes') && (!is_file($_args['payfast_curl_path']) && !function_exists('curl_init'))) {
        addToForm('e_text','You have entered an invalid cURL Command Path - cURL binary does not exist!','payment');
        setFormHighlight('payfast_use_curl');
        jrLocation($url);
    }
    // Check to see if we are asking to use SSL - if we are, we need to make sure a web server is running on port 443 (SSL port)
    if (isset($_args['payfast_use_curl']) && $_args['payfast_use_curl'] == 'yes') {
        $urt = str_replace('http:','https:',$jamroom['jm_htm']);
        $out = jrCallUrl($urt);
        if (!stristr($out,'verify usage')) {
            addToForm('e_text','You have set Use SSL to yes, but it does not appear that SSL is working on your website. Contact your hosting provider if you want to setup SSL on your website.','payment');
            setFormHighlight('payfast_use_curl');
            jrLocation($url);
        }
    }
    // Passed validation - update
    $cnt = jrPaymentUpdateConfig('payfast',$_args);
    return $cnt;
}

/**
 * The jrPayment_PayFast_checkout function is used for creating a
 * URL that will redirect a user to PayFast for checkout.
 *
 * @params array Incoming data to be used in checkout
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_checkout($_data)
{
    // Make sure we get good data
    if (!isset($_data) || !is_array($_data)) {
        jmLogger(0,'CRI','jrPayment_PayFast_checkout invalid _data structure received');
        return false;
    }
    global $jamroom, $config;
    // get our config
    $cfg = jrPayments_PayFast_getconfig();
    
    
    
    if (isset($cfg['payfast_mode']) && $cfg['payfast_mode'] == 'live') {
    $merchant_id = $cfg['payfast_merchant_id'];
    $merchant_key = $cfg['payfast_merchant_key'];
    $burl = 'https://www.payfast.co.za';
    }else{
    $merchant_id  = '10000100';
    $merchant_key   = '46f0cd694581a';
    $burl = 'https://sandbox.payfast.co.za';    
    }
    //$burl = 'https://www.payfast.local'; //Testing
    
    
    
    // Make sure we have a good currency
    if (!isset($config['vault_currency']) || strlen($config['vault_currency']) === 0) {
        $config['vault_currency'] = 'ZAR';
    }
   
    // The "name" that gets shown in PayFast
    
    // Our return URL
    $return_url = urlencode($jamroom['jm_htm'] .'/'. $config['jamroom_index'] .'?mode=cp&page=myfiles');
    if (isset($cfg['payfast_vault_return']) && strlen($cfg['payfast_vault_return']) > 2) {
        $return_url = urlencode($cfg['payfast_vault_return']);
    }
    $cancel_url = urlencode($jamroom['jm_htm'].'/payment.php');
    $notify_url = urlencode($jamroom['jm_htm'] .'/payment.php?p=PayFast');
    $m_payment_id = urlencode($_data['vault_number']);
    $amount = urlencode($_data['price']);
    $item_name = urlencode($config['system_name'] .' Cart Checkout - '. $_data['vault_count'] .' items');
    
    // See if we are showing cart contents
    $payArray = array(
            'merchant_id'=>$merchant_id,
            'merchant_key'=>$merchant_key,
            'return_url'=>$return_url,
            'cancel_url'=>$cancel_url,
            'notify_url'=>$notify_url,
            'm_payment_id'=>$m_payment_id,
            'amount'=>$amount,
            'item_name'=>$item_name
            );
    $secureString = '';
    foreach($payArray as $k=>$v){
        $secureString .= $k.'='.$v.'&';        
    }
    $secureString = substr( $secureString, 0, -1 );
    $md5 = md5($secureString);    
    $url = $burl .'/eng/process?'.$secureString;
    $url .= '&signature='.$md5.'&user_agent=Jamroom 4.3.x';
    // And forward our user over to checkout
    header('Location: '. $url);
    exit;
}
/**
 * The jrPayment_PayFast_complete_payout function is
 * used for marking a payout as "complete".
 *
 * @params array Incoming data to be used in payout
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_complete_payout($_data)
{
    global $jamroom_db;
    $req = "DELETE FROM {$jamroom_db['temp']}
             WHERE temp_key = 'jrPayFastMasspayData'
               AND temp_varchar = '". dbEscapeString($_data['key']) ."'
             LIMIT 1";
    $cnt = dbQuery($req,'COUNT');
    return true;
}
/**
 * The jrPayment_PayFast_getdata function is used for sending
 * out the PayFast Mass Pay file from the database
 *
 * @params array Incoming data to be used in payout
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_getdata($_data)
{
    global $jamroom_db;
    // Let's get our data
    $req = "SELECT *
              FROM {$jamroom_db['temp']}
             WHERE temp_key = 'jrPayFastMasspayData'
               AND temp_varchar = '". dbEscapeString($_data['filekey']) ."'
             LIMIT 1";
    $_rt = dbQuery($req,'SINGLE');
    if (!isset($_rt) || strlen($_rt['temp_text']) === 0) {
        jrNoticePage('error','Unable to retrieve PayFast Mass Pay data from the database - verify connectivity!');
    }
    // Looks good - let's send out our MASS PAY file
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Cache-Control: private");
    header("Pragma: no-cache");
    header("Content-type: application/csv");
    header("Content-Disposition: inline; filename=\"quota_id_{$_rt['temp_int']}_payout_{$_data['filekey']}.csv\"");
    header("Content-length: ". strlen($_rt['temp_text']));
    ob_start();
    echo $_rt['temp_text'];
    ob_end_flush();
    exit;
}
/**
 * The jrPayment_PayFast_payout function is used to generate a 
 * MASS PAY file for processing
 *
 * @params array Incoming data to be used in payout *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_payout($_data)
{
    global $language, $config, $jamroom, $jamroom_db, $_user;
    // Make sure we get a good quota_id
    if (!checkType($_data['quota_id'],'number_nz')) {
        jmLogger(0,'CRI',"jrPayment_PayFast_payout() invalid quota_id received!");
        return false;
    }
    if (!is_array($_data['band_ids'])) {
        jmLogger(0,'CRI',"jrPayment_PayFast_payout() invalid band_ids array received!");
        return false;
    }
    // Okay - get the payment details for each of these profiles and generate
    // our MASS PAY payment API
    $_qta = jrPaymentGetQuota($_data['quota_id']);
    $req = "SELECT *
              FROM {$jamroom_db['band_info']}
             WHERE band_id IN ('". implode("','",$_data['band_ids']) ."')
             ORDER BY band_balance DESC
             LIMIT 5000";
    $_rt = dbQuery($req,'NUMERIC');
    if (!isset($_rt[0]) || !is_array($_rt[0])) {
        jmLogger(0,'CRI',"jrPayment_PayFast_payout() unable to retrieve information for band_id payout!");
        return false;
    }
    $out = '';
    foreach ($_rt as $k => $_band) {
         if (!checkType($_band['band_payment_email'],'email')) {
             jmLogger(0,'MAJ',"jrPayment_PayFast_payout() band_id {$_band['band_id']} has invalid band_payment_email address");
             continue;
         }
         // Make sure we ar enot over our limit marks
         switch ($config['vault_currency']) {             
             case 'ZAR':
                 $limit = 50000;
                 break;
         }
         if (isset($_band['band_balance']) && $_band['band_balance'] > $limit) {
             jmLogger(0,'CRI',"jrPayment_PayFast_payout() band_id {$_band['band_id']} has band_balance larger than allowed ({$limit}): {$_band['band_balance']}");
             continue;
         }
         $out .= "{$_band['band_payment_email']},". jrPaymentNumberFormat($_band['band_balance']) .",{$config['vault_currency']},=HYPERLINK(\"https://www.payfast.co.za/eng/process?cmd=_paynow&receiver=".urlencode($_band['band_payment_email'])."&item_name=Sales&amount=". jrPaymentNumberFormat($_band['band_balance']) ."\")\n";
         
          

         
         $_dn["{$_band['band_id']}"] = $_band['band_balance'];
    }
    // Make sure we have a good entry after processing
    if (isset($out) && strlen($out) > 0) {
        // It looks like we actually had some payouts - let's store a key in the
        // database that will allow us to "mark" the payout as being processed at a later date
        $key = substr(md5(serialize($_dn)),0,8);
        // See if we have already sent out thsis mass pay file before
        $req = "SELECT *
                  FROM {$jamroom_db['temp']}
                 WHERE temp_key = 'jrPaymentPayout'
                   AND temp_varchar = '{$key}'
                 LIMIT 1";
        $_rt = dbQuery($req,'SINGLE');
        if (!isset($_rt) || strlen($_rt['temp_key']) === 0) {
            // Doesn't exist - let's add it in
            $req = "INSERT INTO {$jamroom_db['temp']} (temp_key,temp_int,temp_varchar,temp_text)
                    VALUES ('jrPaymentPayout','{$_data['quota_id']}','{$key}','". dbEscapeString(serialize($_dn)) ."')";
            $cnt = dbQuery($req,'COUNT');
            if (!isset($cnt) || $cnt != 1) {
                jmLogger(0,'CRI',"jrPayment_PayFast_payout() unable to store payout key in database - verify connectivity!");
            }
            // Now store our file data in the database
            $req = "INSERT INTO {$jamroom_db['temp']} (temp_key,temp_int,temp_varchar,temp_text)
                    VALUES ('jrPayFastMasspayData','{$_data['quota_id']}','{$key}','". dbEscapeString($out) ."')";
            $cnt = dbQuery($req,'COUNT');
            if (!isset($cnt) || $cnt != 1) {
                jmLogger(0,'CRI',"jrPayment_PayFast_payout() unable to store payout data in database - verify connectivity!");
            }
        }
        // Now - we want to refresh on ourself so we can show out directions
        // + send out the download CSV file
        $tkey = jrTempKey('set',false,'jrPaymentRunPlugin');
        ob_start();
        echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
        <html dir="'. $language['settings']['layout'] .'">
        <head>
        <meta http-equiv="refresh" content="2;url='. $jamroom['jm_htm'] .'/payment.php?mode=run_plugin&plugin=PayFast&function=getdata&filekey='. $key .'&key='. $tkey .'">
        <meta http-equiv="Content-Type" content="text/html; charset='. $language['settings']['charset'] .'">
        <meta http-equiv="Pragma" content="no-cache">
        <title>PayFast Mass Payment File</title>
        <link rel="stylesheet" type="text/css" href="'. $jamroom['jm_htm'] .'/styles/'. $_user['user_style'] .'"></head>';
        jmBodyBegin();
        jrShowNotice('success',"<div style=\"text-align:left\">Your PayFast MassPay CSV File should begin downloading momentarily.</b><br /><br /><b>IT IS VERY IMPORTANT</b> that you perform the next steps immediately to process your Mass Payment!<br /><ul></ul></div>",false);
        jmRefresh('Click here to complete the Payout Process','vault.php?mode=incomplete&quota_id='. $_data['quota_id'],'_self');
        jmBodyEnd();
        jmHtmlEnd();
        ob_end_flush();
    }
    else {
        jrNoticePage('error','Errors were encountered creating the Payout - check Activity Log for details');
    }
    return true;
}


/**
 * the jrPayment_PayFast_testconn function is used for testing the connection
 * to payfast to ensure we have communication
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_testconn()
{
    // We need to initiate a test to the PayFast sandbox with a fake transaction
    // and see if we can get PayFast to respond
    // get our config
    $cfg = jrPayments_PayFast_getconfig();
   
   $meth = 'https';
    
    // Prep our jrCallUrl call - default sandbox
    $url = $meth .'://www.payfast.co.za/eng/query/validate';    
    //$url = $meth .'://www.payfast.local/eng/query/validate';
    
        $out = jrCallUrl($url,array(),true,false,'POST',$cfg['payfast_curl_path']);
   
    
    if (strstr($out,'INVALID') || strstr($out,'sandbox.payfast.co.za')) {
        return true;
    }
    return false;
}
/**
 * the jrPayment_PayFast_validate function is used to validate the ITN POST
 * from PayFast. When a user has purchased something, and their transaction is
 * finished, PayFast will post a message back to Jamroom, whereupon Jamroom
 * must then "reply" to this post the same way it was received so PayFast knows
 * the information was retireved successfully.  Note that SSL is NOT NEEDED
 * here as no CC or personal information is sent across the net.
 *
 * @params array Incoming POST from Paypal to retransmit
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_validate(&$_args)
{
    global $config, $jamroom, $jamroom_db;
    
            $cfg = jrPayments_PayFast_getconfig();
            define('PF_DEBUG',$cfg['payfast_debug_itn']);
            $pfError = false;
            $pfErrMsg = '';
            $pfDone = false;
            $pfData = array();     
            $pfParamString = '';
        
            pflog( 'PayFast ITN call received' );
        
            //// Notify PayFast that information has been received
            if( !$pfError && !$pfDone )
            {
                header( 'HTTP/1.0 200 OK' );
                flush();
            }
        
            //// Get data sent by PayFast
            if( !$pfError && !$pfDone )
            {
                pflog( 'Get posted data' );
            
                // Posted variables from ITN
                $pfData = pfGetData();
            
                pflog( 'PayFast Data: '. print_r( $pfData, true ) );
            
                if( $pfData === false )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_BAD_ACCESS;
                }
            }
           
            //// Verify security signature
            if( !$pfError && !$pfDone )
            {
                pflog( 'Verify security signature' );
            
                // If signature different, log for debugging
                if( !pfValidSignature( $pfData, $pfParamString ) )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
                }
            }
        
            //// Verify source IP (If not in debug mode)
            if( !$pfError && !$pfDone && !PF_DEBUG )
            {
                pflog( 'Verify source IP' );
            
                if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
                {
                    $pfError = true;
                    $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
                }
            }
        
          
            if( !$pfError && !$pfDone )
            {
              
                
            }   
            // Make sure it is Completed
            if ($_args['payment_status'] != 'COMPLETE') 
            {               
                
                return false;
            }   
            // First, we want to make sure we are getting a vault payment
            if (strpos($_args['m_payment_id'],'v_') !== 0) 
            {
                jmLogger(0,'MAJ',"jrPayment_PayFast_txn_action() instant: invalid item_number: ". $_args['m_payment_id']);
                return false;
            }
            // Next - we need to validate that the item_number we receive is the CORRECT item_number
            $req = "SELECT *
                      FROM {$jamroom_db['temp']}
                     WHERE temp_varchar = 'jrPaymentCheckout'
                       AND temp_key = '". dbEscapeString($_args['m_payment_id']) ."'
                     LIMIT 1";
            $_rt = dbQuery($req,'SINGLE');
            if (!isset($_rt) || strlen($_rt['temp_text']) === 0) 
            {
                jmLogger(0,'MAJ',"jrPayment_PayFast_txn_action() instant: invalid item_number: ". $_args['m_payment_id']);
                return false;
            }    
            
            $_tmp = @unserialize($_rt['temp_text']); 
            
            if (!isset($_tmp) || !is_array($_tmp)) 
            { 
                jmLogger(0,'MAJ',"jrPayment_PayFast_txn_action() instant: unable to retrieve cart for item_number: ". $_args['m_payment_id']);
                return false;
            }
            
            // Add up our prices and validate
            $tot = 0;
            foreach ($_tmp as $iid => $_inf) 
            {
               if (checkType($iid,'number_nz')) 
               {
                   $tot += $_inf['price'];
                   // Add in handling for this item if present
                   if (isset($_inf['handling']) && $_inf['handling'] > 0) 
                   {
                       $tot += $_inf['handling'];
                   }
               }
            }
            // See if we had a service charge in this transaction
            $sch = false;
            if (isset($_tmp[0]) && $_tmp[0] > 0)
            {
                $sch = $_tmp[0];
                unset($_tmp[0]);
            }
           
           
            // Add in service charge if needed
            if (isset($sch) && $sch !== false) 
            {
                $tot += $sch;
            }
            $sch = jrPaymentNumberRound(trim($sch));
            $tot = jrPaymentNumberRound(trim($tot));  // This is what we SHOULD RECEIVE
            $grs = jrPaymentNumberRound($_args['amount_gross']); // This is what we ACTUALLY received
            // Note that we use "LESS THAN" here in case TAXES are added
            if (!isset($grs) || $grs < $tot) 
            {
                // Our totals don't line up - this could be because of
                // tax on the processor end, user credit or shipping
                // Check for User Credit and adjust gross
                // NOTE: $_rt['temp_int'] = puchasing User ID
                $grs = jrUserCredit_adjust_credit($_rt['temp_int'],$tot,$grs,$_args['m_payment_id']);
                if (!isset($grs) || $grs < $tot) {
                    jmLogger(0,'CRI',"jrPayment_PayFast_txn_action() unable to reconcile received price: ". jrPaymentNumberFormat($grs) ." should be: ". jrPaymentNumberFormat($tot) ." for txn_id {$_args['m_payment_id']}");
                    return false;
                }
            }
            // Okay - looks like they did not cheat at all, let's add
            // these items to the user's "My Files" section
            // $_rt['temp_int'] = User_ID that purchased items
            $uid = jrPaymentUpdateMyFiles($_rt['temp_int'],$_tmp,$_args);
            if (isset($uid) && is_numeric($uid)) 
            {
                // Looks like we successfully added the item to the user id - note that if the user was not logged in,
                // a user account was created for them, and jrPaymentUpdateMyFiles will return the new user_id
                $_rt['temp_int'] = $uid;
                // cleanup jamroom_temp
                $req = "DELETE FROM {$jamroom_db['temp']}
                         WHERE temp_varchar = 'jrPaymentCheckout'
                           AND temp_key = '". dbEscapeString($_args['m_payment_id']) ."'
                         LIMIT 1";
                $cnt = dbQuery($req,'COUNT');
                if (!isset($cnt) || $cnt != 1) 
                {
                    jmLogger(0,'CRI',"jrPayment_PayFast_txn_action() unable to delete temp cart for user_id {$_rt['temp_int']}");
                }
                // And now reset their cart
                jrPaymentUpdateCart($_rt['temp_int'],'delete');
            } 
            // send out the email to let them know their purchase is complete - send receipt
            jrPaymentPurchaseReceipt($_args['email_address'],$_args['amount_gross'],$_tmp);
            // $_tmp contains our cart contents as item_id => price
            $tmp = jrPaymentDistributeFunds($_args['amount_gross'],-$_args['amount_fee'],$_tmp,$_args['m_payment_id'],$sch,$_rt['temp_int'],$_args['tax']);
            // Cleanup purged downloads
            jrPaymentPurgeDownloads();           
} 
/**
 * The jrPayment_PayFast_browser_entry function is used by the Payments Browser
 * plugin to get some sepcific information about payer, amount and fee from
 * a transaction.
 *
 * @params array transaction details to pull from
 *
 * @return array Returns array on success
 */
function jrPayment_PayFast_browser_entry($_entry,$_txn,$search)
{
    global $config;
    // get our config
    if (!isset($GLOBALS['jr_payments_config']) || !is_array($GLOBALS['jr_payments_config'])) {
        $GLOBALS['jr_payments_config'] = jrPayments_PayFast_getconfig();
    }
    // check for search
    if (isset($search) && strlen($search) > 0) {
        $_entry['txn_type']     = jrSearchHighlight($_entry['txn_type'],$search,'jmFont3');
        $_entry['txn_trans_id'] = jrSearchHighlight($_entry['txn_trans_id'],$search,'jmFont3');
    }
    $dat[1]['title'] = $_entry['txn_id'];
    $dat[1]['style'] = 'text-align:center;';
    $dat[2]['title'] = gmstrftime($config['date1'],convertTime($_entry['txn_time'],$config['server_offset']));
    $dat[2]['style'] = 'text-align:center;';
    $dat[3]['title'] = $_entry['txn_type'];
    $dat[3]['style'] = 'text-align:center;';
   
    $dat[4]['title'] = $_entry['txn_trans_id'] ;
    $dat[4]['style'] = 'text-align:center;';
    $sym = 'R';
    $GLOBALS['jr_total_currency_symbol'] = $sym;
    $dat[5]['title'] = '<a href="mailto:'. $_txn['email_address'] .'">'. $_txn['email_address'] .'</a>';
    $dat[5]['style'] = 'text-align: center;';
    $dat[6]['title'] = 'payfast_payment';
    $dat[6]['style'] = 'text-align: center;';
    if (isset($_txn['amount_gross']) && strlen($_txn['amount_gross']) > 0) {
        $dat[7]['title'] = $sym . $_txn['amount_gross'] .' ZAR';
        $GLOBALS['jr_total_txn_amount'] += $_txn['amount_gross'];
    }
    else {
        $dat[7]['title'] = '-';
    }
    $dat[7]['style'] = 'text-align: center;';
    if (isset($_txn['amount_fee']) && strlen($_txn['amount_fee']) > 0) {
        $dat[8]['title'] = $sym . $_txn['amount_fee'];
        $GLOBALS['jr_total_fee_amount'] += $_txn['amount_fee'];
    }
    else {
        $dat[8]['title'] = '-';
    }
    $dat[8]['style'] = 'text-align:center;';
    list($pw,$ph) = jrGetPopupInfo('txn_details',620,500);
    $dat[9]['title'] = jrHtmlButtonCode('details','popwin(\'payment.php?mode=tx_detail&plug=PayFast&txn_id='. $_entry['txn_id'] .'\',\'txn_details\','. $pw .','. $ph .',\'yes\');return false');
    return $dat;
}
/**
 * The jrPayment_PayFast_txn_detail function is used to display Transaction details
 *
 * @params array transaction
 *
 * @return bool returns true/false on success/fail
 */
function jrPayment_PayFast_txn_detail($_txn)
{
    $dat[1]['title'] = 'Parameter';
    $dat[2]['title'] = 'Value';
    htmlPageSelect('header',$dat,'jmLog_header');
    $_txn['txn_text'] = unserialize($_txn['txn_text']);
    ksort($_txn['txn_text']);
    foreach ($_txn['txn_text'] as $param => $value) {
        $dat[1]['title'] = $param;
        $dat[2]['title'] = $value;
        htmlPageSelect('row',$dat,'jmLog_line');
    }
    htmlPageSelect('footer');
}
/**
 * The jrPayment_PayFast_txn_address function is used for returning
 * a "formatted" shipping address based on a purchase
 *
 * @params array transaction
 *
 * @return string Returns formatted address
 */
function jrPayment_PayFast_txn_address($_txn)
{
    if (!isset($_txn) || !is_array($_txn)) {
        return false;
    }
    $out = "{$_txn['address_name']}\n{$_txn['address_street']}\n{$_txn['address_city']}, {$_txn['address_state']} {$_txn['address_zip']}\n{$_txn['address_country']}";
    return $out;
}
?>
