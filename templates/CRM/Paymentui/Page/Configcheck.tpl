<h2>Partial Payments UI: Contribution Page integration</h2>
<p>
  Partial Payments UI operates through a CiviCRM Contribution Page, which you must
  specify in the "Contribution Page" setting at <em>Administer</em> &gt; <em>Customize Data
  and Screens</em> &gt; <a href="{$settingsUrl}">Partial Payments UI</a>.
</p>


<h3>Contribution Page configuration requirements</h3>
<p>
  Your selected contribution page is currently: 
  {if $contributionPageId}
    <strong>{$contributionPageTitle}</strong>. 
    [<a href="{crmURL p='civicrm/admin/contribute/settings' q="action=update&reset=1&id=$contributionPageId"}">Contribution Page Configuration</a>]
  {else}
    <strong>(NONE)</strong>
{/if}
</p>

<p>
  The following list displays the configuration requirements for the selected 
  Contribution Page.
  {if $contributionPageId}
     Note the status indicator for each.
  {else}
    (once you have specified the Contribution Page in the <a href="{$settingsUrl}">Partial Payments UI</a> settings,
    each requirement will be marked below with an indicator of its status.
  {/if}
</p>

<table>
  <thead>
  <th>Requirement</th>
  {if $contributionPageId}
     <th>Status</th>
  {/if} 
  </thead>
  <tbody>
  {foreach from=$checks item=check}
    {if $contributionPageId}
        {if $check.status === true}
          {assign var=rowClass value="status crm-ok"}
          {assign var=iconClass value="fa-check"}
        {elseif $check.status === false}
          {assign var=rowClass value="status error"}
          {assign var=iconClass value="fa-exclamation-triangle"}
        {/if}
   {/if}
    <tr class="{$rowClass} status-{$check.status}">
      <td>{$check.label}</td>
      {if $contributionPageId}
           <td class="">
             <i class="crm-i {$iconClass}"></i>                
           </td>
      {/if} 
    </tr>
  {/foreach}
  </tbody>
</table>

<h3>Ignored Contribution Page settings</h3>
<p>
  The following Contribution Page configurations will be ignored, because the
  Partial Payments UI follows its own behavior in these matters:
</p>
<table>
  <thead>
    <th>ignored Configuration</th>
    <th>Rationale</th>
  </thead>
  <tbody>
    <tr>
      <td>Use a confirmation page?</td>
      <td>Partial Payments UI will skip the confirmation page.</td>
    </tr>
    <tr>
      <td>Add footer region with Twitter, Facebook and LinkedIn share buttons and scripts?</td>
      <td>This information would be discplayed on the Thank-You page (which is not displayed; see below) and in emailed receipts (for which Partial Payments UI generates its own receipt content).</td>
    </tr>
    <tr>
      <td>Thank-you Page Title (and related settings)</td>
      <td>Partial Payments UI will skip the Thank-You page and redirect the user to the main Partial Payments form.</td>
    </tr>
    <tr>
      <td>Email Receipt to Contributor? (and related settings)</td>
      <td>Partial Payments UI will always attempt to send a separate receipt to each participant for each separate partial payment on each participation record.</td>
    </tr>
  </tbody>
</table>
