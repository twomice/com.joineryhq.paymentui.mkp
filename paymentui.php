<?php

require_once 'paymentui.civix.php';
use CRM_Paymentui_ExtensionUtil as E;

/**
 * Implements hook_civicrm_alterPaymentProcessorParams().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterPaymentProcessorParams
 */
function paymentui_civicrm_alterPaymentProcessorParams($paymentObj, &$rawParams, &$cookedParams) {
  // Don't bother unless we're coming from our own PaymentUI page.
  if (CRM_Utils_Array::value('isPaymentuiForm', $rawParams) == 1) {
    // Get event titles for any participations for which payments are submitted.
    $paidParticipantIds = array();
    foreach (CRM_Utils_Array::value('payment', $rawParams, array(0)) as $participantId => $amount) {
      if ($amount > 0) {
        $paidParticipantIds[] = $participantId;
      }
    }
    $apiParams = array(
      'id' => array('IN' => $paidParticipantIds),
      'return' => "event_id",
    );
    $result = civicrm_api3('Participant', 'get', $apiParams);
    $titles = CRM_Utils_Array::collect('event_title', CRM_Utils_Array::value('values', $result));
    if (!empty($titles)) {
      // Concatenate event titles into the 'desc' parameter sent to the payment processor.
      // TODO: This works for paypal pro; add support for other processors?
      $desc = ts('Partial payment for event(s): %1', array(
        '1' => implode($titles, '; '),
      ));
      $cookedParams['desc'] = $desc;
      $cookedParams['description'] = $desc;
    }
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function paymentui_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Event_Form_ManageEvent_Fee') {
    // Add is_paymentui checkbox to form in 'beforeHookFormElements').
    $form->addElement('checkbox', 'is_paymentui', E::ts('Include participants in Partial Payments UI'));
    $tpl = & CRM_Core_Smarty::singleton();
    $bhfe = (array) $tpl->get_template_vars('beginHookFormElements');
    $bhfe[] = 'is_paymentui';
    $form->assign('beginHookFormElements', $bhfe);
    // Get value from settings and set default.
    $eventSettings = CRM_Paymentui_Settings::getEventSettings($form->_id);
    $defaults = array(
      'is_paymentui' => CRM_Utils_Array::value('is_paymentui', $eventSettings, 0),
    );
    $form->setDefaults($defaults);
    // Add JavaScript which will position the field correctly within the form.
    CRM_Core_Resources::singleton()->addScriptFile('com.joineryhq.paymentui.mkp', 'js/CRM_Event_Form_ManageEvent_Fee.js');
  }
}

/**
 * Implements hook_civicrm_postProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postProcess
 */
function paymentui_civicrm_postProcess($formName, &$form) {
  // Save is_paymentui setting as set in form submission.
  if ($formName == 'CRM_Event_Form_ManageEvent_Fee') {
    $eventSettings = CRM_Paymentui_Settings::getEventSettings($form->_id);
    $eventSettings['is_paymentui'] = CRM_Utils_Array::value('is_paymentui', $form->_submitValues, 0);
    CRM_Paymentui_Settings::saveAllEventSettings($form->_id, $eventSettings);
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function paymentui_civicrm_config(&$config) {
  _paymentui_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function paymentui_civicrm_xmlMenu(&$files) {
  _paymentui_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function paymentui_civicrm_install() {
  return _paymentui_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function paymentui_civicrm_uninstall() {
  return _paymentui_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function paymentui_civicrm_enable() {
  return _paymentui_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function paymentui_civicrm_disable() {
  return _paymentui_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function paymentui_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _paymentui_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function paymentui_civicrm_managed(&$entities) {
  return _paymentui_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function paymentui_civicrm_caseTypes(&$caseTypes) {
  _paymentui_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function paymentui_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _paymentui_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_permission().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_permission
 */
function paymentui_civicrm_permission(&$permissions) {
  $permissions += array(
    'paymentui_add_payments' => array(
      ts('Submit Additional Payments', array('domain' => 'com.joineryhq.paymentui.mkp')),
      ts('Allows for submitting additional payments against existing partially paid balances.', array('domain' => 'com.joineryhq.paymentui.mkp')),
    ),
  );
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function paymentui_civicrm_navigationMenu(&$menu) {
  _paymentui_get_max_navID($menu, $max_navID);
  _paymentui_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', array(
    'label' => ts('Partial Payments UI', array('domain' => 'com.joineryhq.paymentui.mkp')),
    'name' => 'Partial Payments UI',
    'url' => 'civicrm/admin/paymentui/settings',
    'permission' => 'administer CiviCRM',
    'operator' => 'AND',
    'separator' => NULL,
    'navID' => ++$max_navID,
  ));
  _paymentui_civix_navigationMenu($menu);
}

/**
 * For an array of menu items, recursively get the value of the greatest navID
 * attribute.
 * @param <type> $menu
 * @param <type> $max_navID
 */
function _paymentui_get_max_navID(&$menu, &$max_navID = NULL) {
  foreach ($menu as $id => $item) {
    if (!empty($item['attributes']['navID'])) {
      $max_navID = max($max_navID, $item['attributes']['navID']);
    }
    if (!empty($item['child'])) {
      _paymentui_get_max_navID($item['child'], $max_navID);
    }
  }
}
