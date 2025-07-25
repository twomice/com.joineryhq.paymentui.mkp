<?php

require_once 'CRM/Core/Form.php';
use CRM_Paymentui_ExtensionUtil as E;

/**
 * Form controller class for extension Settings form.
 * Borrowed heavily from
 * https://github.com/eileenmcnaughton/nz.co.fuzion.civixero/blob/master/CRM/Civixero/Form/XeroSettings.php
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Paymentui_Form_Settings extends CRM_Core_Form {

  public static $settingFilter = ['group' => 'paymentui'];
  public static $extensionName = 'com.joineryhq.paymentui.mkp';
  private $_submittedValues = [];
  private $_settings = [];

  public function __construct($state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL) {

    $this->setSettings();

    parent::__construct(
      $state = NULL, $action = CRM_Core_Action::NONE, $method = 'post', $name = NULL
    );
  }

  public function buildQuickForm() {
    if (!$this->_flagSubmitted) {
      // Only on page load (not on submit), validate the selected Contribution Page.
      $this->showWarnings();
    }

    $settings = $this->_settings;
    foreach ($settings as $name => $setting) {
      if (isset($setting['quick_form_type'])) {
        switch ($setting['html_type']) {
          case 'Select':
            $this->add(
              $setting['html_type'],
              $setting['name'],
              $setting['title'],
              $this->getSettingOptions($setting), NULL, $setting['html_attributes']
            );
            break;

          case 'CheckBox':
            $this->addCheckBox(
              $setting['name'],
              $setting['title'],
              array_flip($this->getSettingOptions($setting))
            );
            break;

          case 'Radio':
            $this->addRadio(
              $setting['name'],
              $setting['title'],
              $this->getSettingOptions($setting)
            );
            break;

          case 'EntityRef':
            $this->addEntityRef(
              $setting['name'],
              $setting['title'],
              $this->getEntityRefProps($setting)
            );
            break;

          default:
            $add = 'add' . $setting['quick_form_type'];
            if ($add == 'addElement') {
              $this->$add($setting['html_type'], $name, ts($setting['title']), ($setting['html_attributes'] ?? []));
            }
            else {
              $this->$add($name, ts($setting['title']));
            }
            break;
        }
      }
      $descriptions[$setting['name']] = ts($setting['description']);

      if (!empty($setting['X_form_rules_args'])) {
        $rules_args = (array) $setting['X_form_rules_args'];
        foreach ($rules_args as $rule_args) {
          array_unshift($rule_args, $setting['name']);
          call_user_func_array([$this, 'addRule'], $rule_args);
        }
      }
    }
    $this->assign("descriptions", $descriptions);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    CRM_Core_Resources::singleton()->addStyleFile(E::LONG_NAME, 'css/extension.css');

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $this->_submittedValues = $this->exportValues();
    $this->saveSettings();
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/paymentui/settings', 'reset=1'));
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return [string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons". These
    // items don't have labels. We'll identify renderable by filtering on
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
   * Define the list of settings we are going to allow to be set on this form.
   */
  public function setSettings() {
    if (empty($this->_settings)) {
      $this->_settings = self::getSettings();
    }
  }

  public static function getSettings() {
    $settings = civicrm_api3('setting', 'getfields', ['filters' => self::$settingFilter]);
    return $settings['values'];
  }

  /**
   * Get the settings we are going to allow to be set on this form.
   */
  public function saveSettings() {
    $settings = $this->_settings;
    $values = array_intersect_key($this->_submittedValues, $settings);
    civicrm_api3('setting', 'create', $values);

    // Save any that are not submitted, as well (e.g., checkboxes that aren't checked).
    $unsettings = array_fill_keys(array_keys(array_diff_key($settings, $this->_submittedValues)), NULL);
    civicrm_api3('setting', 'create', $unsettings);

    CRM_Core_Session::setStatus(" ", ts('Settings saved.'), "success");
  }

  /**
   * Set defaults for form.
   *
   * @see CRM_Core_Form::setDefaultValues()
   */
  public function setDefaultValues() {
    $result = civicrm_api3('setting', 'get', ['return' => array_keys($this->_settings)]);
    $domainID = CRM_Core_Config::domainID();
    $ret = ($result['values'][$domainID] ?? NULL);
    return $ret;
  }

  public static function getExcludeStatusOptions() {
    $options = [];
    $result = civicrm_api3('ParticipantStatusType', 'get', [
      'options' => ['limit' => 0],
    ]);
    foreach ($result['values'] as $id => $value) {
      $id = $value['id'];
      $label = $value['label'];
      $options[$id] = $label;
    }
    asort($options);
    return $options;
  }

  public static function getExcludeRoleOptions() {
    $options = [];
    $result = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => "participant_role",
      'options' => ['limit' => 0],
    ]);
    foreach ($result['values'] as $id => $value) {
      $id = $value['value'];
      $label = $value['label'];
      $options[$id] = $label;
    }
    asort($options);
    return $options;
  }

  public static function getContributionPageEntityRefProps() {
    return [
      'entity' => 'contributionPage',
      'placeholder' => '- ' . E::ts('Select') . ' -',
      'select' => ['minimumInputLength' => 0],
      'api' => [
        'x-is-paymentui' => TRUE,
      ],
    ];
  }

  public function getSettingOptions($setting) {
    if (!empty($setting['X_options_callback']) && is_callable($setting['X_options_callback'])) {
      return call_user_func($setting['X_options_callback']);
    }
    else {
      return ($setting['X_options'] ?? []);
    }
  }

  public function getEntityRefProps($setting) {
    if (!empty($setting['X_entityref_props_callback']) && is_callable($setting['X_entityref_props_callback'])) {
      return call_user_func($setting['X_entityref_props_callback']);
    }
    else {
      return [];
    }
  }

  private function showWarnings() {
    $selectedContributionPageId = \Civi::settings()->get('paymentui_contribution_page_id');
    if ($selectedContributionPageId) {
      $configValidator = new CRM_Paymentui_Configvalidator($selectedContributionPageId);
      if (!$configValidator->isValid()) {
        $contributionPageTitle = civicrm_api3('ContributionPage', 'getValue', [
          'id' => $selectedContributionPageId,
          'return' => 'title',
        ]);
        $statusMsg = ts('The selected Contribution Page <em>%1</em> has a problem:', [1 => $contributionPageTitle]) . ' ' . CRM_Paymentui_Util::getContributionPageConfigHelpMessage();
        CRM_Core_Session::setStatus($statusMsg, ts('Warning'), 'error');
      }
    }
  }

}
