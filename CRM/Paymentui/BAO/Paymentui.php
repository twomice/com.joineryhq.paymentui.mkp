<?php

class CRM_Paymentui_BAO_Paymentui extends CRM_Event_DAO_Participant {

  static function getParticipantInfo($contactID) {
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
      ),
    );
    $result = civicrm_api3('setting', 'get', $api_params);
    $paymentui_exclude_participant_status = CRM_Utils_Array::value('paymentui_exclude_participant_status', $result['values'][CRM_Core_Config::domainID()], array(0));


    $query_params = array();
    $cid_placeholders = array();
    $i = 1;
    foreach ($relatedContactIDs as $param) {
      $cid_placeholders[] = '%'. $i;
      $query_params[$i] = array($param, 'Int');
      $i++;
    }
    $status_placeholders = array();
    foreach ($paymentui_exclude_participant_status as $param) {
      $status_placeholders[] = '%'. $i;
      $query_params[$i] = array($param, 'Int');
      $i++;
    }

    //Get participant info for the primary and related contacts
    $sql = "SELECT p.id, p.contact_id, p.status_id, e.title, c.display_name, pp.contribution_id FROM civicrm_participant p
			INNER JOIN civicrm_contact c ON ( p.contact_id =  c.id )
			INNER JOIN civicrm_event e ON ( p.event_id = e.id )
			INNER JOIN civicrm_participant_payment pp ON ( p.id = pp.participant_id )
			WHERE
			p.contact_id IN (". implode(',', $cid_placeholders) .")
			AND (p.status_id NOT IN(". implode(',', $status_placeholders) ."))
      AND p.is_test = 0
      AND ifnull(end_date, start_date) > NOW()
    ";
    $dao = CRM_Core_DAO::executeQuery($sql, $query_params);

    if ($dao->N) {
      while ($dao->fetch()) {
        //Get the payment details of all the participants
        $paymentDetails = CRM_Contribute_BAO_Contribution::getPaymentInfo($dao->id, 'event', FALSE, TRUE);
        //Get display names of the participants and additional participants, if any
        $displayNames = self::getDisplayNames($dao->id, $dao->display_name);

        // For reasons I don't understand, CRM_Contribute_BAO_Contribution::getPaymentInfo()
        // swaps $paid and $balance if either is 0. Swap them back now.
        if ($paymentDetails['balance'] == 0 || $paymentDetails['paid'] == 0) {
          $balance = $paymentDetails['paid'];
          $paid = $paymentDetails['balance'];
        }
        else {
          $balance = $paymentDetails['balance'];
          $paid = $paymentDetails['paid'];
        }

        //Create an array with all the participant and payment information
        $participantInfo[$dao->id]['pid'] = $dao->id;
        $participantInfo[$dao->id]['cid'] = $dao->contact_id;
        $participantInfo[$dao->id]['contribution_id'] = $dao->contribution_id;
        $participantInfo[$dao->id]['event_name'] = $dao->title;
        $participantInfo[$dao->id]['contact_name'] = $displayNames;
        $participantInfo[$dao->id]['total_amount'] = $paymentDetails['total'];
        $participantInfo[$dao->id]['paid'] = $paid;
        $participantInfo[$dao->id]['balance'] = $balance;
        $participantInfo[$dao->id]['rowClass'] = 'row_' . $dao->id;
        $participantInfo[$dao->id]['payLater'] = $paymentDetails['payLater'];
        $participantInfo[$dao->id]['status'] = $participant_statuses[$dao->status_id]['label'];
        $participantInfo[$dao->id]['is_counted'] = $participant_statuses[$dao->status_id]['is_counted'];
        $participantInfo[$dao->id]['status_id'] = $dao->status_id;
      }
    } else {
      return FALSE;
    }
    
    return $participantInfo;
  }

  /**
   * * Helper function to get formatted display names of the the participants
   * * Purpose - to generate comma separated display names of primary and additional participants
   * */
  static function getDisplayNames($participantId, $display_name) {
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
   * * Helper function to get related contacts of tthe contact
   * * Checks for Child, Spouse, Child/Ward relationship types
   * */
  static function getRelatedContacts($contactID) {
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
    } else {
      return FALSE;
    }
  }

  /**
   * * Creates a financial trxn record for the CC transaction of the total amount
   * */
  function createFinancialTrxn($payment) {
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
    $entityFinancialTrxnParams =
            array(
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

}
