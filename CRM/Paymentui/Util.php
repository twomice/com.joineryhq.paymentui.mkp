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

  public static function getContributionPageConfigHelpMessage() {
    $url = CRM_Utils_System::url('civicrm/admin/paymentui/configcheck');
    return ts('See the <a href="%1">Partial Payments UI: Contribution Page Config Checker" page</a> for details on required configuration.', [1 => $url]);
  }

}
