<?php
/**
 * Emporiqa Page Formatter
 *
 * Formats PrestaShop CMS page data into the consolidated payload
 * structure expected by the Emporiqa webhook API.
 * One event per page contains ALL channels and ALL languages
 * in nested channel→language maps.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaPageFormatter
{
    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    public function __construct(EmporiqaChannelResolver $channelResolver)
    {
        $this->channelResolver = $channelResolver;
    }

    /**
     * Format a CMS page for the webhook payload.
     * Includes all assigned channels with per-channel data.
     *
     * @param CMS $cms The CMS page
     * @param string|null $syncSessionId Optional sync session ID
     *
     * @return array Formatted payload
     */
    public function format(CMS $cms, $syncSessionId = null)
    {
        $cmsId = (int) $cms->id;

        $allContexts = $this->channelResolver->getShopContexts();
        $pageChannels = $this->channelResolver->getPageChannels($cmsId);

        if (empty($pageChannels)) {
            return [];
        }

        $contexts = [];
        foreach ($allContexts as $channelKey => $ctx) {
            if (in_array($channelKey, $pageChannels, true)) {
                $contexts[$channelKey] = $ctx;
            }
        }

        if (empty($contexts)) {
            return [];
        }

        $channelKeys = [];
        $allTitles = [];
        $allContents = [];
        $allLinks = [];

        foreach ($contexts as $channelKey => $ctx) {
            $channelKeys[] = $channelKey;
            $shopId = $ctx['shop_id'];

            // Reload the CMS scoped to this shop so translation arrays
            // populate even when the admin AJAX is running under a different
            // shop context (the passed-in $cms is loaded against the current
            // request's shop, which may have no rows in ps_cms_lang).
            $shopCms = new CMS($cmsId, null, $shopId);
            if (!Validate::isLoadedObject($shopCms)) {
                continue;
            }

            $titles = [];
            $contents = [];
            $links = [];

            $shopLink = new Link(null, null);

            foreach ($ctx['enabled_languages'] as $iso) {
                $langId = isset($ctx['languages'][$iso]) ? $ctx['languages'][$iso] : null;
                if (!$langId) {
                    continue;
                }

                $title = is_array($shopCms->meta_title)
                    ? ($shopCms->meta_title[$langId] ?? reset($shopCms->meta_title))
                    : $shopCms->meta_title;
                $titles[$iso] = $title ?: '';

                $content = is_array($shopCms->content)
                    ? ($shopCms->content[$langId] ?? reset($shopCms->content))
                    : $shopCms->content;
                $contents[$iso] = $content ?: '';

                $rewrite = is_array($shopCms->link_rewrite) ? ($shopCms->link_rewrite[$langId] ?? null) : $shopCms->link_rewrite;
                if (empty($rewrite)) {
                    continue;
                }
                $links[$iso] = $shopLink->getCMSLink($shopCms, null, null, $langId, $shopId);
            }

            // Empty PHP arrays serialize as JSON []; the API expects {} (dict).
            $allTitles[$channelKey] = !empty($titles) ? $titles : new stdClass();
            $allContents[$channelKey] = !empty($contents) ? $contents : new stdClass();
            $allLinks[$channelKey] = !empty($links) ? $links : new stdClass();
        }

        $data = [
            'identification_number' => 'page-' . $cmsId,
            'channels' => $channelKeys,
            'titles' => $allTitles,
            'contents' => $allContents,
            'links' => $allLinks,
        ];

        if ($syncSessionId) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }
}
