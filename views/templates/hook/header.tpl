{*
 * Emporiqa - Widget and cart script injection for displayHeader hook.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{* Frame-ancestors policy: storefront pages can be embedded by the merchant's
   own origin (e.g. checkout flows in iframes) and by the Emporiqa widget
   surfaces — everywhere else is denied. *}
<meta http-equiv="Content-Security-Policy" content="frame-ancestors 'self' https://*.emporiqa.com">
<script>var emporiqa_cart_config = {ldelim}
  "ajax_url": "{$emporiqa_cart_ajax_url|escape:'javascript':'UTF-8'}",
  "token": "{$emporiqa_cart_token|escape:'javascript':'UTF-8'}",
  "checkout_url": "{$emporiqa_checkout_url|escape:'javascript':'UTF-8'}"
{rdelim};</script>
<script src="{$emporiqa_cart_handler_js|escape:'htmlall':'UTF-8'}"></script>
<script async crossorigin="anonymous" src="{$emporiqa_widget_url|escape:'htmlall':'UTF-8'}"></script>
