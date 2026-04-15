{*
 * Emporiqa Admin Configuration Template
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 *}

<div class="emporiqa-wrap">
    <div class="emporiqa-header">
        <img src="{$emporiqa_module_dir|escape:'htmlall':'UTF-8'}views/img/logo-rectangle.png" alt="Emporiqa" class="emporiqa-header-logo" />
        <a href="https://emporiqa.com/docs/prestashop/" target="_blank" rel="noopener noreferrer" class="btn btn-default emporiqa-header-dashboard-btn">
            <i class="icon-book"></i> {l s='Documentation' mod='emporiqa'}
        </a>
        <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/" target="_blank" rel="noopener noreferrer" class="btn btn-default emporiqa-header-dashboard-btn">
            <i class="icon-external-link"></i> {l s='Open Dashboard' mod='emporiqa'}
        </a>
    </div>

    <div class="emporiqa-tabs">
        <a href="#settings" class="emporiqa-nav-tab active" data-tab="emporiqa-settings">{l s='Settings' mod='emporiqa'}</a>
        <a href="#sync" class="emporiqa-nav-tab" data-tab="emporiqa-sync">{l s='Sync' mod='emporiqa'}</a>
    </div>

    <div class="alert alert-info emporiqa-info-banner">
        <strong>{l s='Quick Setup:' mod='emporiqa'}</strong>
        {l s='1. Copy your' mod='emporiqa'}
        <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">{l s='Store ID and Connection Secret' mod='emporiqa'}</a>
        {l s='from the dashboard.' mod='emporiqa'}
        {l s='2. Save settings, then run your first sync from the Sync tab.' mod='emporiqa'}
    </div>

    {* ===== Settings Tab ===== *}
    <div id="emporiqa-settings" class="emporiqa-tab-content active">
        <form method="post" action="{$smarty.server.REQUEST_URI|escape:'htmlall':'UTF-8'}">

            {* --- Connection Settings --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-connection">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-cogs"></i> {l s='Connection Settings' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        <div class="form-group row">
                            <label class="control-label col-lg-3 required">{l s='Store ID' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="text" name="EMPORIQA_STORE_ID" value="{$emporiqa_store_id|escape:'htmlall':'UTF-8'}" class="form-control" autocomplete="off" />
                                <p class="help-block">{l s='Your Emporiqa Store ID. Find it in your' mod='emporiqa'}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">
                                        {l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Store Integration' mod='emporiqa'}
                                    </a>.
                                </p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3 required">{l s='Connection Secret' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="password" name="EMPORIQA_WEBHOOK_SECRET" value="" class="form-control" autocomplete="new-password"
                                    {if $emporiqa_webhook_secret_set}placeholder="{l s='Value is set (leave empty to keep)' mod='emporiqa'}"{/if} />
                                <p class="help-block">{l s='Your connection secret for HMAC signing. Find it in your' mod='emporiqa'}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">
                                        {l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Store Integration' mod='emporiqa'}
                                    </a>.
                                    {l s='Leave empty to keep the current value.' mod='emporiqa'}
                                </p>
                                {if !$emporiqa_webhook_secret_set}
                                    <p class="emporiqa-field-warning">{l s='Not configured.' mod='emporiqa'}
                                        <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">
                                            {l s='Get your secret from the dashboard.' mod='emporiqa'}
                                        </a>
                                    </p>
                                {/if}
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Order Tracking URL' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <div class="emporiqa-copy-row">
                                    <input type="text" value="{$emporiqa_order_tracking_url|escape:'htmlall':'UTF-8'}" class="form-control emporiqa-url-field" readonly />
                                    <button type="button" id="emporiqa-copy-tracking-url" class="btn btn-default emporiqa-copy-btn" data-url="{$emporiqa_order_tracking_url|escape:'htmlall':'UTF-8'}">
                                        <i class="icon-copy"></i> {l s='Copy' mod='emporiqa'}
                                    </button>
                                </div>
                                <p class="help-block">{l s='Copy this URL into your' mod='emporiqa'}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#order-tracking" target="_blank" rel="noopener noreferrer">
                                        {l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Store Integration' mod='emporiqa'} &rarr; {l s='Order Tracking' mod='emporiqa'}
                                    </a>.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {* --- Advanced --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-closed" id="emporiqa-section-advanced">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="false">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-cog"></i> {l s='Advanced' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Sync Products' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <span class="switch prestashop-switch fixed-width-lg">
                                    <input type="radio" name="EMPORIQA_SYNC_PRODUCTS" id="EMPORIQA_SYNC_PRODUCTS_on" value="1" {if $emporiqa_sync_products}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PRODUCTS_on">{l s='Yes' mod='emporiqa'}</label>
                                    <input type="radio" name="EMPORIQA_SYNC_PRODUCTS" id="EMPORIQA_SYNC_PRODUCTS_off" value="0" {if !$emporiqa_sync_products}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PRODUCTS_off">{l s='No' mod='emporiqa'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Automatically sync product changes to Emporiqa.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Sync Pages' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <span class="switch prestashop-switch fixed-width-lg">
                                    <input type="radio" name="EMPORIQA_SYNC_PAGES" id="EMPORIQA_SYNC_PAGES_on" value="1" {if $emporiqa_sync_pages}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PAGES_on">{l s='Yes' mod='emporiqa'}</label>
                                    <input type="radio" name="EMPORIQA_SYNC_PAGES" id="EMPORIQA_SYNC_PAGES_off" value="0" {if !$emporiqa_sync_pages}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PAGES_off">{l s='No' mod='emporiqa'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Automatically sync CMS page changes to Emporiqa.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Enabled Languages' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                {foreach from=$emporiqa_languages item=lang}
                                    <label class="checkbox-inline">
                                        <input type="checkbox" name="EMPORIQA_ENABLED_LANGUAGES[]" value="{$lang.emporiqa_code|escape:'htmlall':'UTF-8'}"
                                            {if in_array($lang.emporiqa_code, $emporiqa_enabled_languages)}checked="checked"{/if} />
                                        {$lang.name|escape:'htmlall':'UTF-8'} ({$lang.emporiqa_code|escape:'htmlall':'UTF-8'})
                                    </label>
                                {/foreach}
                                <p class="help-block">{l s='Select which languages to include in synced data. All selected languages are sent in each event.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Webhook URL' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="text" name="EMPORIQA_WEBHOOK_URL" value="{$emporiqa_webhook_url|escape:'htmlall':'UTF-8'}" class="form-control" />
                                <p class="help-block">{l s='Only change this if you are running a custom Emporiqa instance.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Batch Size' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="number" name="EMPORIQA_BATCH_SIZE" value="{$emporiqa_batch_size|escape:'htmlall':'UTF-8'}" class="form-control fixed-width-sm" min="1" max="500" />
                                <p class="help-block">{l s='Number of items per webhook batch (default: 25, max: 500).' mod='emporiqa'}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel-footer emporiqa-panel-footer">
                <button type="submit" name="submitEmporiqaSettings" class="btn btn-primary">
                    <i class="icon-save"></i> {l s='Save' mod='emporiqa'}
                </button>
            </div>
        </form>
    </div>

    {* ===== Sync Tab ===== *}
    <div id="emporiqa-sync" class="emporiqa-tab-content">
        {include file="./sync_tab.tpl"}
    </div>
</div>

<script>
    window.emporiqaSyncConfig = {
        ajaxUrl: '{$emporiqa_sync_ajax_url|escape:'javascript':'UTF-8'}',
        platformBaseUrl: '{$emporiqa_platform_base_url|escape:'javascript':'UTF-8'}',
        token: '{$emporiqa_token|escape:'javascript':'UTF-8'}'
    };
</script>
