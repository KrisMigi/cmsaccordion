{* KM CMS Accordion – szablon *}
{* Zmienne: uniqid, title, content (HTML), open (bool), group (string) *}
<details class="km-acc"
         id="kmacc-{$uniqid|escape:'html':'UTF-8'}"
         data-group="{$group|escape:'html':'UTF-8'}"
         {if $open}open{/if}>
  <summary class="km-acc-header">
    <span class="km-acc-title">{$title|escape:'html':'UTF-8'}</span>
    <span class="km-acc-icon" aria-hidden="true"></span>
  </summary>
  <div class="km-acc-panel">
    {$content nofilter}
  </div>
</details>
