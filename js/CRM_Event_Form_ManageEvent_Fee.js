/**
 * On-page event handling for paymentui/add/payment
 */
CRM.$(function ($) {
  $('input#is_paymentui').closest('table').prependTo('div#event-fees');
  $('input#is_paymentui').closest('td').prependTo($('input#is_paymentui').closest('tr'));
  $('input#is_paymentui').closest('table').removeClass('form-layout-compressed');
  $('input#is_paymentui').closest('table').addClass('form-layout');
  $('input#is_paymentui').closest('td').addClass('extra-long-fourty label');
  $('label[for="is_paymentui"]').closest('td').removeClass('label nowrap');



});