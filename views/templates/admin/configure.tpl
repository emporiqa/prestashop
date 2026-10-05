{*
 * Emporiqa Admin Configuration Template
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 *}

<div class="emporiqa-wrap">
    <div class="emporiqa-header">
        <img src="{$emporiqa_module_dir|escape:'htmlall':'UTF-8'}views/img/logo-rectangle.png?v={$emporiqa_module_version|escape:'htmlall':'UTF-8'}" alt="Emporiqa" class="emporiqa-header-logo" />
        <div class="emporiqa-header-actions">
            <a href="https://emporiqa.com/docs/prestashop/" target="_blank" rel="noopener noreferrer" class="btn btn-default emporiqa-header-dashboard-btn">
                <i class="icon-book"></i> {l s='Documentation' mod='emporiqa'}
            </a>
            <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/" target="_blank" rel="noopener noreferrer" class="btn btn-default emporiqa-header-dashboard-btn">
                <i class="icon-external-link"></i> {l s='Open Emporiqa dashboard' mod='emporiqa'}
            </a>
        </div>
    </div>

    <div class="emporiqa-tabs">
        <a href="#settings" class="emporiqa-nav-tab {if !$emporiqa_just_connected}active{/if}" data-tab="emporiqa-settings">{l s='Settings' mod='emporiqa'}</a>
        <a href="#sync" class="emporiqa-nav-tab {if $emporiqa_just_connected}active{/if}" data-tab="emporiqa-sync">{l s='Sync' mod='emporiqa'}</a>
    </div>

    {* ===== One-click connect state banners (1.2.0+) ===== *}
    {if $emporiqa_connect_state == 'error'}
        <div class="alert alert-danger emporiqa-info-banner">
            <strong>{l s='Connection failed.' mod='emporiqa'}</strong>
            {$emporiqa_connect_last_error|escape:'htmlall':'UTF-8'}
            <br>{l s='Click Connect to Emporiqa below to try again. If it keeps failing, expand Edit credentials manually and paste your Store ID and Connection Secret.' mod='emporiqa'}
        </div>
    {/if}

    {* ===== Settings Tab ===== *}
    <div id="emporiqa-settings" class="emporiqa-tab-content {if !$emporiqa_just_connected}active{/if}">
        {if $emporiqa_shops_none_warning}
            <div class="alert alert-warning emporiqa-info-banner">
                {l s='None of the shops ticked under Shops and languages is active any more, so nothing is synced and the chat is hidden. Tick at least one shop and click Save.' mod='emporiqa'}
            </div>
        {/if}
        {if $emporiqa_shop_context_notice}
            <div class="alert alert-info emporiqa-info-banner">
                {l s='These settings apply to all your shops: they share one Emporiqa store, and each shop is a channel in it. Choose which shops to sync under Shops and languages.' mod='emporiqa'}
            </div>
        {/if}
        <form method="post" action="{$smarty.server.REQUEST_URI|escape:'htmlall':'UTF-8'}">

            {* --- Connection Settings --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-connection">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-cogs"></i> {l s='Connection Settings' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">

                    {* ===== One-click connect (primary path) ===== *}
                    <div class="panel emporiqa-connect-card">
                        {if $emporiqa_connect_state == 'connected'}
                            <h3 class="emporiqa-connect-h3"><i class="icon-check text-success"></i> {l s='Connected to Emporiqa' mod='emporiqa'}</h3>
                            <p class="text-muted">{l s='Store ID' mod='emporiqa'}: <code>{$emporiqa_store_id|escape:'htmlall':'UTF-8'}</code></p>
                            <p>
                                <a href="{$emporiqa_connect_initiate_url|escape:'htmlall':'UTF-8'}" class="btn btn-default">
                                    <i class="icon-refresh"></i> {l s='Reconnect' mod='emporiqa'}
                                </a>
                            </p>
                            <p class="text-muted small">{l s='Reconnect only if Emporiqa or this page asks you to. It replaces your connection secret automatically.' mod='emporiqa'}</p>
                        {else}
                            <h3 class="emporiqa-connect-h3">{l s='Connect to Emporiqa in one click' mod='emporiqa'}</h3>
                            <p>{l s="We'll sign you in, link this store, and send back a fresh connection secret. No copy-pasting." mod='emporiqa'}</p>
                            {if !$emporiqa_https_enabled}
                                <p class="emporiqa-field-warning">
                                    {l s='One-click connect requires HTTPS. Turn on SSL under Shop Parameters > General, or paste your credentials under Edit credentials manually below.' mod='emporiqa'}
                                </p>
                            {else}
                                <p>
                                    <a href="{$emporiqa_connect_initiate_url|escape:'htmlall':'UTF-8'}" class="btn btn-primary btn-lg">
                                        <i class="icon-link"></i> {l s='Connect to Emporiqa' mod='emporiqa'}
                                    </a>
                                </p>
                            {/if}
                        {/if}
                    </div>

                    {* ===== Manual paste (secondary path, collapsed by default) ===== *}
                    <div class="emporiqa-collapsible-section emporiqa-section-closed" id="emporiqa-section-manual-paste">
                        <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="false">
                            <span class="emporiqa-section-toggle"></span>
                            <i class="icon-edit"></i> {l s='Edit credentials manually' mod='emporiqa'}
                        </div>
                        <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        <div class="form-group row">
                            <label class="control-label col-lg-3 required">{l s='Store ID' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="text" name="EMPORIQA_STORE_ID" value="{$emporiqa_store_id|escape:'htmlall':'UTF-8'}" class="form-control" autocomplete="off" />
                                <p class="help-block">{l s='Your Emporiqa Store ID. Find it in your' mod='emporiqa'}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">
                                        {l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Integration' mod='emporiqa'}</a>.
                                </p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3 required">{l s='Connection Secret' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="password" name="EMPORIQA_WEBHOOK_SECRET" value="" class="form-control" autocomplete="new-password"
                                    {if $emporiqa_webhook_secret_set}placeholder="{l s='Value is set (leave empty to keep)' mod='emporiqa'}"{/if} />
                                <p class="help-block">{l s='The secret that signs every request between your shop and Emporiqa. Find it in your' mod='emporiqa'}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#integration-overview" target="_blank" rel="noopener noreferrer">
                                        {l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Integration' mod='emporiqa'}</a>.
                                    {l s='Leave empty to keep the current value.' mod='emporiqa'}
                                </p>
                            </div>
                        </div>
                    </div>{* /form-wrapper inside manual-paste *}
                        </div>{* /section-body of manual-paste *}
                    </div>{* /emporiqa-collapsible-section manual-paste *}

                </div>
            </div>

            {* --- Shops and languages --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-shops-languages">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-globe"></i> {l s='Shops and languages' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        {if $emporiqa_show_shops}
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Shops' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="hidden" name="EMPORIQA_SHOPS_FIELD" value="1" />
                                {foreach from=$emporiqa_shops item=shop}
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="EMPORIQA_ENABLED_SHOPS[]" value="{$shop.id|intval}"
                                                {if $shop.enabled}checked="checked"{/if} />
                                            {$shop.name|escape:'htmlall':'UTF-8'}
                                            <span class="text-muted">({l s='shown in Emporiqa as' mod='emporiqa'} &quot;{$shop.channel_key|escape:'htmlall':'UTF-8'}&quot;)</span>
                                        </label>
                                    </div>
                                {/foreach}
                                <p class="help-block">{l s='Ticked shops have their products and pages synced and show the chat. After changing this, click Save, then Sync All on the Sync tab.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        {/if}
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Languages' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                {foreach from=$emporiqa_languages item=lang}
                                    <label class="checkbox-inline">
                                        <input type="checkbox" name="EMPORIQA_ENABLED_LANGUAGES[]" value="{$lang.emporiqa_code|escape:'htmlall':'UTF-8'}"
                                            {if in_array($lang.emporiqa_code, $emporiqa_enabled_languages)}checked="checked"{/if} />
                                        {$lang.emporiqa_label|escape:'htmlall':'UTF-8'}
                                    </label>
                                {/foreach}
                                <p class="help-block">{l s="Languages whose product and page texts are sent to Emporiqa and whose pages show the chat. Pages in a language that is not ticked show no chat. After changing this, click Save, then Sync All on the Sync tab." mod='emporiqa'}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {* --- Ready-made rules: shown once Emporiqa has said this store has them --- *}
            {if $emporiqa_rules_available}
            <div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-rules">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-magic"></i> {l s='Ready-made rules' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <p>{l s='Rules tell the chat what to do when a shopper asks for something specific. The rule below reads your PrestaShop orders through this module. Click Open in Emporiqa to test it and switch it on there. Rules that do not need your shop, such as return requests, are added in Emporiqa under Settings > Rules.' mod='emporiqa'}</p>
                    {if $emporiqa_suggest_legacy_off}
                        <div class="alert alert-info">{l s='The Order status rule is on, so the old order tracking under Advanced is no longer needed. First remove its address in your Emporiqa dashboard (Settings > Integration > For your developer > Order tracking API URL), then set it to No and click Save.' mod='emporiqa'}</div>
                    {/if}
                    <div class="form-group">
                        <label>{l s='Order status address' mod='emporiqa'}</label>
                        <div class="emporiqa-copy-row">
                            <input type="text" value="{$emporiqa_action_url|escape:'htmlall':'UTF-8'}" class="form-control emporiqa-url-field" readonly />
                            <button type="button" class="btn btn-default emporiqa-copy-btn" data-url="{$emporiqa_action_url|escape:'htmlall':'UTF-8'}">
                                <i class="icon-copy"></i> {l s='Copy' mod='emporiqa'}
                            </button>
                        </div>
                        <p class="help-block">{l s='Copy this address into the Order status rule in Emporiqa.' mod='emporiqa'}</p>
                    </div>
                    <table class="table">
                        <tbody>
                            <tr>
                                <td>
                                    <strong>{l s='Order status' mod='emporiqa'}</strong><br>
                                    <span class="text-muted">{l s='Answers "Where is my order?" from your PrestaShop orders. A shopper signed in to your shop only gives the order reference; a guest also gives the email used for the order.' mod='emporiqa'}</span>
                                </td>
                                <td class="text-right emporiqa-rule-state">
                                    {if $emporiqa_order_status_live}
                                        <span class="label label-success">{l s='On' mod='emporiqa'}</span>
                                    {else}
                                        <span class="label label-default">{l s='Not added' mod='emporiqa'}</span>
                                    {/if}
                                    <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/rules/?add=order_status" target="_blank" rel="noopener noreferrer" class="btn btn-default">
                                        <i class="icon-external-link"></i> {l s='Open in Emporiqa' mod='emporiqa'}
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="help-block">{l s='On and Not added show what Emporiqa said at your last connect or Test connection (Sync tab). Run Test connection after changing a rule to refresh them.' mod='emporiqa'}</p>
                </div>
            </div>
            {else}
            {* --- Order tracking (the 1.2.8 one), visible where rules are not offered --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-open" id="emporiqa-section-order-tracking">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="true">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-truck"></i> {l s='Order tracking' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        {include file="./order_tracking_field.tpl"}
                    </div>
                </div>
            </div>
            {/if}

            {* --- Advanced --- *}
            <div class="emporiqa-collapsible-section emporiqa-section-closed" id="emporiqa-section-advanced">
                <div class="emporiqa-section-header" tabindex="0" role="button" aria-expanded="false">
                    <span class="emporiqa-section-toggle"></span>
                    <i class="icon-cog"></i> {l s='Advanced' mod='emporiqa'}
                </div>
                <div class="emporiqa-section-body">
                    <div class="form-wrapper">
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Auto-sync products' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <span class="switch prestashop-switch fixed-width-lg">
                                    <input type="radio" name="EMPORIQA_SYNC_PRODUCTS" id="EMPORIQA_SYNC_PRODUCTS_on" value="1" {if $emporiqa_sync_products}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PRODUCTS_on">{l s='Yes' mod='emporiqa'}</label>
                                    <input type="radio" name="EMPORIQA_SYNC_PRODUCTS" id="EMPORIQA_SYNC_PRODUCTS_off" value="0" {if !$emporiqa_sync_products}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PRODUCTS_off">{l s='No' mod='emporiqa'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Sends each product change to Emporiqa as soon as you save it. When this is off, Sync Products on the Sync tab is off too.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Auto-sync pages' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <span class="switch prestashop-switch fixed-width-lg">
                                    <input type="radio" name="EMPORIQA_SYNC_PAGES" id="EMPORIQA_SYNC_PAGES_on" value="1" {if $emporiqa_sync_pages}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PAGES_on">{l s='Yes' mod='emporiqa'}</label>
                                    <input type="radio" name="EMPORIQA_SYNC_PAGES" id="EMPORIQA_SYNC_PAGES_off" value="0" {if !$emporiqa_sync_pages}checked="checked"{/if} />
                                    <label for="EMPORIQA_SYNC_PAGES_off">{l s='No' mod='emporiqa'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Sends each CMS page change to Emporiqa as soon as you save it. When this is off, Sync Pages on the Sync tab is off too.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        {if $emporiqa_rules_available}{include file="./order_tracking_field.tpl"}{/if}
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Webhook URL' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="text" name="EMPORIQA_WEBHOOK_URL" value="{$emporiqa_webhook_url|escape:'htmlall':'UTF-8'}" class="form-control" />
                                <p class="help-block">{l s='The Emporiqa address your shop sends its data to. Leave it as it is unless Emporiqa support asks you to change it.' mod='emporiqa'}</p>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="control-label col-lg-3">{l s='Batch Size' mod='emporiqa'}</label>
                            <div class="col-lg-9">
                                <input type="number" name="EMPORIQA_BATCH_SIZE" value="{$emporiqa_batch_size|escape:'htmlall':'UTF-8'}" class="form-control fixed-width-sm" min="1" max="500" />
                                <p class="help-block">{l s='How many products or pages are sent per request during a sync from the Sync tab. Lower it if a sync stops with a timeout. Default 25, maximum 500.' mod='emporiqa'}</p>
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
    <div id="emporiqa-sync" class="emporiqa-tab-content {if $emporiqa_just_connected}active{/if}">
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
