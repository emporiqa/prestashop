{*
 * One "last full sync" line of the Sync tab (sync_tab.tpl).
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   AFL-3.0
 *}
{if $emporiqa_last}
    {if $emporiqa_last.ok}
        <span class="text-success">{l s='Last full sync: %s, complete.' sprintf=[$emporiqa_last.at] mod='emporiqa'}</span>
    {else}
        <span class="text-danger">{l s='Last full sync: %s, not completed. Run it again.' sprintf=[$emporiqa_last.at] mod='emporiqa'}</span>
    {/if}
{else}
    <span class="text-muted">{l s='No full sync from this page yet.' mod='emporiqa'}</span>
{/if}
