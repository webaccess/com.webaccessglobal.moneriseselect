<?php

class com_webaccessglobal_moneriseselect extends CRM_Core_Payment {

  CONST CHARSET = 'UFT-8';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  protected static $_mode = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('MonerisEselect');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (!isset(self::$_singleton[$processorName]) || self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new com_webaccessglobal_moneriseselect($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /*
   * This function  sends request and receives response from
   * the processor. It is the main function for processing on-server
   * credit card transactions
   */

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  function doTransferCheckout(&$params, $component = 'contribute') {

    /**
     * Request data
     * */
    $data = array(
      'type' => 'purchase',
      'ps_store_id' => $this->_paymentProcessor['signature'],
      'hpp_key' => $this->_paymentProcessor['user_name'],
      'charge_total' => sprintf('%01.2f', $params['amount']),
      'order_id' => $params['invoiceID'],
      'amount' => sprintf('%01.2f', $params['amount']),
      'cust_id' => $params['contactID'],
      'id1' => $params['invoiceID'],
      'description1' => $params['description'],
      'quantity1' => 1,
      'price1' => sprintf('%01.2f', $params['amount']),
      'subtotal1' => sprintf('%01.2f', $params['amount']),
      'rvar_qfKey' => $params['qfKey'],
      'rvar_contactID' => $params['contactID'],
      'rvar_contributionID' => $params['contributionID'],
      'rvar_contributionTypeID' => $params['contributionTypeID'],
      'rvar_module' => $component,
    );

    if (array_key_exists('email-5', $params) || array_key_exists('email-Primary', $params))
      $data['email'] = array_key_exists('email-5', $params) ? $params['email-5'] : $params['email-Primary'];

    if ($component == 'event') {
      $data['rvar_eventID'] = $params['eventID'];
      $data['rvar_participantID'] = $params['participantID'];
    }
    else {
      $membershipID = CRM_Utils_Array::value('membershipID', $params);
      if ($membershipID) {
        $data['rvar_membershipID'] = $membershipID;
      }
      $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
      if ($relatedContactID) {
        $data['rvar_relatedContactID'] = $relatedContactID;

        $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
        if ($onBehalfDupeAlert) {
          $data['rvar_onbehalf_dupe_alert'] = $onBehalfDupeAlert;
        }
      }
    }

    $otherVars = array(
      'first_name' => 'bill_first_name',
      'last_name' => 'bill_last_name',
      'street_address' => 'bill_address_one',
      'country' => 'bill_country',
      'city' => 'bill_city',
      'state_province' => 'bill_state_or_province',
      'postal_code' => 'bill_postal_code',
      'email' => 'email',
    );
    foreach (array_keys($params) as $p) {
      // get the base name without the location type suffixed to it
      $parts = explode('-', $p);
      $name = count($parts) > 1 ? $parts[0] : $p;
      if (isset($otherVars[$name])) {
        $value = $params[$p];
        if ($value) {
          if ($name == 'state_province') {
            $stateName = CRM_Core_PseudoConstant::stateProvinceAbbreviation($value);
            $value = $stateName;
          }
          if ($name == 'country') {
            $countryName = CRM_Core_PseudoConstant::countryIsoCode($value);
            $value = $countryName;
          }
          // ensure value is not an array
          // CRM-4174
          if (!is_array($value)) {
            $data[$otherVars[$name]] = $value;
          }
        }
      }
    }

    $action = $this->_paymentProcessor['url_site'] . 'HPPDP/index.php';
    $uri = '';
    foreach ($data as $key => $value) {
      if ($value === NULL) {
        continue;
      }

      $value = urlencode($value);
      $uri .= "&{$key}={$value}";
    }

    $uri = substr($uri, 1);
    $uri = "{$action}?$uri";

    CRM_Utils_System::redirect($uri);
  }

  function isError(&$response) {
    $responseCode = $response->getResponseCode();
    if (is_null($responseCode)) {
      return TRUE;
    }
    if ('null' == $responseCode) {
      return TRUE;
    }
    if (($responseCode >= 0) && ($responseCode < 50)) {
      return FALSE;
    }
    return TRUE;
  }

  // ignore for now, more elaborate error handling later.
  function &checkResult(&$response) {
    return $response;

    $errors = $response->getErrors();
    if (empty($errors)) {
      return $result;
    }

    $e = CRM_Core_Error::singleton();
    if (is_a($errors, 'ErrorType')) {
      $e->push($errors->getErrorCode(), 0, NULL, $errors->getShortMessage() . ' ' . $errors->getLongMessage()
      );
    }
    else {
      foreach ($errors as $error) {
        $e->push($error->getErrorCode(), 0, NULL, $error->getShortMessage() . ' ' . $error->getLongMessage()
        );
      }
    }
    return $e;
  }

  function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(), 0, NULL, $error->getMessage()
      );
    }
    elseif (is_string($error)) {
      $e->push(9002, 0, NULL, $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = array();
    if (empty($this->_paymentProcessor['signature'])) {
      $error[] = ts('PS Store ID is not set in the Administer CiviCRM & raquo;
          System Settings & raquo;
          Payment Processors.');
    }

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('HPP KEY is not set in the Administer CiviCRM & raquo;
          System Settings & raquo;
          Payment Processors.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Handle return response from payment processor
   */
  function handlePaymentNotification() {
    require_once 'moneriseselectipn.php';
    $MonerisEselectIPN = new com_webaccessglobal_moneriseselectIPN($this->_mode, $this->_paymentProcessor);
    $MonerisEselectIPN->main($_POST);
  }

}
