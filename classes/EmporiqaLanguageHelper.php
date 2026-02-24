<?php
/**
 * Emporiqa Language Helper
 *
 * Multi-language utilities for mapping PrestaShop languages
 * to Emporiqa-compatible ISO codes.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaLanguageHelper
{
    /**
     * Get the list of enabled language codes from module config.
     * Uses locale (e.g. 'en-US') for uniqueness; falls back to iso_code for legacy configs.
     *
     * @return array e.g. ['en-US', 'de-DE']
     */
    public static function getEnabledLanguages()
    {
        $json = Configuration::get('EMPORIQA_ENABLED_LANGUAGES');
        $languages = json_decode($json, true);

        if (!is_array($languages) || empty($languages)) {
            $defaultLang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
            if (Validate::isLoadedObject($defaultLang)) {
                return [self::getLangCode($defaultLang)];
            }

            return ['en'];
        }

        return $languages;
    }

    /**
     * Get the language code to use for a PS language.
     * Prefers locale (en-US) over iso_code (en) for uniqueness.
     *
     * @param array|Language $lang Language row or object
     *
     * @return string
     */
    public static function getLangCode($lang)
    {
        if (is_array($lang)) {
            return !empty($lang['locale']) ? $lang['locale'] : ($lang['iso_code'] ?? 'en');
        }

        return !empty($lang->locale) ? $lang->locale : $lang->iso_code;
    }

    /**
     * Get PrestaShop language ID from a language code (locale or iso_code).
     *
     * @param string $code e.g. 'en-US', 'de-DE', or legacy 'en', 'de'
     *
     * @return int|false Language ID or false if not found
     */
    public static function getLanguageIdByCode($code)
    {
        $languages = Language::getLanguages(true);
        foreach ($languages as $lang) {
            if (self::getLangCode($lang) === $code) {
                return (int) $lang['id_lang'];
            }
        }
        // Fallback: try iso_code match for legacy 2-letter codes
        $langId = (int) Language::getIdByIso($code);

        return $langId > 0 ? $langId : false;
    }

    /**
     * Get all active PrestaShop languages as code => ID mapping.
     *
     * @return array e.g. ['en-US' => 1, 'de-DE' => 2]
     */
    public static function getActiveLanguageMap()
    {
        $languages = Language::getLanguages(true);
        $map = [];

        foreach ($languages as $lang) {
            $map[self::getLangCode($lang)] = (int) $lang['id_lang'];
        }

        return $map;
    }

    /**
     * Get shop base URL.
     *
     * @return string Shop base URL
     */
    public static function getShopBaseUrl()
    {
        $context = Context::getContext();
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
        $base = $context->shop->getBaseURL($ssl);

        return rtrim($base, '/');
    }
}
