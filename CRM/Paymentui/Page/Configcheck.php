<?php
use CRM_Paymentui_ExtensionUtil as E;

class CRM_Paymentui_Page_Configcheck extends CRM_Core_Page {

  public function run() {
    $contributionPageId = \Civi::settings()->get('paymentui_contribution_page_id');
    $this->assign('contributionPageId', $contributionPageId);

    if ($contributionPageId) {
      $contributionPageTitle = civicrm_api3('ContributionPage', 'getValue', [
        'id' => $contributionPageId,
        'return' => 'title',
      ]);
      $this->assign('contributionPageTitle', $contributionPageTitle);

    }
    $configValidator = new CRM_Paymentui_Configvalidator($contributionPageId, FALSE);
    $checks = $configValidator->getChecks();

    $this->assign('checks', $checks);
    $this->assign('settingsUrl', CRM_Utils_System::url('civicrm/admin/paymentui/settings'));

    CRM_Core_Resources::singleton()->addStyleFile(E::LONG_NAME, 'css/CRM_Paymentui_Page_Configcheck.css');

    parent::run();

    if (empty($contributionPageId)) {
      return;
    }

  }

}
