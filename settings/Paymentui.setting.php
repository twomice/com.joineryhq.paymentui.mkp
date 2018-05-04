<?php

return array(
  'paymentui_exclude_participant_status' => array(
    'group_name' => 'Paymentui Settings',
    'group' => 'paymentui',
    'name' => 'paymentui_exclude_participant_status',
    'type' => 'Int',
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => array(0),
    'description' => ts('Participation records in any of the selected statuses will not appear on the payments page.') . ' (' . ts('Use Ctrl+click to select or unselect multiple options.') . ')',
    'title' =>  ts('Exclude by status'),
    'help_text' => '',
    'html_type' => 'Select',
    'html_attributes' => array(
      'multiple' => TRUE,
      'size' => 10,
    ),
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Paymentui_Form_Settings::getExcludeStatusOptions',
  ),
  'paymentui_exclude_participant_role' => array(
    'group_name' => 'Paymentui Settings',
    'group' => 'paymentui',
    'name' => 'paymentui_exclude_participant_role',
    'type' => 'Int',
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
    'default' => array(0),
    'description' => ts('Participation records in any of the selected roless will not appear on the payments page.') . ' (' . ts('Use Ctrl+click to select or unselect multiple options.') . ')',
    'title' =>  ts('Exclude by role'),
    'help_text' => '',
    'html_type' => 'Select',
    'html_attributes' => array(
      'multiple' => TRUE,
      'size' => 10,
    ),
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Paymentui_Form_Settings::getExcludeRoleOptions',
  ),
 );