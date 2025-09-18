{extends file="layouts/backend.tpl"}
{* Copernicus export: Entry page with validate toggle *}

{block name="page"}
{if isset($plugin)}
  {assign var=pname value=$plugin->getName()}
{else}
  {assign var=pname value=$pluginName}
{/if}

{* اجعل التحقق مفعّلاً افتراضيًا ما لم يُمرَّر خلاف ذلك *}
{assign var=__validate value=$smarty.request.validateSchema|default:1}

<div class="pkp_page_content pkp_page_importexport_plugins">
  <h1>{translate key="plugins.importexport.copernicus.displayName"}</h1>
  <p class="pkp_help">{translate key="plugins.importexport.copernicus.description"}</p>

  <div class="pkp_form">
    <form method="get" action="{url router=$smarty.const.ROUTE_PAGE page="management" op="importexport" path=["plugin", $pname, "issues"]}">
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

      <div class="buttons">
        <button type="submit" class="pkp_button">
          {translate key="plugins.importexport.copernicus.exportByIssues"}
        </button>
      </div>
    </form>
  </div>
</div>
{/block}
