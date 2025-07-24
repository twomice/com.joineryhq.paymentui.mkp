<?php

use CRM_Paymentui_ExtensionUtil as E;

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 */
class CRM_Paymentui_Form_Paymentui extends CRM_Contribute_Form_Contribution_Main {
  private $_participantInfo = [];

  public function preProcess() {
    $this->_contactID = $this->getContactID();
    $participantInfo = CRM_Paymentui_BAO_Paymentui::getParticipantInfo($this->_contactID);
    $this->_participantInfo = $participantInfo;
    if (!$this->getContributionPageID()) {
      return;
    }
    return parent::preProcess();
  }

  /**
   * Function to build the form
   *
   * @return void
   * @access public
   */
  public function buildQuickForm() {
    // Ensure a contribution page has been selected in the extension settings.
    if (!$this->getContributionPageID()) {
      CRM_Core_Session::setStatus('Site administrator attention required: No contribution page has been configured for the Partial Payments User Interface.', ts('Configuration incomplete'), 'error');
      $this->assign('config_incomplete', TRUE);
      return;
    }

    // Ensure this contribution page has valid configurations.
    $configValidator = new CRM_Paymentui_Configvalidator($this->getContributionPageID(), FALSE);
    if (!$configValidator->isValid()) {
      CRM_Core_Session::setStatus('Site administrator attention required: The selected contribution page has configuration problems. See Partial Payments UI settings.', ts('Configuration incompatible'), 'error');
      $this->assign('config_incomplete', TRUE);
      return;
    }
    parent::buildQuickForm();

    // Set a special css class on the form if civicrm 'debug' is enable.
    if (\Civi::settings()->get('debug_enabled')) {
      $class = $this->getAttribute('class') . ' paymentui-is-debug';
      $this->setAttribute('class', $class);
    }
    //Get contact name of the logged in user
    if (!$this->_contactID) {
      $message = ts('You are not authorized to view this page.');
      CRM_Utils_System::setUFMessage($message);
      return;
    }

    // Pass some useful values to javascript.
    $jsVars = [
      // "Other" field is the target for our on-page calculated total amount.
      'priceFieldOtherId' => $this->_getPriceFieldOtherID(),
    ];
    CRM_Core_Resources::singleton()->addVars(E::SHORT_NAME, $jsVars);

    //Get event names for which logged in user and the related contacts are registered
    if (!empty($this->_participantInfo)) {
      $this->assign('participantInfo', $this->_participantInfo);
      $this->assign('displayName', CRM_Contact_BAO_Contact::displayName($this->_contactID));

      //Set column headers for the table
      $columnHeaders = ['Event', 'Registrant', 'Cost', 'Paid to Date', 'Amount Unpaid', 'Make Payment'];
      $this->assign('columnHeaders', $columnHeaders);

      $totalAmount = 0;
      foreach ($this->_participantInfo as $pid => $pInfo) {
        $totalAmount += $pInfo['total_amount'];
        if ($pInfo['balance']) {
          $payment_html_attributes = [
            'class' => 'paymentui-payment-amount',
          ];
          $element = & $this->add('text', "payment[$pid]", NULL, $payment_html_attributes, FALSE);
        }
      }

      // Define form validation.
      $this->addFormRule(['CRM_Paymentui_Form_Paymentui', 'formRule'], $this);
    }

    // Include extra CSS styles.
    $style_path = CRM_Core_Resources::singleton()->getPath(E::LONG_NAME, 'css/extension.css');
    if ($style_path) {
      CRM_Core_Resources::singleton()->addStyleFile(E::LONG_NAME, 'css/extension.css');
    }

    // Include extra JavaScript.
    $style_path = CRM_Core_Resources::singleton()->getPath(E::LONG_NAME, 'js/paymentui_add_payment.js');
    if ($style_path) {
      CRM_Core_Resources::singleton()->addScriptFile(E::LONG_NAME, 'js/paymentui_add_payment.js');
    }
  }

  public function getContactID() {
    return CRM_Core_Session::singleton()->getLoggedInContactID();
  }

  /**
   * Get id of contribution page being acted on.
   *
   * @api This function will not change in a minor release and is supported for
   * use outside of core. This annotation / external support for properties
   * is only given where there is specific test cover.
   *
   * @return int
   */
  public function getContributionPageID(): int {
    if (!$this->_id) {
      $this->_id = \Civi::settings()->get('paymentui_contribution_page_id');
    }
    return $this->_id;
  }

  /**
   * Process confirm function and pass browser to the thank you page.
   */
  protected function skipToThankYouPage() {
    // Redirect to our own page (we don't use thank-you or confirmation.
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/paymentui/add/payment', "reset=1", TRUE, NULL, FALSE));
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return void
   */
  public function postProcess() {

    $this->_params = $this->controller->exportValues($this->_name);

    $totalAmount = $this->getMainContributionAmount();

    //Calculate total amount paid and individual amount for each contribution
    foreach ($this->_params['payment'] as $pid => $pVal) {
      $this->_participantInfo[$pid]['partial_payment_pay'] = $pVal;
    }

    // Building params for CC processing
    $paymentParams = $this->_params;
    $paymentParams["state_province-{$this->_bltID}"] = $this->_params["billing_state_province-{$this->_bltID}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$this->_bltID}"]);
    $paymentParams["country-{$this->_bltID}"] = $this->_params["billing_country-{$this->_bltID}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$this->_bltID}"]);
    $paymentParams['year'] = CRM_Core_Payment_Form::getCreditCardExpirationYear($this->_params);
    $paymentParams['month'] = CRM_Core_Payment_Form::getCreditCardExpirationMonth($this->_params);
    $paymentParams['ip_address'] = CRM_Utils_System::ipAddress();
    $paymentParams['amount'] = $totalAmount;
    $paymentParams['payment_action'] = 'Sale';
    $paymentParams['invoiceID'] = md5(uniqid(rand(), TRUE));
    $paymentParams['currency'] = $this->getCurrency();
    $paymentParams['contactID'] = $this->_contactID;

    $paymentProcessor = Civi\Payment\System::singleton()->getById($this->_paymentProcessorID);
    $doPaymentResult = $paymentProcessor->doPayment($paymentParams);
    $paymentParams['trxn_id'] = $doPaymentResult['trxn_id'];

    if (is_a($doPaymentResult, 'CRM_Core_Error')) {
      $statusMsg = ts('Payment of %1 failed. Error(s):<br />%2', [
        '1' => CRM_Utils_Money::format($totalAmount),
        '2' => CRM_Core_Error::getMessages($doPaymentResult),
      ]);
      CRM_Core_Session::setStatus($statusMsg, ts('Failed'), 'error');
    }
    else {
      $CCFinancialTrxn = CRM_Paymentui_BAO_Paymentui::createFinancialTrxn($paymentParams);

      //Process all the partial payments and update the records
      //Function defined in bot.partial.payment extension - payment.php
      $paymentResponses = CRM_Paymentui_Util::process_partial_payments($paymentParams, $this->_participantInfo, $doPaymentResult);
      foreach ($this->_participantInfo as $participantId => $participantInfo) {
        $paymentResponse = ($paymentResponses[$participantId] ?? NULL);
        if (($paymentResponse['success'] ?? NULL)) {
          // Send email receipt.
          $params = $paymentResponse + [
            'is_email_receipt' => '1',
            'receipt_text' => '',
            'MAX_FILE_SIZE' => '2097152',
            'confirm_email_text' => '',
          ];
          $sendReceipt = $this->emailReceipt($params);

          //Define status message
          $statusMsg = ts('Payment of %1 was processed successfully for %2 at <em>%3</em>.', [
            '1' => CRM_Utils_Money::format($paymentResponse['payment']['total_amount'], $paymentResponse['payment']['currency']),
            '2' => ($participantInfo['contact_name'] ?? NULL),
            '3' => ($paymentResponse['event_name'] ?? NULL),
          ]);
          CRM_Core_Session::setStatus($statusMsg, 'Success:', 'success');
        }
      }
    }

    $this->skipToThankYouPage();
    return;

    $totalAmount = 0;
    $config = CRM_Core_Config::singleton();

    //Calculate total amount paid and individual amount for each contribution
    foreach ($this->_params['payment'] as $pid => $pVal) {
      $totalAmount += $pVal;
      $this->_participantInfo[$pid]['partial_payment_pay'] = $pVal;
    }
    //Building params for CC processing
    $this->_params["state_province-{$this->_bltID}"] = $this->_params["billing_state_province-{$this->_bltID}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$this->_bltID}"]);
    $this->_params["country-{$this->_bltID}"] = $this->_params["billing_country-{$this->_bltID}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$this->_bltID}"]);
    $this->_params['year'] = CRM_Core_Payment_Form::getCreditCardExpirationYear($this->_params);
    $this->_params['month'] = CRM_Core_Payment_Form::getCreditCardExpirationMonth($this->_params);
    $this->_params['ip_address'] = CRM_Utils_System::ipAddress();
    $this->_params['amount'] = $totalAmount;
    $this->_params['amount_level'] = $params['amount_level'];
    $this->_params['currencyID'] = $config->defaultCurrency;
    $this->_params['payment_action'] = 'Sale';
    $this->_params['invoiceID'] = md5(uniqid(rand(), TRUE));

    $paymentParams = $this->_params;
    $payment = Civi\Payment\System::singleton()->getByProcessor($this->_paymentProcessor);
    $doPaymentResult = $payment->doPayment($paymentParams);
    if (is_a($doPaymentResult, 'CRM_Core_Error')) {
      $statusMsg = ts('Payment of %1 failed. Error(s):<br />%2', [
        '1' => CRM_Utils_Money::format($totalAmount),
        '2' => CRM_Core_Error::getMessages($doPaymentResult),
      ]);
      CRM_Core_Session::setStatus($statusMsg, ts('Failed'), 'error');
    }
    else {
      $CCFinancialTrxn = CRM_Paymentui_BAO_Paymentui::createFinancialTrxn($paymentParams);

      $partialPaymentInfo = $this->_participantInfo;
      //Process all the partial payments and update the records
      //Function defined in bot.partial.payment extension - payment.php
      $paymentResponses = CRM_Paymentui_Util::process_partial_payments($paymentParams, $this->_participantInfo);
      foreach ($this->_participantInfo as $participantId => $participantInfo) {
        $paymentResponse = ($paymentResponses[$participantId] ?? NULL);
        if (($paymentResponse['success'] ?? NULL)) {
          //Define status message
          $trxn = ($paymentResponse['trxn'] ?? NULL);
          $statusMsg = ts('Payment of %1 was processed successfully for <em>%2</em>.', [
            '1' => CRM_Utils_Money::format($paymentResponse['payment']['total_amount'], $paymentResponse['payment']['currency']),
            '2' => ($paymentResponse['event_name'] ?? NULL),
          ]);
          $params = $paymentResponse + [
            'is_email_receipt' => '1',
            'receipt_text' => '',
            'MAX_FILE_SIZE' => '2097152',
            'confirm_email_text' => '',
          ];
          $sendReceipt = $this->emailReceipt($params);
          CRM_Core_Session::setStatus($statusMsg, ts('Saved'), 'success');
        }
      }
      parent::postProcess();

      // Save billing details to new or existing billing address.
      $api_params = [
        'street_address' => $this->_params['billing_street_address-5'],
        'city' => $this->_params['billing_city-5'],
        'state_province_id' => $this->_params['billing_state_province_id-5'],
        'postal_code' => $this->_params['billing_postal_code-5'],
        'country_id' => $this->_params['billing_country_id-5'],
        'location_type_id' => "Billing",
        'contact_id' => $this->_contactID,
      ];
      $doPaymentResult = civicrm_api3('Address', 'get', [
        'location_type_id' => "Billing",
        'contact_id' => $this->_contactID,
      ]);
      if (!empty($doPaymentResult['values'])) {
        $api_params['id'] = min(array_keys($doPaymentResult['values']));
      }
      $doPaymentResult = civicrm_api3('Address', 'create', $api_params);
    }

    //Redirect to the same URL
    $url = CRM_Utils_System::url('civicrm/paymentui/add/payment', "reset=1");
    $session = CRM_Core_Session::singleton();
    CRM_Utils_System::redirect($url);
  }

  /**
   * Send an email receipt for the payment described in given params.
   *
   * @param array $params
   *
   * @return mixed
   */
  private function emailReceipt(&$params) {
    $eventId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', ($params['pid'] ?? NULL), 'event_id', 'id');
    $fromEmails = self::getEmails($eventId);

    $returnProperties = ['fee_label', 'start_date', 'end_date', 'is_show_location', 'title'];
    CRM_Core_DAO::commonRetrieveAll('CRM_Event_DAO_Event', 'id', $eventId, $events, $returnProperties);
    $event = $events[$eventId];

    // Template needs 'component' to include event-related information.
    $this->assign('component', 'event');

    $this->assign('event', $event);
    $isShowLocation = ($event['is_show_location'] ?? NULL);
    $this->assign('isShowLocation', $isShowLocation);
    if ($isShowLocation == 1) {
      $locationParams = [
        'entity_id' => $eventId,
        'entity_table' => 'civicrm_event',
      ];
      $location = CRM_Core_BAO_Location::getValues($locationParams, TRUE);
      $this->assign('location', $location);
    }

    // assign payment info here
    $this->assign('isRefund', FALSE);
    $payment = ($params['payment'] ?? NULL);
    $balance = ($params['balance'] ?? 0) - $payment['total_amount'];
    $this->assign('amountOwed', $balance);
    // Contribution total amount.
    $this->assign('totalAmount', ($params['total_amount'] ?? NULL));
    // Transaction payment amount.
    $this->assign('paymentAmount', $payment['total_amount']);
    $this->assign('paymentsComplete', ($balance == 0) ? 1 : 0);

    $this->assign('contactDisplayName', ($params['contact_name'] ?? NULL));

    // assign trxn details
    $this->assign('trxn_id', $payment['trxn_id']);
    $this->assign('receive_date', $payment['trxn_date']);
    if ($payment_instrument_id = $payment['payment_instrument_id']) {
      $paymentInstrument = CRM_Contribute_PseudoConstant::paymentInstrument();
      $this->assign('paidBy', ($paymentInstrument[$payment_instrument_id] ?? NULL));
    }
    $this->assign('checkNumber', $payment['check_number']);

    $contactId = ($params['cid'] ?? NULL);

    $sendTemplateParams = [
      'groupName' => 'msg_tpl_workflow_contribution',
      'valueName' => 'payment_or_refund_notification',
      'contactId' => $contactId,
      'PDFFilename' => ts('notification') . '.pdf',
      // 'modelProps' are important for sending relevant Entity IDs to the
      // 'payment_or_refund_notification' message template; without these (or
      // at least, without _some_ of them), that message template won't have
      // enough info to print all of its useful information.
      'modelProps' => array_filter([
        'contributionID' => $params['contribution_id'],
        'contactID' => $params['cid'],
        'financialTrxnID' => $payment['id'],
        'eventID' => $eventId,
        'participantID' => ($params['pid'] ?? NULL),
      ]),
    ];

    // try to send emails only if email id is present
    // and the do-not-email option is not checked for that contact
    $contact = civicrm_api3('contact', 'getSingle', ['id' => $contactId]);
    if (
      $contactEmail = ($contact['email'] ?? NULL) && !($contact['do_not_email'] ?? NULL)
    ) {
      $sendTemplateParams['from'] = ($fromEmails['from'] ?? NULL);
      $sendTemplateParams['toName'] = ($contact['display_name'] ?? NULL);
      $sendTemplateParams['toEmail'] = ($contact['email'] ?? NULL);
      $sendTemplateParams['cc'] = ($fromEmails['cc'] ?? NULL);
      $sendTemplateParams['bcc'] = ($fromEmails['bcc'] ?? NULL);
    }
    list($mailSent, $subject, $message, $html) = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
    return $mailSent;
  }

  /**
   * Build list of email from/cc/bcc using the domain email id and the emails
   * configured for the event
   *
   * @param int $eventId
   *   The id of the event.
   *
   * @return array
   *   an array of email ids
   */
  public static function getEmails($eventId = NULL) {
    $emails = [];

    // add all configured FROM email addresses
    $domainFrom = CRM_Core_OptionGroup::values('from_email_address');
    foreach (array_keys($domainFrom) as $k) {
      $domainEmail = $domainFrom[$k];
      $emails['from'] = $domainEmail;
    }

    if ($eventId) {
      // add the emails configured for the event
      $params = ['id' => $eventId];
      $returnProperties = ['is_email_confirm', 'confirm_from_name', 'confirm_from_email', 'cc_confirm', 'bcc_confirm'];
      $eventEmail = [];

      CRM_Core_DAO::commonRetrieve('CRM_Event_DAO_Event', $params, $eventEmail, $returnProperties);
      if ($eventEmail['is_email_confirm']) {
        if (
          !empty($eventEmail['confirm_from_name']) &&
          !empty($eventEmail['confirm_from_email'])
        ) {
          $eventEmailId = "{$eventEmail['confirm_from_name']} <{$eventEmail['confirm_from_email']}>";
          $emails['from'] = $eventEmailId;
        }
        $emails['cc'] = ($eventEmail['cc_confirm'] ?? NULL);
        $emails['bcc'] = ($eventEmail['bcc_confirm'] ?? NULL);
      }
    }
    return $emails;
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return [string]
   */
  private function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * Get the ID of the other amount field if the form is configured to offer it.
   *
   * The other amount field is an alternative to the configured radio options,
   * specific to this form.
   *
   * Copied from private method CRM_Contribute_Form_Contribution_Main::getPriceFieldOtherID(),
   * in civicrm 5.81.0
   *
   * @return int|null
   */
  private function _getPriceFieldOtherID(): ?int {
    if (!$this->isQuickConfig()) {
      return NULL;
    }
    foreach ($this->order->getPriceFieldsMetadata() as $field) {
      if ($field['name'] === 'other_amount') {
        return (int) $field['id'];
      }
    }
    return NULL;
  }

  /**
   * global form rule
   *
   * @param array $fields the input form values
   * @param array $files the uploaded files if any
   * @param $self
   *
   * @internal param array $options additional user data
   *
   * @return true if no errors, else array of errors
   * @access public
   * @static
   */
  public static function formRule($fields, $files, $self) {
    $errors = [];

    //Validate the amount: should not be more than balance and should be numeric
    $total = 0;
    foreach ($fields['payment'] as $pid => $amount) {
      if ($amount) {
        if ($self->_participantInfo[$pid]['balance'] < $amount) {
          $errors['payment[' . $pid . ']'] = ts('Amount can not exceed the balance amount.');
        }
        if (!is_numeric($amount)) {
          $errors['payment[' . $pid . ']'] = ts('Please enter a valid amount.');
        }
        else {
          $total += $amount;
        }
      }
    }
    if (!$total) {
      $errors["payment[{$pid}]"] = ts('Please enter an amount for at least one event.');
    }

    return $errors;
  }

}
