{if $participantInfo}
  {* HEADER *}
  <div class="form-item">
    <fieldset>
    <legend>{ts}{$displayName}{/ts}</legend>
    <div>
      {crmButton href="#" id="paymentui-button-show-payable" icon="bullet"}Show only payable events{/crmButton}
      {crmButton href="#" id="paymentui-button-show-all" icon="check"}Show all{/crmButton}
    </div>
    <table class="form-layout" id="paymentui-events-list">
      <thead class="sticky">
        {foreach from=$columnHeaders item=header}
          <th scope="col"><strong>{$header}</strong></th>
        {/foreach}
      </thead>
      {foreach from=$participantInfo item=row}
        <tr class="{$row.rowClass}">
          <td class="paymentui-event-name">{$row.event_name}</td>
          <td class="paymentui-contact-name">{$row.contact_name}</td>
          <td class="paymentui-total-amount nowrap">{$row.total_amount|crmMoney}</td>
          <td class="paymentui-amount-paid nowrap">{$row.paid|crmMoney}</td>
          <td class="paymentui-balance nowrap">{$row.balance|crmMoney}</td>

          <td class="paymentui-payment-amount nowrap">
            {if !$row.is_counted}
              {$row.status}
            {elseif $form.payment[$row.pid].html}
              {$form.payment[$row.pid].html|crmMoney}
            {else}
              Payment completed
            {/if}
          </td>
        </tr>
      {/foreach}
      {if $contactId}
      <thead class="sticky">
            <td colspan = 5 scope="col"><strong>Total</strong></th>
            <td class="font-size12pt "><span>$ </span><span name='total' id ='total'>0</span></td>
      </thead>
      {/if}
    </table>
    <p id="empty-events-list-notice">{ts}No unpaid balances remain for your events.{/ts}</p>
    </fieldset>
  </div>

  <div id="paymentui-billing-form">
    {* FIELD EXAMPLE: OPTION 1 (AUTOMATIC LAYOUT) *}
    {include file="CRM/Core/BillingBlock.tpl" context="front-end" }
    {if $form.payment_processor.label}
      {* PP selection only works with JS enabled, so we hide it initially *}
      <fieldset class="crm-group payment_options-group" style="display:none;">
        <legend>{ts}Payment Options{/ts}</legend>
        <div class="crm-section payment_processor-section">
          <div class="label">{$form.payment_processor.label}</div>
          <div class="content">{$form.payment_processor.html}</div>
          <div class="clear"></div>
        </div>
      </fieldset>
      {/if}

      {if $is_pay_later}
      <fieldset class="crm-group pay_later-group">
        <legend>{ts}Payment Options{/ts}</legend>
        <div class="crm-section pay_later_receipt-section">
          <div class="label">&nbsp;</div>
          <div class="content">
            [x] {$pay_later_text}
          </div>
          <div class="clear"></div>
        </div>
      </fieldset>
      {/if}

      <div id="billing-payment-block">
        {* If we have a payment processor, load it - otherwise it happens via ajax *}
        {if $ppType}
          {include file="CRM/Contribute/Form/Contribution/Main.tpl" snippet=4}
        {/if}
      </div>
      {include file="CRM/common/paymentBlock.tpl"}

    {* FOOTER *}
    <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
    </div>
    {literal}

    <script type="text/javascript">
    function calculateTotal() {
      var total = 0.00;
      cj.each(cj( "input[name^='payment']" ), function() {
        var amt = cj(this).val();
        if ( cj.isNumeric(amt) ) {
          total = parseFloat(total)+parseFloat(amt);
        }
      });
      total = Math.round(total*100, 2)/100;
        total.toFixed(2);

      document.getElementById('total').innerHTML = total;
    }
    </script>
    {/literal}
  </div>
{else}
  <p>{ts}No relevant events were found for you.{/ts}</p>
{/if}