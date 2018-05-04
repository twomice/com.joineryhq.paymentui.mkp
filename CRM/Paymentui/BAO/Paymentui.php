<?php

class CRM_Paymentui_BAO_Paymentui extends CRM_Event_DAO_Participant {

  public static function getParticipantInfo($contactID) {
    // Get participant statuses.
    $result = civicrm_api3('ParticipantStatusType', 'get', array(
      'options' => array('limit' => 0),
    ));
    $participant_statuses = $result['values'];

    // Get related contacts for which this contact may have paid.
    $relatedContactIDs = self::getRelatedContacts($contactID);
    $relatedContactIDs[] = $contactID;

    // Find out which statuses to exclude.
    $api_params = array(
      'return' => array(
        'paymentui_exclude_participant_status',
        'paymentui_exclude_participant_role',
      ),
    );
    $result = civicrm_api3('setting', 'get', $api_params);
    $paymentui_exclude_participant_status = CRM_Utils_Array::value('paymentui_exclude_participant_status', $result['values'][CRM_Core_Config::domainID()], array());
    $paymentui_exclude_participant_status[] = 0;
    $paymentui_exclude_participant_role = CRM_Utils_Array::value('paymentui_exclude_participant_role', $result['values'][CRM_Core_Config::domainID()], array());

    $query_params = array();
    $cid_placeholders = array();
    $i = 1;
    foreach ($relatedContactIDs as $param) {
      $cid_placeholders[] = '%' . $i;
      $query_params[$i] = array($param, 'Int');
      $i++;
    }
    $status_placeholders = array();
    foreach ($paymentui_exclude_participant_status as $param) {
      $status_placeholders[] = '%' . $i;
      $query_params[$i] = array($param, 'Int');
      $i++;
    }

    //Get participant info for the primary and related contacts
    $sql = "
      SELECT p.id, p.contact_id, p.status_id, e.title, c.display_name, pp.contribution_id, e.id as event_id
      FROM
        civicrm_participant p
        INNER JOIN civicrm_contact c ON p.contact_id = c.id
        INNER JOIN civicrm_event e ON p.event_id = e.id
        LEFT JOIN civicrm_participant_payment pp ON p.id = pp.participant_id
			WHERE
        p.contact_id IN (" . implode(',', $cid_placeholders) . ")
        AND (p.status_id NOT IN (" . implode(',', $status_placeholders) . "))
        AND p.is_test = 0
        AND ifnull(end_date, start_date) > NOW()
    ";

    foreach ($paymentui_exclude_participant_role as $param) {
      // Not exactly equal to $i;
      $sql .= "AND p.role_id != %{$i} ";
      $query_params[$i] = array($param, 'Int');
      $i++;
      // Not in the middle of a multi-value string.
      $sql .= "AND p.role_id NOT LIKE '%" . CRM_core_dao::VALUE_SEPARATOR . (int) $param . CRM_core_dao::VALUE_SEPARATOR . "%'";
      // Not at the start of a multi-value string.
      $sql .= "AND p.role_id NOT LIKE '%" . CRM_core_dao::VALUE_SEPARATOR . (int) $param . "'";
      // Not at the end of a multi-value string.
      $sql .= "AND p.role_id NOT LIKE '" . (int) $param . CRM_core_dao::VALUE_SEPARATOR . "%'\n";
    }
    $dao = CRM_Core_DAO::executeQuery($sql, $query_params);

    $participantInfo = array();
    if ($dao->N) {
      while ($dao->fetch()) {
        if (!self::eventIsPaymentui($dao->event_id)) {
          // This event is not configured to be displayed on the paymentui page,
          // so skip this record.
          continue;
        }
        //Get the payment details of all the participants
        $paymentDetails = CRM_Contribute_BAO_Contribution::getPaymentInfo($dao->id, 'event', FALSE, TRUE);
        //Get display names of the participants and additional participants, if any
        $displayNames = self::getDisplayNames($dao->id, $dao->display_name);

        //Create an array with all the participant and payment information
        $participantInfo[$dao->id]['pid'] = $dao->id;
        $participantInfo[$dao->id]['cid'] = $dao->contact_id;
        $participantInfo[$dao->id]['contribution_id'] = $dao->contribution_id;
        $participantInfo[$dao->id]['event_name'] = $dao->title;
        $participantInfo[$dao->id]['contact_name'] = $displayNames;
        $participantInfo[$dao->id]['total_amount'] = $paymentDetails['total'];
        $participantInfo[$dao->id]['paid'] = $paymentDetails['paid'];
        $participantInfo[$dao->id]['balance'] = $paymentDetails['balance'];
        $participantInfo[$dao->id]['rowClass'] = 'row_' . $dao->id;
        $participantInfo[$dao->id]['payLater'] = $paymentDetails['payLater'];
        $participantInfo[$dao->id]['status'] = $participant_statuses[$dao->status_id]['label'];
        $participantInfo[$dao->id]['is_counted'] = $participant_statuses[$dao->status_id]['is_counted'];
        $participantInfo[$dao->id]['status_id'] = $dao->status_id;
      }
    }
    return $participantInfo;
  }

  /**
   * Check whether the given is configured for display on the paymentui page.
   * @param Int $eventId
   *
   * @return bool TRUE if configured for display on the paymentui page; otherwise false.
   */
  public static function eventIsPaymentui($eventId) {
    $eventSettings = CRM_Paymentui_Settings::getEventSettings($eventId);
    return CRM_Utils_Array::value('is_paymentui', $eventSettings, 0);
  }

  /**
   * Helper function to get formatted display names of the the participants
   * Purpose - to generate comma separated display names of primary and additional participants
   */
  public static function getDisplayNames($participantId, $display_name) {
    $displayName[] = $display_name;
    //Get additional participant names
    $additionalPIds = CRM_Event_BAO_Participant::getAdditionalParticipantIds($participantId);
    if ($additionalPIds) {
      foreach ($additionalPIds as $pid) {
        $cId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $pid, 'contact_id', 'id');
        $displayName[] = CRM_Contact_BAO_Contact::displayName($cId);
      }
    }
    $displayNames = implode(', ', $displayName);
    return $displayNames;
  }

  /**
   * Helper function to get related contacts of tthe contact
   * Checks for Child, Spouse, Child/Ward relationship types
   */
  public static function getRelatedContacts($contactID) {
    //Get relationship type id of Spouse, Child, Child/Ward of
    $relTypeIDs = array();
    $relTypeIDs['parent'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_RelationshipType', 'Parent of', 'id', 'label_a_b');
    $relTypeIDs['guardian'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_RelationshipType', 'Parent/Guardian of', 'id', 'label_a_b');
    $relTypeIDs['spouse'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_RelationshipType', 'Spouse of', 'id', 'label_a_b');
    $relatedContacts = CRM_Contact_BAO_Relationship::getRelationship($contactID);
    $isRelatedContact = FALSE;

    foreach ($relatedContacts as $relData) {
      if (array_search($relData['civicrm_relationship_type_id'], $relTypeIDs)) {
        $relatedContactIDs[] = $relData['cid'];
        $isRelatedContact = TRUE;
      }
    }

    if ($isRelatedContact) {
      return $relatedContactIDs;
    }
    else {
      return FALSE;
    }
  }

  /**
   * * Creates a financial trxn record for the CC transaction of the total amount
   * */
  public function createFinancialTrxn($payment) {
    //Set Payment processor to Auth CC
    //To be changed for switching to live processor
    $payment_processor_id = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessor', 'Credit Card', 'id', 'name');
    $fromAccountID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialAccount', 'Accounts Receivable', 'id', 'name');
    $CCAccountID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialAccount', 'Payment Processor Account', 'id', 'name');
    $paymentMethods = CRM_Contribute_PseudoConstant::paymentInstrument();
    $CC_id = array_search('Credit Card', $paymentMethods);
    $params = array(
      'to_financial_account_id' => $CCAccountID,
      'from_financial_account_id' => $fromAccountID,
      'trxn_date' => date('Ymd'),
      'total_amount' => $payment['amount'],
      'fee_amount' => '',
      'net_amount' => '',
      'currency' => $payment['currencyID'],
      'status_id' => 1,
      'trxn_id' => $payment['trxn_id'],
      'payment_processor' => $payment_processor_id,
      'payment_instrument_id' => $CC_id,
    );
    require_once 'CRM/Core/BAO/FinancialTrxn.php';

    $trxn = new CRM_Financial_DAO_FinancialTrxn();
    $trxn->copyValues($params);
    $fids = array();
    if (!CRM_Utils_Rule::currencyCode($trxn->currency)) {
      $config = CRM_Core_Config::singleton();
      $trxn->currency = $config->defaultCurrency;
    }

    $trxn->save();
    $entityFinancialTrxnParams = array(
      'entity_table' => "civicrm_financial_trxn",
      'entity_id' => $trxn->id,
      'financial_trxn_id' => $trxn->id,
      'amount' => $params['total_amount'],
      'currency' => $trxn->currency,
    );
    $entityTrxn = new CRM_Financial_DAO_EntityFinancialTrxn();
    $entityTrxn->copyValues($entityFinancialTrxnParams);
    $entityTrxn->save();
  }

  /**
   * For a given participant, assuming there is exactly one price field option
   * with a non-zero value, and that field is a text/numeric field with a value
   * of 1, update the amount of that field to the given amount.
   *
   * @param Int $participantId
   *  CiviCRM Participant ID (e.g., civicrm_participant.id)
   * @param Float $amount
   *  Decimal number indicating the total amount in dollars.
   * @return boolean
   *  TRUE on success, otherwise FALSE.
   * @throws CRM_Exception
   */
  public static function updateParticipantSingleLineItemTotal($participantId, $amount) {
    $result = civicrm_api3('participant', 'getSingle', array(
      'sequential' => 1,
      'id' => $participantId,
    ));
    $eventId = CRM_Utils_Array::value('event_id', $result);

    $params = array(
      '1' => array($eventId, 'Int'),
    );
    $priceSetId = CRM_Core_DAO::singleValueQuery("
      SELECT price_set_id
      FROM civicrm_price_set_entity
      WHERE
        entity_table = 'civicrm_event'
        AND entity_id = %1
    ", $params);
    if (!$priceSetId) {
      throw new CRM_Exception('Could not find a price set for event ' . $eventId);
    }

    $result = civicrm_api3('PriceField', 'get', array(
      'sequential' => 1,
      'price_set_id' => $priceSetId,
      'api.PriceFieldValue.get' => array(),
    ));
    $nonZeroPriceOptionCount = 0;
    foreach ($result['values'] as $value) {
      foreach ($value['api.PriceFieldValue.get']['values'] as $priceFieldValue) {
        if ($priceFieldValue['amount'] != 0) {
          $nonZeroPriceOptionCount++;
        }
        if ($nonZeroPriceOptionCount > 1) {
          throw new CRM_Exception("Price Set for event $eventId has too many non-zero-valued price options; cannot decide which one to use.");
        }
      }
      if (
        $value['html_type'] == 'Text' && $value['is_enter_qty'] == '1' && $value['api.PriceFieldValue.get']['count'] == 1 && $value['api.PriceFieldValue.get']['values'][0]['amount'] == 1
      ) {
        $priceFieldIdToUpdate = $value['id'];
      }
    }
    if (!$priceFieldIdToUpdate) {
      throw new CRM_Exception("Price Set for event $eventId should have exactly one text/numeric price field worth 1.00, but none was found; nothing to update.");
    }

    // Build variables needed for changing the line item amount.
    $params = CRM_Event_Form_EventFees::setDefaultPriceSet($participantId, $eventId, FALSE);
    $params['price_' . $priceFieldIdToUpdate] = $amount;

    $contributionId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $participantId, 'contribution_id', 'participant_id');

    $priceSets = CRM_Price_BAO_PriceSet::getSetDetail($priceSetId, FALSE, FALSE);
    $priceSet = CRM_Utils_Array::value($priceSetId, $priceSets);
    $feeBlock = CRM_Utils_Array::value('fields', $priceSet);

    $lineItems = CRM_Price_BAO_LineItem::getLineItems($participantId, 'participant');

    $paymentInfo = CRM_Contribute_BAO_Contribution::getPaymentInfo($participantId, 'event');
    $paidAmount = CRM_Utils_Array::value('paid', $paymentInfo);

    // Change the line item.
    CRM_Event_BAO_Participant::changeFeeSelections($params, $participantId, $contributionId, $feeBlock, $lineItems, $paidAmount, $priceSetId);

    // If we're here, we have to assume it's successful, especially because
    // CRM_Event_BAO_Participant::changeFeeSelections() returns nothing.
    return TRUE;
  }

}
