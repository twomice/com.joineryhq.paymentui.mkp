<?php

/**
 * Settings-related utility methods.
 *
 */
class CRM_Paymentui_Settings {

  public static function getEventSettings($eventId) {
    $settingName = "event_settings_{$eventId}";
    $result = civicrm_api3('OptionValue', 'get', array(
      'sequential' => 1,
      'option_group_id' => "paymentui",
      'name' => $settingName,
    ));
    $resultValue = CRM_Utils_Array::value(0, $result['values'], array());
    $settingJson = CRM_Utils_Array::value('value', $resultValue, '{}');
    return json_decode($settingJson, TRUE);
  }

  public static function saveAllEventSettings($eventId, $settings) {
    $settingName = "event_settings_{$eventId}";
    $result = civicrm_api3('OptionValue', 'get', array(
      'sequential' => 1,
      'option_group_id' => "paymentui",
      'name' => $settingName,
    ));

    $createParams = array();

    if ($optionValueId = CRM_Utils_Array::value('id', $result)) {
      $createParams['id'] = $optionValueId;
    }
    else {
      $createParams['name'] = $settingName;
      $createParams['option_group_id'] = "paymentui";
    }

    // Add event_id to settings. Without this, optionValue.create api was failing
    // to save new settings with a message like "value already exists in the database"
    // if the values for this event are the same as for some other event. So by
    // adding event_id, we make it unique to this event.
    $settings['event_id'] = $eventId;
    $createParams['value'] = json_encode($settings);

    try {
      civicrm_api3('optionValue', 'create', $createParams);
      return TRUE;
    }
    catch (CiviCRM_API3_Exception $e) {
      return FALSE;
    }
  }

}
