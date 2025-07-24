<?php

/**
 * Utility methods for paymentui extension
 */
class CRM_Paymentui_Util {
  /**
   * Function to process partial payments
   *
   * This function copied from https://github.com/backoffice/BOT-Partial-Payment-Extension/commit/e23537be742a35947f8fbaa9ee351a107362942b,
   * later modified for the current extension.
   * License: GNU Affero Public License 3.0 (https://github.com/backoffice/BOT-Partial-Payment-Extension/blob/e23537be742a35947f8fbaa9ee351a107362942b/bot.partial.payment/LICENSE.txt)
   *
   * @param $paymentParams - Payment Processor parameters
   * @param $participantInfo - participantID as key and contributionID, ContactID, PayLater, Partial Payment Amount
   * @return $participantInfo array with 'Success' flag
   *
   */
  public static function process_partial_payments($paymentParams, $participantInfo) {
    foreach ($participantInfo as $pId => $pInfo) {
      if (!$pInfo['contribution_id'] || !$pId) {
        $participantInfo[$pId]['success'] = 0;
        continue;
      }
      if ($pInfo['partial_payment_pay']) {
        if ($pInfo['payLater']) {
          $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');
          //Update contribution status from pending to partially paid
          $updateContribution = new CRM_Contribute_DAO_Contribution();
          $contributionParams = array(
            'id' => $pInfo['contribution_id'],
            'contact_id' => $pInfo['cid'],
            'contribution_status_id' => array_search('Partially paid', $contributionStatuses),
          );
          $updateContribution->copyValues($contributionParams);
          $updateContribution->save();
          //Update participant Status from 'Pending from Pay Later' to 'Partially Paid'
          $pendingPayLater = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', 'Pending from pay later', 'id', 'name');
          $partiallyPaid = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', 'Partially paid', 'id', 'name');
          $participantStatus = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $pId, 'status_id', 'id');

          if ($participantStatus == $pendingPayLater) {
            CRM_Event_BAO_Participant::updateParticipantStatus($pId, $pendingPayLater, $partiallyPaid, TRUE);
          }
        }

        //Add additional financial transactions for partial payments
        $paymentParams['total_amount'] = $pInfo['partial_payment_pay'];

        //recordAdditionalPayment method no longer supported as of CiviCRM 5.18.x
        //$trxnRecord = CRM_Contribute_BAO_Contribution::recordAdditionalPayment( $pInfo['contribution_id'], $paymentParams, 'owed', $pId );
        $paymentParams['participant_id'] = $pId;
        $paymentParams['contribution_id'] = $pInfo['contribution_id'];

        try {
          $trxnRecord = civicrm_api3('Payment', 'create', $paymentParams);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_var("Trxn Record", $trxnRecord);
          CRM_Core_Error::debug_var("API Exception error", $error);
        }
        $participantInfo[$pId]['success'] = 1;
        $participantInfo[$pId]['payment'] = $trxnRecord['values'][$trxnRecord['id']];
      }
    }
    return $participantInfo;
  }
  
  public static function contributionPageConfigIsValid($contributionPageId) {
    // Parameters for a basic api search of minimally qualifying contribution pages:
    $params = [
      'id' => $contributionPageId, 
      'is_active' => 1,
      'is_allow_other_amount' => 1,
      'is_recur' => 0,
      'is_monetary' => 1,
      'is_pay_later' => 0,
      'amount_block_is_active' => 1,
    ];
    $params['sequential'] = 1;
    
/*
*        'is_email_receipt' => '0',
*        'is_share' => '0',
*        'is_confirm_enabled' => '1',
* 'Thank-you Page'
 
 */    
    
    $contributionPageGet = civicrm_api3('ContributionPage', 'get', $params);
    $contributionPage = $contributionPageGet['values'][0] ?? FALSE;
    if (!$contributionPage) {
      // No contribution page was found, meeting basic requirements.
      return FALSE;
    }

    // Do verification on priceset:
    $priceSetId = CRM_Price_BAO_PriceSet::getFor('civicrm_contribution_page', $contributionPageId);
    $priceSetDetail = CRM_Price_BAO_PriceSet::getCachedPriceSetDetail($priceSetId);
    // Verify priceSet is quick-config.
    if(!$priceSetDetail['is_quick_config']) {
      return FALSE;
    }
    // Verify price set has no min amount:
    if ($priceSetDetail['min_amount']) {
      return FALSE;
    }
    // Verify price set has no max amount:
    if ($priceSetDetail['max_amount']) {
      return FALSE;
    }
    // Verify priceset has one field, which is 'other_amount'
    // This will also fail if membership is enabled, because then the price set will contain other fields.
    $priceFieldCount = count($priceSetDetail['fields']);
    if ($priceFieldCount > 1) {
      return FALSE;
    }
    $singlePriceField = array_pop($priceSetDetail['fields']);
    if ($singlePriceField['name'] != 'other_amount') {
      return FALSE;
    }
    
    // Verify pledge block is disabled.
    // (Note there's no api (3 OR 4) for this; we could use DAO, but might
    // as well use SQL.
    $query = "
      SELECT count(*)
      FROM civicrm_pledge_block
      WHERE
        entity_table = 'civicrm_contribution_page'
        AND entity_id = %1
    ";
    $queryParams = [
      1 => array($contributionPageId, 'Integer'),
    ];
    $sql = CRM_Core_DAO::composeQuery($query, $queryParams);
    $pledgeBlockCount = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    if ($pledgeBlockCount) {
      return FALSE;
    }

    // Verify no profiles are enabled.
    $ufJoinCount = civicrm_api3('UFJoin', 'getCount', [
      'entity_table' => "civicrm_contribution_page",
      'entity_id' => $contributionPageId,
    ]);
    if ($ufJoinCount) {
      return FALSE;
    }
    
    // Verify no premiums are enabled.
    $ufJoinCount = civicrm_api3('Premium', 'getCount', [
      'entity_table' => "civicrm_contribution_page",
      'entity_id' => $contributionPageId,
      'premiums_active' => 1,
    ]);
    if ($ufJoinCount) {
      return FALSE;
    }
    
    // Verify pcp block is disabled.
    // (Note there's no api (3 OR 4) for this; we could use DAO, but might
    // as well use SQL.
    $query = "
      SELECT count(*)
      FROM civicrm_pcp_block
      WHERE
        entity_table = 'civicrm_contribution_page'
        AND entity_id = %1
        AND is_active
    ";
    $queryParams = [
      1 => array($contributionPageId, 'Integer'),
    ];
    $sql = CRM_Core_DAO::composeQuery($query, $queryParams);
    $pcpBlockCount = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    if ($pcpBlockCount) {
      return FALSE;
    }

    // If we're still here, we've passed all checks.
    return TRUE;
  }
  
  public static function getContributionPageConfigErrorMessage() {
    return ts('foobar');
  }
}
