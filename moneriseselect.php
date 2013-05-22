<?php

require_once 'moneriseselect.civix.php';
require_once 'moneriseselectpayment.php';
/**
 * Implementation of hook_civicrm_config
 */
function moneriseselect_civicrm_config(&$config) {
  _moneriseselect_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function moneriseselect_civicrm_xmlMenu(&$files) {
  _moneriseselect_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function moneriseselect_civicrm_install() {
  return _moneriseselect_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function moneriseselect_civicrm_uninstall() {

  CRM_Core_DAO::executeQuery("DELETE pp FROM civicrm_payment_processor pp
RIGHT JOIN  civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
WHERE ppt.name = 'MonerisEselect'");

  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Moneris Eselect Payment Processor Message:
    <br />Entries for Moneris Eselect hosted Payment Processor are now Deleted!
    <br />");

  return _moneriseselect_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function moneriseselect_civicrm_enable() {

  CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor pp
RIGHT JOIN  civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
SET pp.is_active = 1
WHERE ppt.name = 'MonerisEselect'");

  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Moneris Eselect Payment Processor Message:
    <br />Entries for Moneris Eselect hosted Payment Processor are now Enabled!
    <br />");

  return _moneriseselect_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function moneriseselect_civicrm_disable() {

  CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor pp
RIGHT JOIN  civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
SET pp.is_active = 0
WHERE ppt.name = 'MonerisEselect'");

  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Moneris Eselect Payment Processor Message:
    <br />Entries for Moneris Eselect hosted Payment Processor are now Disabled!
    <br />");

  return _moneriseselect_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function moneriseselect_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _moneriseselect_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function moneriseselect_civicrm_managed(&$entities) {
$entities[] = array(
    'module' => 'com.webaccessglobal.moneriseselect',
    'name' => 'MonerisEselect',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'MonerisEselect',
      'title' => 'Moneris Eselect Hosted',
      'description' => 'Moneris Eselect Hosted Payment Processor',
      'class_name' => 'com.webaccessglobal.moneriseselect',
      'billing_mode' => 'notify',
      'user_name_label' => 'HPP Key',
      'signature_label' => 'PS Store ID',
      'url_site_default' => 'https://www3.moneris.com/',
      'url_site_test_default' => 'https://esqa.moneris.com/',
      'payment_type' => 1,
    ),
  );
  return _moneriseselect_civix_civicrm_managed($entities);
}
