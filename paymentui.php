<?php

require_once 'paymentui.civix.php';
use CRM_Paymentui_ExtensionUtil as E;

/**
 * Implements hook_civicrm_alterPaymentProcessorParams().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterPaymentProcessorParams
 */
function fixme_zz_paymentui_civicrm_alterPaymentProcessorParams($paymentObj, &$rawParams, &$cookedParams) {
  // Don't bother unless we're coming from our own PaymentUI page.
  if ($rawParams['isPaymentuiForm'] == 1) {
    // Get event titles for any participations for which payments are submitted.
    $paidParticipantIds = [];
    foreach (($rawParams['payment'] ?? [0]) as $participantId => $amount) {
      if ($amount > 0) {
        $paidParticipantIds[] = $participantId;
      }
    }
    $apiParams = [
      'id' => ['IN' => $paidParticipantIds],
      'return' => "event_id",
    ];
    $result = civicrm_api3('Participant', 'get', $apiParams);
    $titles = CRM_Utils_Array::collect('event_title', ($result['values'] ?? []));
    if (!empty($titles)) {
      // Concatenate event titles into the 'desc' parameter sent to the payment processor.
      // TODO: This works for paypal pro; add support for other processors?
      $desc = ts('Partial payment for event(s): %1', [
        '1' => implode($titles, '; '),
      ]);
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
    $defaults = [
      'is_paymentui' => ($eventSettings['is_paymentui'] ?? 0),
    ];
    $form->setDefaults($defaults);
    // Add JavaScript which will position the field correctly within the form.
    CRM_Core_Resources::singleton()->addScriptFile('com.joineryhq.paymentui.mkp', 'js/CRM_Event_Form_ManageEvent_Fee.js');
  }
  elseif (is_a($form, 'CRM_Contribute_Form_ContributionPage')) {
    $contributionPageId = $form->get('id');
    $paymentUiContributionPageId = \Civi::settings()->get('paymentui_contribution_page_id');

    if ($contributionPageId == $paymentUiContributionPageId) {
      $configValidator = new CRM_Paymentui_Configvalidator($contributionPageId);
      if (!$configValidator->isValid()) {
        $statusMsg = ts('This Contribution Page is selected for the Partial Payments User Interface, but it has some incompatible configurations. ') . ' ' . CRM_Paymentui_Util::getContributionPageConfigHelpMessage();
        CRM_Core_Session::setStatus($statusMsg, ts('Warning'), 'error');
      }
    }
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
    $eventSettings['is_paymentui'] = ($form->_submitValues['is_paymentui'] ?? 0);
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
  // Bind our event listeners.
  Civi::dispatcher()->addListener('civi.api.prepare', ['CRM_Paymentui_APIWrapper', 'PREPARE'], -100);
  Civi::dispatcher()->addListener('civi.api.respond', ['CRM_Paymentui_APIWrapper', 'RESPOND'], -100);
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
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function paymentui_civicrm_enable() {
  return _paymentui_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_permission().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_permission
 */
function paymentui_civicrm_permission(&$permissions) {
  $permissions += [
    'paymentui_add_payments' => [
      'label' => ts('Submit Additional Payments', ['domain' => 'com.joineryhq.paymentui.mkp']),
      'description' => ts('Allows for submitting additional payments against existing partially paid balances.', ['domain' => 'com.joineryhq.paymentui.mkp']),
    ],
  ];
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
function paymentui_civicrm_navigationMenu(&$menu) {
  _paymentui_get_max_navID($menu, $max_navID);
  _paymentui_civix_insert_navigation_menu($menu, 'Administer/Customize Data and Screens', [
    'label' => ts('Partial Payments UI', ['domain' => 'com.joineryhq.paymentui.mkp']),
    'name' => 'Partial Payments UI',
    'url' => 'civicrm/admin/paymentui/settings',
    'permission' => 'administer CiviCRM',
    'operator' => 'AND',
    'separator' => NULL,
    'navID' => ++$max_navID,
  ]);
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

// /**
//  * Implements hook_civicrm_postInstall().
//  *
//  * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
//  */
// function paymentui_civicrm_postInstall() {
//   _paymentui_civix_civicrm_postInstall();
// }
