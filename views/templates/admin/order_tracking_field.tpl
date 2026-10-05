{*
 * The order tracking (1.2.8) setting of configure.tpl: under Order tracking,
 * or under Advanced where ready-made rules are offered.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 *}
<div class="form-group row">
    <label class="control-label col-lg-3">
        {if $emporiqa_rules_available}{l s='Old order tracking (deprecated)' mod='emporiqa'}{else}{l s='Order tracking' mod='emporiqa'}{/if}
    </label>
    <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
            <input type="radio" name="EMPORIQA_ORDER_TRACKING" id="EMPORIQA_ORDER_TRACKING_on" value="1" {if $emporiqa_order_tracking}checked="checked"{/if} />
            <label for="EMPORIQA_ORDER_TRACKING_on">{l s='Yes' mod='emporiqa'}</label>
            <input type="radio" name="EMPORIQA_ORDER_TRACKING" id="EMPORIQA_ORDER_TRACKING_off" value="0" {if !$emporiqa_order_tracking}checked="checked"{/if} />
            <label for="EMPORIQA_ORDER_TRACKING_off">{l s='No' mod='emporiqa'}</label>
            <a class="slide-button btn"></a>
        </span>
        <div class="emporiqa-copy-row">
            <input type="text" value="{$emporiqa_order_tracking_url|escape:'htmlall':'UTF-8'}" class="form-control emporiqa-url-field" readonly />
            <button type="button" id="emporiqa-copy-tracking-url" class="btn btn-default emporiqa-copy-btn" data-url="{$emporiqa_order_tracking_url|escape:'htmlall':'UTF-8'}">
                <i class="icon-copy"></i> {l s='Copy' mod='emporiqa'}
            </button>
        </div>
        {if $emporiqa_rules_available}
            <p class="help-block">{l s='Kept so that stores set up before version 1.3.0 keep answering order questions. The Order status rule under Ready-made rules replaces it. Once that rule is live, remove this address from your Emporiqa dashboard (Settings > Integration > For your developer > Order tracking API URL), then set this to No.' mod='emporiqa'}</p>
        {else}
            <p class="help-block">{l s='Lets the chat answer "Where is my order?" once the shopper gives the order reference and the email used for the order. Copy the address above into your' mod='emporiqa'}
                <a href="{$emporiqa_platform_base_url|escape:'htmlall':'UTF-8'}/platform/store-settings/?tab=integration#order-tracking" target="_blank" rel="noopener noreferrer">{l s='Emporiqa dashboard under Settings' mod='emporiqa'} &rarr; {l s='Integration' mod='emporiqa'} &rarr; {l s='Order tracking' mod='emporiqa'}</a>.
            </p>
        {/if}
    </div>
</div>
