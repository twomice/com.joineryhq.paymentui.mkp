<?php

return array(
  'paymentui_payment_processor_id' => array(
    'group_name' => 'Paymentui Settings',
    'group' => 'paymentui',
    'name' => 'paymentui_payment_processor_id',
    'type' => 'Int',
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => ts('The payment processor to be used for all partial payments.'),
    'title' =>  ts('Payment processor'),
    'help_text' => '',
    'html_type' => 'Select',
    'html_attributes' => array(),
    'quick_form_type' => 'Element',
    'X_options_callback' => 'CRM_Paymentui_Form_Settings::getPaymentProcessorOptions',
  ),
 );