<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 */
class CRM_Paymentui_Form_Paymentui extends CRM_Contribute_Form_ContributionBase {

  static $extensionName = 'com.joineryhq.paymentui.mkp';

  /**
   * Function to set variables up before form is built
   *
   * @return void
   * @access public
   */
  public function preProcess() {
    // check if the user is registered and we have a contact ID
    $this->_contactID = $this->getContactID();

    // Determine the correct payment processor.
    $this->_paymentProcessor = array('billing_mode' => 1);
    $locationTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id', array(), 'validate');
    $this->_bltID = array_search('Billing', $locationTypes);
    $this->set('bltID', $this->_bltID);
    $this->assign('bltID', $this->_bltID);
    $this->_fields = array();

    $result = civicrm_api3('PaymentProcessor', 'get', array(
      'is_default' => 1,
      'is_test' => 0,
    ));
    $this->payment_processor_id = $result['id'];

    if (!$this->payment_processor_id) {
      CRM_Core_Error::fatal(ts('No default payment processor is available. Cannot continue.'));
    }

    //Set Payment processor to default
    $this->_paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($this->payment_processor_id, 'live');

    $payment_processors = CRM_Financial_BAO_PaymentProcessor::getPaymentProcessors();
    $processor = $payment_processors[$this->payment_processor_id];

    CRM_Core_Payment_Form::buildPaymentForm($this, $processor, FALSE, FALSE);
    $this->assign_by_ref('paymentProcessor', $paymentProcessor);
    $this->assign('hidePayPalExpress', FALSE);
  }

  /**
   * Set the default values.
   *
   * @return Array
   */
  /**
   */
  public function setDefaultValues() {

    if (!empty($this->_contactID)) {
      $this->_defaults = $this->getProfileDefaults('Billing', $this->_contactID);
    }

    // set default country from config if no country set
    if (empty($this->_defaults["billing_country_id-{$this->_bltID}"])) {
      $this->_defaults["billing_country_id-{$this->_bltID}"] = $config->defaultContactCountry;
    }

    // set default state/province from config if no state/province set
    if (empty($this->_defaults["billing_state_province_id-{$this->_bltID}"])) {
      $this->_defaults["billing_state_province_id-{$this->_bltID}"] = $config->defaultContactStateProvince;
    }

    return $this->_defaults;
  }


  /**
   * Function to build the form
   *
   * @return void
   * @access public
   */
  public function buildQuickForm() {
    //Get contact name of the logged in user
    $session = CRM_Core_Session::singleton();

    if (!$this->_contactID) {
      $message = ts('You are not authorized to view this page.');
      CRM_Utils_System::setUFMessage($message);
      return;
    }
    $this->assign('contactId', $this->_contactID);

    //Get event names for which logged in user and the related contacts are registered
    $this->_participantInfo = CRM_Paymentui_BAO_Paymentui::getParticipantInfo($this->_contactID);
    if (!empty($this->_participantInfo)) {
      $this->assign('displayName', CRM_Contact_BAO_Contact::displayName($this->_contactID));

      //Set column headers for the table
      $columnHeaders = array('Event', 'Registrant', 'Cost', 'Paid to Date', 'Amount Unpaid', 'Make Payment');
      $this->assign('columnHeaders', $columnHeaders);

      $this->assign('participantInfo', $this->_participantInfo);
      foreach ($this->_participantInfo as $pid => $pInfo) {
        if ($pInfo['balance']) {
          $payment_html_attributes = array(
            'class' => 'paymentui-payment-amount',
            'onkeyup' => 'calculateTotal();',
          );
          $element = & $this->add('text', "payment[$pid]", NULL, $payment_html_attributes, FALSE);
        }
      }
      $this->assignToTemplate();

      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => ts('Submit'),
          'isDefault' => TRUE,
        ),
      ));
      $this->addElement('hidden', 'isPaymentuiForm', 1);

      // export form elements
      $this->assign('elementNames', $this->getRenderableElementNames());
      parent::buildQuickForm();
      $this->addFormRule(array('CRM_Paymentui_Form_Paymentui', 'formRule'), $this);
    }

    // Include extra CSS styles.
    $style_path = CRM_Core_Resources::singleton()->getPath(self::$extensionName, 'css/extension.css');
    if ($style_path) {
      CRM_Core_Resources::singleton()->addStyleFile(self::$extensionName, 'css/extension.css');
    }

    // Include extra JavaScript.
    $style_path = CRM_Core_Resources::singleton()->getPath(self::$extensionName, 'js/paymentui_add_payment.js');
    if ($style_path) {
      CRM_Core_Resources::singleton()->addScriptFile(self::$extensionName, 'js/paymentui_add_payment.js');
    }
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
    $errors = array();
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
    //Validate credit card fields
    $required = array(
      'credit_card_type' => 'Credit Card Type',
      'credit_card_number' => 'Credit Card Number',
      'cvv2' => 'CVV',
      'billing_first_name' => 'Billing First Name',
      'billing_last_name' => 'Billing Last Name',
      'billing_street_address-5' => 'Billing Street Address',
      'billing_city-5' => 'City',
      'billing_state_province_id-5' => 'State Province',
      'billing_postal_code-5' => 'Postal Code',
      'billing_country_id-5' => 'Country',
    );

    foreach ($required as $name => $fld) {
      if (!$fields[$name]) {
        $errors[$name] = ts('%1 is a required field.', array(1 => $fld));
      }
    }
    CRM_Core_Payment_Form::validateCreditCard($fields, $errors);
    return $errors;
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
    $this->_params['currencyID'] = $config->defaultCurrency;
    $this->_params['payment_action'] = 'Sale';
    $this->_params['invoiceID'] = md5(uniqid(rand(), TRUE));

    $paymentParams = $this->_params;
    CRM_Core_Payment_Form::mapParams($this->_bltID, $this->_params, $paymentParams, TRUE);
    $result = $this->_paymentProcessor['object']->doDirectPayment($paymentParams);
    if (is_a($result, 'CRM_Core_Error')) {
      $statusMsg = ts('Payment of %1 failed. Error(s):<br />%2', array(
        '1' => CRM_Utils_Money::format($totalAmount),
        '2' => CRM_Core_Error::getMessages($result),
      ));
      CRM_Core_Session::setStatus($statusMsg, ts('Failed'), 'error');
    }
    else {
      $CCFinancialTrxn = CRM_Paymentui_BAO_Paymentui::createFinancialTrxn($paymentParams);

      $partialPaymentInfo = $this->_participantInfo;
      //Process all the partial payments and update the records
      $paymentResponses = process_partial_payments($paymentParams, $this->_participantInfo);
      foreach (array_keys($this->_participantInfo) as $participantId) {
        $paymentResponse = CRM_Utils_Array::value($participantId, $paymentResponses);
        if (CRM_Utils_Array::value('success', $paymentResponse)) {
          //Define status message
          $trxn = CRM_Utils_Array::value('trxn', $paymentResponse);
          $statusMsg = ts('Payment of %1 was processed successfully for <em>%2</em>.', array(
            '1' => CRM_Utils_Money::format($trxn->total_amount, $trxn->currency),
            '2' => CRM_Utils_Array::value('event_name', $paymentResponse),
          ));
          $params = $paymentResponse + array(
            'is_email_receipt' => '1',
            'receipt_text' => '',
            'MAX_FILE_SIZE' => '2097152',
            'confirm_email_text' => '',
          );
          $sendReceipt = $this->emailReceipt($params);
          CRM_Core_Session::setStatus($statusMsg, ts('Saved'), 'success');
        }
      }
      parent::postProcess();

      // Save billing details to new or existing billing address.
      $api_params = array(
        'street_address' => $this->_params['billing_street_address-5'],
        'city' => $this->_params['billing_city-5'],
        'state_province_id' => $this->_params['billing_state_province_id-5'],
        'postal_code' => $this->_params['billing_postal_code-5'],
        'country_id' => $this->_params['billing_country_id-5'],
        'location_type_id' => "Billing",
        'contact_id' => $this->_contactID,
      );
      $result = civicrm_api3('Address', 'get', array(
        'location_type_id' => "Billing",
        'contact_id' => $this->_contactID,
      ));
      if (!empty($result['values'])) {
        $api_params['id'] = min(array_keys($result['values']));
      }
      $result = civicrm_api3('Address', 'create', $api_params);
    }

    //Redirect to the same URL
    $url = CRM_Utils_System::url('civicrm/paymentui/add/payment', "reset=1");
    $session = CRM_Core_Session::singleton();
    CRM_Utils_System::redirect($url);
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  private function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * Send an email receipt for the payment described in given params.
   *
   * @param array $params
   *
   * @return mixed
   */
  private function emailReceipt(&$params) {
    $eventId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', CRM_Utils_Array::value('pid', $params), 'event_id', 'id');
    $fromEmails = self::getEmails($eventId);

    $returnProperties = array('fee_label', 'start_date', 'end_date', 'is_show_location', 'title');
    CRM_Core_DAO::commonRetrieveAll('CRM_Event_DAO_Event', 'id', $eventId, $events, $returnProperties);
    $event = $events[$eventId];

    // Template needs 'component' to include event-related information.
    $this->assign('component', 'event');

    $this->assign('event', $event);
    $isShowLocation = CRM_Utils_Array::value('is_show_location', $event);
    $this->assign('isShowLocation', $isShowLocation);
    if ($isShowLocation == 1) {
      $locationParams = array(
        'entity_id' => $eventId,
        'entity_table' => 'civicrm_event',
      );
      $location = CRM_Core_BAO_Location::getValues($locationParams, TRUE);
      $this->assign('location', $location);
    }

    // assign payment info here
    $this->assign('isRefund', FALSE);
    $trxn = CRM_Utils_Array::value('trxn', $params);
    $balance = CRM_Utils_Array::value('balance', $params, 0) - $trxn->total_amount;
    $this->assign('amountOwed', $balance);
    // Contribution total amount.
    $this->assign('totalAmount', CRM_Utils_Array::value('total_amount', $params));
    // Transaction payment amount.
    $this->assign('paymentAmount', $trxn->total_amount);
    $this->assign('paymentsComplete', ($balance == 0) ? 1 : 0);

    $this->assign('contactDisplayName', CRM_Utils_Array::value('contact_name', $params));

    // assign trxn details
    $this->assign('trxn_id', $trxn->trxn_id);
    $this->assign('receive_date', $trxn->trxn_date);
    if ($payment_instrument_id = $trxn->payment_instrument_id) {
      $paymentInstrument = CRM_Contribute_PseudoConstant::paymentInstrument();
      $this->assign('paidBy', CRM_Utils_Array::value($payment_instrument_id, $paymentInstrument));
    }
    $this->assign('checkNumber', $trxn->check_number);

    $contactId = CRM_Utils_Array::value('cid', $params);

    $sendTemplateParams = array(
      'groupName' => 'msg_tpl_workflow_contribution',
      'valueName' => 'payment_or_refund_notification',
      'contactId' => $contactId,
      'PDFFilename' => ts('notification') . '.pdf',
    );

    // try to send emails only if email id is present
    // and the do-not-email option is not checked for that contact
    $contact = civicrm_api3('contact', 'getSingle', array('id' => $contactId));
    if (
      $contactEmail = CRM_Utils_Array::value('email', $contact)
      && !CRM_Utils_Array::value('do_not_email', $contact)
    ) {
      $sendTemplateParams['from'] = CRM_Utils_Array::value('from', $fromEmails);
      $sendTemplateParams['toName'] = CRM_Utils_Array::value('display_name', $contact);
      $sendTemplateParams['toEmail'] = CRM_Utils_Array::value('email', $contact);
      $sendTemplateParams['cc'] = CRM_Utils_Array::value('cc', $fromEmails);
      $sendTemplateParams['bcc'] = CRM_Utils_Array::value('bcc', $fromEmails);
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
    $emails = array();

    // add all configured FROM email addresses
    $domainFrom = CRM_Core_OptionGroup::values('from_email_address');
    foreach (array_keys($domainFrom) as $k) {
      $domainEmail = $domainFrom[$k];
      $emails['from'] = $domainEmail;
    }

    if ($eventId) {
      // add the emails configured for the event
      $params = array('id' => $eventId);
      $returnProperties = array('is_email_confirm', 'confirm_from_name', 'confirm_from_email', 'cc_confirm', 'bcc_confirm');
      $eventEmail = array();

      CRM_Core_DAO::commonRetrieve('CRM_Event_DAO_Event', $params, $eventEmail, $returnProperties);
      if ($eventEmail['is_email_confirm']) {
        if (
          !empty($eventEmail['confirm_from_name']) &&
          !empty($eventEmail['confirm_from_email'])
        ) {
          $eventEmailId = "{$eventEmail['confirm_from_name']} <{$eventEmail['confirm_from_email']}>";
          $emails['from'] = $eventEmailId;
        }
        $emails['cc'] = CRM_Utils_Array::value('cc_confirm', $eventEmail);
        $emails['bcc'] = CRM_Utils_Array::value('bcc_confirm', $eventEmail);
      }
    }
    return $emails;
  }

}
