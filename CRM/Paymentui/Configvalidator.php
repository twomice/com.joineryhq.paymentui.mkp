<?php

/**
 * Validator for selected Contribution Page settings.
 */
class CRM_Paymentui_Configvalidator {

  private $contributionPageId;
  private $failFast = TRUE;
  private $checks = [];

  public function __construct($contributionPageId = NULL, $failFast = TRUE) {
    $this->contributionPageId = $contributionPageId;
    $this->failFast = $failFast;
  }

  public function isValid() {
    $this->runChecks();
    $statuses = CRM_Utils_Array::collect('status', $this->checks);
    if (in_array(FALSE, $statuses)) {
      return FALSE;
    }
    return TRUE;
  }

  public function getChecks() {
    $this->runChecks();
    return $this->checks;
  }

  private function runChecks() {
    if (!empty($this->checks)) {
      // Checks have already been run on this contribution page. No need to check again.
      return;
    }

    if ($this->failFast) {
      // Parameters for a basic api search of minimally qualifying contribution pages:
      $params = [
        'id' => $this->contributionPageId,
        'is_active' => 1,
        'is_allow_other_amount' => 1,
        'is_recur' => 0,
        'is_monetary' => 1,
        'is_pay_later' => 0,
        'amount_block_is_active' => 1,
      ];
      $params['sequential'] = 1;

      $contributionPageGet = civicrm_api3('ContributionPage', 'get', $params);
      $contributionPage = $contributionPageGet['values'][0] ?? FALSE;
      if (!$contributionPage) {
        // No contribution page was found, meeting basic requirements.
        $this->addCheckStatus('basic check', FALSE);
        return;
      }
    }
    else {
      if ($this->contributionPageId) {
        $params = [
          'id' => $this->contributionPageId,
        ];
        $params['sequential'] = 1;
        $contributionPageGet = civicrm_api3('ContributionPage', 'get', $params);
        $contributionPage = $contributionPageGet['values'][0] ?? FALSE;
        $this->addCheckStatus('Must exist', !empty($contributionPage));
      }
      else {
        $contributionPage = [];
      }
      $this->addCheckStatus('Must be active', $contributionPage['is_active']);
      $this->addCheckStatus('"Contribution Amounts" section must be enabled', $contributionPage['amount_block_is_active']);
      $this->addCheckStatus('"Allow other amounts" must be enabled', $contributionPage['is_allow_other_amount']);
      $this->addCheckStatus('"Recurring Contributions" must be disabled', !$contributionPage['is_recur']);
      $this->addCheckStatus('"Execute real-time monetary transactions" must be enabled', $contributionPage['is_monetary']);
      $this->addCheckStatus('"Pay later option" must be disabled', !$contributionPage['is_pay_later']);
    }

    // Verify no min amount:
    $status = !(bool) (float) $contributionPage['min_amount'];
    $this->addCheckStatus('Must not specify "Minimum Amount"', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Verify no max amount:
    $status = !isset($contributionPage['max_amount']);
    $this->addCheckStatus('Must not specify "Maximum Amount"', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Do verification on priceset:
    $priceSetId = CRM_Price_BAO_PriceSet::getFor('civicrm_contribution_page', $this->contributionPageId);
    $priceSetDetail = CRM_Price_BAO_PriceSet::getCachedPriceSetDetail($priceSetId);
    // Verify priceSet is quick-config.
    $status = (bool) $priceSetDetail['is_quick_config'];
    $this->addCheckStatus('Must not use a Price Set', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Verify priceset has one field, which is 'other_amount'
    // This will also fail if membership is enabled, because then the price set will contain other fields.
    $priceFieldCount = count($priceSetDetail['fields']);
    $singlePriceField = array_pop($priceSetDetail['fields']);

    $status = (
      ($priceFieldCount == 1)
      && ($singlePriceField['name'] == 'other_amount')
    );
    $this->addCheckStatus('Must only offer "other amount" price option (this check may fail due to: Memberships Section enabled; use of a Price Set; options in "Fixed Contribution Options")', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Verify membership options are disabled
    $membershipBlockCount = civicrm_api3('MembershipBlock', 'getCount', [
      'entity_table' => "civicrm_contribution_page",
      'entity_id' => $this->contributionPageId,
      'is_active' => 1,
    ]);
    $status = !$membershipBlockCount;
    $this->addCheckStatus('"Membership Section" must be disabled', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Verify no profiles are enabled.
    $ufJoinCount = civicrm_api3('UFJoin', 'getCount', [
      'entity_table' => "civicrm_contribution_page",
      'entity_id' => $this->contributionPageId,
    ]);
    $status = !$ufJoinCount;
    $this->addCheckStatus('Must not have configured profiles', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // Verify no premiums are enabled.
    $premiumCount = civicrm_api3('Premium', 'getCount', [
      'entity_table' => "civicrm_contribution_page",
      'entity_id' => $this->contributionPageId,
      'premiums_active' => 1,
    ]);
    $status = !$premiumCount;
    $this->addCheckStatus('"Premiums Section" must be disabled', $status);
    if (!$status && $this->failFast) {
      return;
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
      1 => array($this->contributionPageId, 'Integer'),
    ];
    $sql = CRM_Core_DAO::composeQuery($query, $queryParams);
    $pledgeBlockCount = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    $status = !$pledgeBlockCount;
    $this->addCheckStatus('"Pledges" must be disabled', $status);
    if (!$status && $this->failFast) {
      return;
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
      1 => array($this->contributionPageId, 'Integer'),
    ];
    $sql = CRM_Core_DAO::composeQuery($query, $queryParams);
    $pcpBlockCount = CRM_Core_DAO::singleValueQuery($query, $queryParams);
    $status = !$pcpBlockCount;
    $this->addCheckStatus('"Personal Campaign Pages" must be disabled', $status);
    if (!$status && $this->failFast) {
      return;
    }

    // If we're still here, we've passed all checks.
    return TRUE;

  }

  private function addCheckStatus($label, $status) {
    $this->checks[] = [
      'label' => $label,
      'status' => (bool) $status,
    ];
  }

}
