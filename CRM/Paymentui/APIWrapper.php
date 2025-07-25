<?php

use CRM_Paymentui_ExtensionUtil as E;

/**
 * Description of CRM_Stepw_APIWrapper
 *
 * @author as
 */
class CRM_Paymentui_APIWrapper {

  /**
   * API wrapper for 'prepare' events; delegates to private static methods in this class.
   *
   * @param Civi\API\Event\PrepareEvent $event
   */
  public static function PREPARE (Civi\API\Event\PrepareEvent $event) {
    // Pass event to the PREPARE handler for this api request, if one exists in this class.
    $requestSignature = $event->getApiRequestSig();
    $methodName = 'PREPARE_' . str_replace('.', '_', $requestSignature);
    if (is_callable("self::$methodName")) {
      call_user_func_array("self::$methodName", [$event]);
    }
  }

  /**
   * API wrapper for 'respond' events; delegates to private static methods in this class.
   *
   * @param Civi\API\Event\RespondEvent $event
   */
  public static function RESPOND(Civi\API\Event\RespondEvent $event) {
    // Pass event to the RESPOND handler for this api request, if one exists in this class.
    $requestSignature = $event->getApiRequestSig();
    $methodName = 'RESPOND_' . str_replace('.', '_', $requestSignature);
    if (is_callable("self::$methodName")) {
      call_user_func_array("self::$methodName", [$event]);
    }
  }

  /**
   * API wrapper for 'respond' event on 3.contributionpage.getlist
   *
   * @param Civi\API\Event\RespondEvent $event
   */
  private static function RESPOND_3_contributionpage_getlist($event) {
    // Alter the resonse so that Description just shows the Contribution Page ID.
    $request = $event->getApiRequest();
    if (!($request['params']['x-is-paymentui'] ?? FALSE)) {
      return;
    }
    $response = $event->getResponse();
    foreach ($response['values'] as &$value) {
      $value['description'] = [E::ts('Contribution Page ID') . ': ' . $value['id']];
    }
    $event->setResponse($response);
  }

}
