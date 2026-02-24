<?php
/**
 * Emporiqa Page Formatter
 *
 * Formats PrestaShop CMS page data into the consolidated payload
 * structure expected by the Emporiqa webhook API.
 * One event per page contains ALL languages in nested channel→language maps.
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
    /**
     * Format a CMS page for the webhook payload.
     * Returns consolidated payload with all languages included.
     *
     * @param CMS $cms The CMS page
     * @param string|null $syncSessionId Optional sync session ID
     *
     * @return array Formatted payload
     */
    public function format(CMS $cms, $syncSessionId = null)
    {
        $context = Context::getContext();
        $channel = (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL');

        $enabledLanguages = EmporiqaLanguageHelper::getEnabledLanguages();
        $langMap = EmporiqaLanguageHelper::getActiveLanguageMap();

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($enabledLanguages as $iso) {
            $langId = isset($langMap[$iso]) ? $langMap[$iso] : EmporiqaLanguageHelper::getLanguageIdByCode($iso);
            if (!$langId) {
                continue;
            }

            $title = is_array($cms->meta_title)
                ? ($cms->meta_title[$langId] ?? reset($cms->meta_title))
                : $cms->meta_title;
            $titles[$iso] = $title ?: '';

            $content = is_array($cms->content)
                ? ($cms->content[$langId] ?? reset($cms->content))
                : $cms->content;
            $contents[$iso] = $content ?: '';

            $links[$iso] = $context->link->getCMSLink($cms, null, null, $langId);
        }

        $data = [
            'identification_number' => 'page-' . (int) $cms->id,
            'channels' => [$channel],
            'titles' => [$channel => $titles],
            'contents' => [$channel => $contents],
            'links' => [$channel => $links],
        ];

        if ($syncSessionId) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }
}
