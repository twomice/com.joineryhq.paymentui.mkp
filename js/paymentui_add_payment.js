/**
 * On-page event handling for paymentui/add/payment
 */
CRM.$(function($){
  // Add click handlers for "show all" and "show payable" buttons.
  $('a#paymentui-button-show-payable').click(paymentui_add_payment.show_payable);
  $('a#paymentui-button-show-all').click(paymentui_add_payment.show_all);

  // Start by showing all events.
  paymentui_add_payment.show_all();
})

var paymentui_add_payment = {
  // Click handler for "show payable" button.
  'show_payable': function() {
    // For each row, hide it if it doesn't have a payment field, otherwise be
    // sure it's showing.
    CRM.$('table#paymentui-events-list tbody tr').each(function(idx, el) {
      $el = CRM.$(el)
      if ($el.find('input.paymentui-payment-amount').length > 0) {
        $el.show();
      }
      else {
        $el.hide();
      }
    })
    paymentui_add_payment.toggle_list_show();
    CRM.$('a#paymentui-button-show-payable').hide()
    CRM.$('a#paymentui-button-show-all').show()
  },

  // Click handler for "show all" button.
  'show_all': function() {
    // Just show all the rows.
    CRM.$('table#paymentui-events-list tbody tr').show();
    paymentui_add_payment.toggle_list_show();
    CRM.$('a#paymentui-button-show-payable').show()
    CRM.$('a#paymentui-button-show-all').hide()
  },

  // Toggle display of relevant page elements based on visible contents of
  // events-list table.
  'toggle_list_show': function() {
    CRM.$('table#paymentui-events-list').show();
    if(CRM.$('table#paymentui-events-list tbody tr:visible').length){
      CRM.$('table#paymentui-events-list').show();
      CRM.$('div#paymentui-billing-form').show();
      CRM.$('p#empty-events-list-notice').hide();
    }
    else {
      CRM.$('table#paymentui-events-list').hide();
      CRM.$('div#paymentui-billing-form').hide();
      CRM.$('p#empty-events-list-notice').show();
    }
  }
}