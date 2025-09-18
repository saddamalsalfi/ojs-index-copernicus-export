{extends file="layouts/backend.tpl"}
{* Copernicus export: Select issues and export with schema validation toggle *}

{block name="page"}
{if isset($plugin)}
  {assign var=pname value=$plugin->getName()}
{else}
  {assign var=pname value=$pluginName}
{/if}

{* افتراضيًا نجعل التحقق مفعّل ما لم يُمرَّر خلاف ذلك عبر GET/POST *}
{assign var=__validate value=$smarty.request.validateSchema|default:1}

<div class="pkp_page_content pkp_page_importexport_plugins">
  <h1>{translate key="plugins.importexport.copernicus.export.issues.title"}</h1>
  <p class="pkp_help">{translate key="plugins.importexport.copernicus.export.issues.help"}</p>

  {if $issues && $issues|@count > 0}
  <form class="pkp_form" method="post" action="{url op="importexport" path="plugin"|to_array:$pname:"process"}">
    {csrf}
    <input type="hidden" name="target" value="issue" />

    {* أرسل قيمة 0 دائمًا، ثم دَع الـcheckbox يكتب 1 إذا كان مُحدّدًا *}
    <input type="hidden" name="validateSchema" value="0" />

    <fieldset class="fields">
      <div class="field">
        <label class="context">
          <input type="checkbox" name="validateSchema" value="1" {if $__validate}checked{/if}>
          {translate key="plugins.importexport.copernicus.validateSchema"}
        </label>
        <div class="description">
          {translate key="plugins.importexport.copernicus.validateSchema.help"}
        </div>
      </div>
    </fieldset>

    <fieldset class="fields">
      <div class="pkp_form_section">
        <label>
          <input type="checkbox" id="selectAll" />
          {translate key="common.selectAll"}
        </label>
      </div>

      <ul class="pkp_list">
        {foreach from=$issues item=issue}
          <li class="pkp_list_item">
            <label>
              <input type="checkbox" name="issueId[]" value="{$issue->getId()|escape}" class="issueCheck" />
              {$issue->getIssueIdentification()|escape}
            </label>
          </li>
        {/foreach}
      </ul>
    </fieldset>

    <div class="buttons">
      <button type="submit" class="pkp_button" name="export" value="1">
        {translate key="plugins.importexport.copernicus.export"}
      </button>
      <a class="pkp_button" href="{url op="importexport" path="plugin"|to_array:$pname}">
        {translate key="common.back"}
      </a>
    </div>
  </form>
  {else}
    <p>{translate key="plugins.importexport.copernicus.noIssues"}</p>
    <p>
      <a class="pkp_button" href="{url op="importexport" path="plugin"|to_array:$pname}">
        {translate key="common.back"}
      </a>
    </p>
  {/if}
</div>

{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
  var selectAll = document.getElementById('selectAll');
  if (!selectAll) return;
  var checks = document.querySelectorAll('input.issueCheck');
  selectAll.addEventListener('change', function () {
    checks.forEach(function (c) { c.checked = selectAll.checked; });
  });
});
</script>
{/literal}
{/block}
