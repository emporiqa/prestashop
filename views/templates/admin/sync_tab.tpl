{*
 * Emporiqa Sync Tab Template
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 *}

<div class="alert alert-info emporiqa-info-banner">
    {l s='Before your first sync, configure your LLM provider at' mod='emporiqa'}
    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=llm" target="_blank" rel="noopener">
        {l s='Settings' mod='emporiqa'} &rarr; {l s='Store Integration' mod='emporiqa'} &rarr; {l s='Language Model & API Key' mod='emporiqa'}
    </a>.
</div>

{if !$emporiqa_store_id || !$emporiqa_webhook_secret_set}
    <div class="alert alert-warning">
        {l s='Configure your Store ID and Webhook Secret in the Settings tab before syncing.' mod='emporiqa'}
    </div>
{/if}

{* --- Sync Overview --- *}
<div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-sync-overview">
    <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
        <span class="emporiqa-section-toggle"></span>
        <i class="icon-bar-chart"></i> {l s='Sync Overview' mod='emporiqa'}
    </div>
    <div class="emporiqa-section-body">
        <div class="form-wrapper">
            {if $emporiqa_sync_products}
                <p>
                    <strong>{l s='Products' mod='emporiqa'}</strong><br />
                    {$emporiqa_product_count|intval} {l s='products (all translations included per product)' mod='emporiqa'}
                </p>
            {else}
                <p class="emporiqa-sync-disabled-note">
                    <strong>{l s='Products' mod='emporiqa'}</strong><br />
                    <em>{l s='Product sync is disabled.' mod='emporiqa'}
                    <a href="#settings" class="emporiqa-nav-tab-link" data-target-tab="emporiqa-settings">{l s='Enable it in the Settings tab.' mod='emporiqa'}</a></em>
                </p>
            {/if}
            {if $emporiqa_sync_pages}
                <p>
                    <strong>{l s='Pages' mod='emporiqa'}</strong><br />
                    {$emporiqa_page_count|intval} {l s='pages (all translations included per page)' mod='emporiqa'}
                </p>
            {else}
                <p class="emporiqa-sync-disabled-note">
                    <strong>{l s='Pages' mod='emporiqa'}</strong><br />
                    <em>{l s='Page sync is disabled.' mod='emporiqa'}
                    <a href="#settings" class="emporiqa-nav-tab-link" data-target-tab="emporiqa-settings">{l s='Enable it in the Settings tab.' mod='emporiqa'}</a></em>
                </p>
            {/if}
        </div>
    </div>
</div>

{* --- Sync Actions --- *}
<div class="emporiqa-sync-descriptions">
    <p><strong>{l s='Sync Products' mod='emporiqa'}</strong> &mdash; {l s='send all products to Emporiqa.' mod='emporiqa'}</p>
    <p><strong>{l s='Sync Pages' mod='emporiqa'}</strong> &mdash; {l s='send all pages to Emporiqa.' mod='emporiqa'}</p>
    <p><strong>{l s='Sync All' mod='emporiqa'}</strong> &mdash; {l s='send all products and pages at once.' mod='emporiqa'}</p>
</div>

<div class="emporiqa-sync-actions">
    <button type="button" id="emporiqa-sync-products" class="btn btn-primary" data-entity="products"
        {if !$emporiqa_store_id || !$emporiqa_webhook_secret_set || !$emporiqa_sync_products}disabled="disabled"{/if}>
        <i class="icon-cubes"></i> {l s='Sync Products' mod='emporiqa'}
    </button>
    <button type="button" id="emporiqa-sync-pages" class="btn btn-default" data-entity="pages"
        {if !$emporiqa_store_id || !$emporiqa_webhook_secret_set || !$emporiqa_sync_pages}disabled="disabled"{/if}>
        <i class="icon-file-text"></i> {l s='Sync Pages' mod='emporiqa'}
    </button>
    <button type="button" id="emporiqa-sync-all" class="btn btn-default" data-entity="all"
        {if !$emporiqa_store_id || !$emporiqa_webhook_secret_set}disabled="disabled"{/if}>
        <i class="icon-globe"></i> {l s='Sync All' mod='emporiqa'}
    </button>
    <button type="button" id="emporiqa-sync-cancel" class="btn btn-danger" style="display:none;">
        <i class="icon-times"></i> {l s='Cancel' mod='emporiqa'}
    </button>
</div>

<div class="emporiqa-progress-wrapper" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
    <div class="emporiqa-progress-bar">
        <div class="emporiqa-progress-bar-fill" style="width: 0%;"></div>
        <div class="emporiqa-progress-text">0%</div>
    </div>
</div>

<div class="emporiqa-sync-log" aria-live="polite"></div>
