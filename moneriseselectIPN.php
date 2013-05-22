<?php

class moneriseselectIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  protected static $_mode = null;

  static function retrieve($name, $type, $object, $abort = true) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === null) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name");
      echo "Failure: Missing Parameter - " . $name . "<p>";
      exit();
    }

    if ($value) {
      if (!CRM_Utils_Type::validate($value, $type)) {
        CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
        echo "Failure: Invalid Parameter<p>";
        exit();
      }
    }

    return $value;
  }

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    parent::__construct();

    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === null) {
      self::$_singleton = new moneriseselectIPN($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * The function gets called when a new order takes place.
   *
   * @param array  $privateData  contains the CiviCRM related data
   * @param string $component    the CiviCRM component
   * @param array  $moneriseselectData contains the Merchant related data
   *
   * @return void
   *
   */
  function newOrderNotify($success, $privateData, $component, $moneriseselectData) {
    $ids = $input = $params = array();

    $input['component'] = strtolower($component);
    $ids['contact'] = self::retrieve('contactID', 'Integer', $privateData, true);
    $ids['contribution'] = self::retrieve('contributionID', 'Integer', $privateData, true);

    if ($input['component'] == "event") {
      $ids['event'] = self::retrieve('eventID', 'Integer', $privateData, true);
      $ids['participant'] = self::retrieve('participantID', 'Integer', $privateData, true);
      $ids['membership'] = NULL;
    }
    else {
      $ids['membership'] = self::retrieve('membershipID', 'Integer', $privateData, FALSE);
      $ids['related_contact'] = self::retrieve('relatedContactID', 'Integer', $privateData, FALSE);
      $ids['onbehalf_dupe_alert'] = self::retrieve('onBehalfDupeAlert', 'Integer', $privateData, FALSE);
      $ids['contributionRecur'] = self::retrieve('contributionRecurID', 'Integer', $privateData, FALSE);
    }

    $ids['contributionRecur'] = $ids['contributionPage'] = null;
    if (!$this->validateData($input, $ids, $objects)) {
      return false;
    }

    // make sure the invoice is valid and matches what we have in the contribution record
    $contribution = & $objects['contribution'];
    $input['invoice'] = $privateData['invoiceID'];
    $input['trxn_id'] = $moneriseselectData['trxn_id'];
    $input['is_test'] = $privateData['is_test'];
    $input['amount'] = $moneriseselectData['PurchaseAmount'];

    $transaction = new CRM_Core_Transaction();

    /*
      Valid-Approved : The transaction was approved and successfully validated
      Valid-Declined : The transaction was declined and successfully validated
      Invalid : No reference to this transactionKey, validation failed
      Invalid-ReConfirmed : An attempt has already been made with this transaction key,
      validation failed
      Invalid-Bad_Source : The Referring URL is not correct, validation failed
     */

    switch ($moneriseselectData['status']) {
      case 'Invalid-ReConfirmed':
        break;
      case 'Invalid':
      case 'Invalid-Bad_Source':
      case 'Valid-Declined':
        return $this->failed($objects, $transaction);
        break;
    }

    if ($contribution->invoice_id != $input['invoice']) {
      CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
      echo "Failure: Invoice values dont match between database and IPN request<p>";
      return;
    }

    if ($contribution->total_amount != $input['amount']) {
      CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
      echo "Failure: Amount values dont match between database and IPN request. " . $contribution->total_amount . "/" . $input['amount'] . "<p>";
      return;
    }

    // check if contribution is already completed, if so we ignore this ipn
    if ($contribution->contribution_status_id == 1) {
      $transaction->commit();
      CRM_Core_Error::debug_log_message("returning since contribution has already been handled");
      echo "Success: Contribution has already been handled<p>";
      return true;
    }
    else {

      if (CRM_Utils_Array::value('event', $ids)) {
        $contribution->trxn_id = $ids['event'] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['participant'];
      }
      elseif (CRM_Utils_Array::value('membership', $ids)) {
        $contribution->trxn_id = $ids['membership'][0] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['related_contact'] . CRM_Core_DAO::VALUE_SEPARATOR . $ids['onbehalf_dupe_alert'];
      }
    }

    $this->completeTransaction($input, $ids, $objects, $transaction);
    return true;
  }

  /**
   * The function returns the component(Event/Contribute..)and whether it is Test or not
   *
   * @param array   $privateData    contains the name-value pairs of transaction related data
   *
   * @return array context of this call (test, component, payment processor id)
   * @static
   */
  static function getContext($privateData) {

    $component = null;
    $isTest = null;

    $contributionID = $privateData['contributionID'];
    $contribution = & new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionID;

    if (!$contribution->find(true)) {
      CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
      echo "Failure: Could not find contribution record for $contributionID<p>";
      exit();
    }

    if (stristr($contribution->source, 'Online Contribution')) {
      $component = 'contribute';
    }
    else if (stristr($contribution->source, 'Online Event Registration')) {
      $component = 'event';
    }
    $isTest = $contribution->is_test;

    $duplicateTransaction = 0;
    if ($contribution->contribution_status_id == 1) {
      //contribution already handled. (some processors do two notifications so this could be valid)
      $duplicateTransaction = 1;
    }

    if ($component == 'contribute') {
      if (!$contribution->contribution_page_id) {
        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
        echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
        exit();
      }
    }
    else {

      $eventID = $privateData['eventID'];
      if (!$eventID) {
        CRM_Core_Error::debug_log_message("Could not find event ID");
        echo "Failure: Could not find eventID<p>";
        exit();
      }

      // we are in event mode
      // make sure event exists and is valid
      //require_once 'CRM/Event/DAO/Event.php';
      $event = & new CRM_Event_DAO_Event();
      $event->id = $eventID;

      if (!$event->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find event: $eventID");
        echo "Failure: Could not find event: $eventID<p>";
        exit();
      }
    }

    return array(
      $isTest,
      $component,
      $duplicateTransaction
    );
  }

  /**
   * This method is handles the response that will be invoked by the
   * notification or request sent by the payment processor.
   * hex string from paymentexpress is passed to this function as hex string.
   */
  function main($moneriseselectPostData) {
    CRM_Core_Error::debug_var('$moneriseselectPostData', $moneriseselectPostData);
    $config = CRM_Core_Config::singleton();
    $success = false;

    $component = $moneriseselectPostData['rvar_module'];
    $qfKey = $moneriseselectPostData['rvar_qfKey'];

    $privateData = $ids = $objects = array();
    $privateData['transactionID'] = $moneriseselectPostData['bank_transaction_id'];
    $privateData['contributionID'] = $moneriseselectPostData['rvar_contributionID'];
    $privateData['contactID'] = $moneriseselectPostData['rvar_contactID'];
    $privateData['invoiceID'] = $moneriseselectPostData['response_order_id'];

    if ($component == "event") {
      $privateData['participantID'] = $moneriseselectPostData['rvar_participantID'];
      $privateData['eventID'] = $moneriseselectPostData['rvar_eventID'];
    }
    else if ($component == "contribute") {
      $privateData["membershipID"] = array_key_exists('rvar_membershipID', $moneriseselectPostData) ? $moneriseselectPostData['rvar_membershipID'] : '';
      $privateData["relatedContactID"] = array_key_exists('rvar_relatedContactID', $moneriseselectPostData) ? $moneriseselectPostData['rvar_relatedContactID'] : '';
      $privateData["onbehalf_dupe_alert"] = array_key_exists('rvar_onbehalf_dupe_alert', $moneriseselectPostData) ? $moneriseselectPostData['rvar_onbehalf_dupe_alert'] : '';
    }

    list ($mode, $component, $duplicateTransaction) = self::getContext($privateData);
    $mode = $mode ? 'test' : 'live';
    $privateData['is_test'] = $mode;

    /**
     * Fix me as per civicrm versions
     * In below 4.2 version 'CRM_Core_BAO_PaymentProcessor'
     * */
    $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($this->_paymentProcessor['id'], $mode);
    $ipn = self::singleton($mode, $component, $paymentProcessor);

    /*
     * It is advised that you log the initial transaction response and then compare the Transaction
     * Verification response to ensure authenticity.
     */
    $url = $paymentProcessor['url_site'];
    $moneriseselectParams['ps_store_id'] = $paymentProcessor['signature'];
    $moneriseselectParams['hpp_key'] = $paymentProcessor['user_name'];
    $moneriseselectParams['transactionKey'] = $moneriseselectPostData['transactionKey'];

    $verifyTrans = $this->_transactionVerification($moneriseselectParams, $url);
    CRM_Core_Error::debug_var('$verifyTrans', $verifyTrans);


    $moneriseselectData = array();
    $moneriseselectData['trxn_id'] = $moneriseselectPostData['bank_transaction_id'];
    $moneriseselectData['PurchaseAmount'] = $verifyTrans['amount'];
    //$moneriseselectData['transactionID'] = $verifyTrans['txn_num'];
    $moneriseselectData['status'] = $verifyTrans['status'];

    if ($verifyTrans['response_code'] < 50 && $moneriseselectPostData['result'] == 1 && $moneriseselectPostData['txn_num'] == $verifyTrans['txn_num']) {
      $success = TRUE;
    }

    if ($duplicateTransaction == 0) {
      $ipn->newOrderNotify($success, $privateData, $component, $moneriseselectData);
    }

    //Check status and take appropriate action
    if ($component == "event") {
      $baseURL = 'civicrm/event/register';
      $query = $success ? "_qf_ThankYou_display=true&qfKey={$qfKey}" : "_qf_Register_display=1&cancel=1&qfKey={$qfKey}";
    }
    else if ($component == "contribute") {
      $baseURL = 'civicrm/contribute/transact';
      $query = $success ? "_qf_ThankYou_display=true&qfKey={$qfKey}" : "_qf_Main_display=1&cancel=1&qfKey={$qfKey}";
    }
    else {
      // Invalid component
      CRM_Core_Error::fatal(ts('Invalid component "' . $component . '" selected.'));
      exit();
    }

    $finalURL = CRM_Utils_System::url($baseURL, $query, false, null, false);
    CRM_Utils_System::redirect($finalURL);
  }

  function _transactionVerification($params, $url) {
    if (!function_exists('curl_init')) {
      CRM_Core_Error::fatal("curl functions NOT available.");
    }

    $url = $url . 'HPPDP/verifyTxn.php';

    $p = array();
    foreach ($params as $n => $v) {
      $p[] = "$n=" . urlencode($v);
    }

    //NVPRequest for submitting to server
    $nvpreq = implode('&', $p);
    $postlength = strlen($nvpreq);
    $submit = curl_init();
    curl_setopt($submit, CURLOPT_POST, $postlength);
    curl_setopt($submit, CURLOPT_HEADER, 0);
    curl_setopt($submit, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($submit, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($submit, CURLOPT_URL, $url);
    curl_setopt($submit, CURLOPT_TIMEOUT, 10);
    curl_setopt($submit, CURLOPT_POSTFIELDS, $nvpreq);
    curl_setopt($submit, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($submit);

    if (!$response) {
      return self::error(curl_errno($submit), curl_error($submit));
    }
    curl_close($submit);

    $xmlObj = simplexml_load_string($response);
    foreach ((array) $xmlObj as $index => $value)
      $result[$index] = ( is_object($value) ) ? (array) $value : $value;

    return $result;
  }

  function &error($errorCode = null, $errorMessage = null) {
    $e = & CRM_Core_Error::singleton();
    if ($errorCode) {
      $e->push($errorCode, 0, null, $errorMessage);
    }
    else {
      $e->push(9001, 0, null, 'Unknowns System Error.');
    }
    return $e;
  }

}

