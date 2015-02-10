<?php

namespace Stevebauman\Translation;

use Stichoza\Google\GoogleTranslate;
use Stevebauman\Translation\Exceptions\InvalidLocaleCode;
use Stevebauman\Translation\Models\Locale as LocaleModel;
use Stevebauman\Translation\Models\LocaleTranslation as TranslationModel;
use Illuminate\Cache\CacheManager as Cache;
use Illuminate\Session\SessionManager as Session;
use Illuminate\Config\Repository as Config;

/**
 * Class Translation
 * @package Stevebauman\Translation
 */
class Translation {

    /**
     * Holds the default application locale
     *
     * @var string
     */
    protected $defaultLocale = '';

    /**
     * Holds the locale model
     *
     * @var LocaleModel
     */
    protected $localeModel;

    /**
     * Holds the translation model
     *
     * @var TranslationModel
     */
    protected $translationModel;

    /**
     * Holds the current cache instance
     *
     * @var Cache
     */
    protected $cache;

    /**
     * Holds the current session instance
     *
     * @var Session
     */
    protected $session;

    /**
     * Holds the current config instance
     *
     * @var Config
     */
    protected $config;

    /**
     * The sprintf format to retrieve a translation from the cache
     *
     * @var string
     */
    private $cacheTranslationStr = 'translation::%s.%s';

    /**
     * The sprintf format to retrieve a translation from the cache
     *
     * @var string
     */
    private $cacheLocaleStr = 'translation::%s';

    /**
     * The amount of time (minutes) to store the cached translations
     *
     * @var int
     */
    private $cacheTime = 30;

    /**
     * @param Config $config
     * @param Session $session
     * @param Cache $cache
     * @param LocaleModel $localeModel
     * @param TranslationModel $translationModel
     */
    public function __construct(
        Config $config,
        Session $session,
        Cache $cache,
        LocaleModel $localeModel,
        TranslationModel $translationModel)
    {
        $this->config = $config;
        $this->session = $session;
        $this->cache = $cache;

        $this->localeModel = $localeModel;
        $this->translationModel = $translationModel;

        $this->setDefaultLocale($this->getAppLocale());
    }

    /**
     * Returns the translation for the current locale
     *
     * @param string $text
     * @param array $data
     */
    public function translate($text = '', $data = array())
    {
        $defaultTranslation = $this->getDefaultTranslation($text);

        $toLocale = $this->firstOrCreateLocale($this->getLocale());

        $translation = $this->findTranslationByLocaleIdAndParentId($toLocale->id, $defaultTranslation->id);

        if($translation)
        {

            return $translation->translation;

        } else {

            /*
             * If the default translation locale doesn't equal the locale to translate to,
             * we'll create a new translation record with the default
             * translation text and return the default translation text
             */
            if($defaultTranslation->locale_id != $toLocale->id) {

                $translation = $this->firstOrCreateTranslation($toLocale, $defaultTranslation->translation, $defaultTranslation);

                return $translation->translation;

            }

            return $defaultTranslation->translation;
        }
    }

    /**
     * Retrieves the current app's default locale
     *
     * @return string
     */
    public function getAppLocale()
    {
        return $this->config->get('app.locale');
    }

    /**
     * Retrieves the default locale property
     */
    public function getDefaultLocale()
    {
        return $this->defaultLocale;
    }

    /**
     * Retrieves the current locale from the session. If a locale isn't set then the default app locale
     * is set as the current locale
     *
     * @return string
     */
    public function getLocale()
    {
        $locale = $this->session->get('locale');

        if($locale)
        {

            return $locale;

        } else {

            /*
             * First session
             */
            $this->setLocale($this->getDefaultLocale());

            return $this->getLocale();
        }
    }

    /**
     * Sets the default locale property
     *
     * @param string $code
     */
    public function setDefaultLocale($code = '')
    {
        $this->defaultLocale = $code;
    }

    /**
     * Sets the current locale in the session
     *
     * @param string $code
     */
    public function setLocale($code = '')
    {
        $this->session->set('locale', $code);
    }

    /**
     * Returns the translation by the specified text and the applications
     * default locale
     *
     * @param string $text
     * @return Translation
     */
    public function getDefaultTranslation($text)
    {
        $locale = $this->firstOrCreateLocale($this->getDefaultLocale());

        return $this->firstOrCreateTranslation($locale, $text);
    }

    /**
     * Retrieves or creates a locale from the specified code
     *
     * @param string $code
     * @return static
     */
    private function firstOrCreateLocale($code)
    {
        $cachedLocale = $this->getCacheLocale($code);

        if($cachedLocale) return $cachedLocale;

        $name = $this->getConfigLocaleByCode($code);

        $locale = $this->localeModel->firstOrCreate(array(
            'code' => $code,
            'name' => $name,
        ));

        $this->setCacheLocale($locale);

        return $locale;
    }

    /**
     * Returns the translation from the parent records
     *
     * @param $localeId
     * @param $parentId
     * @return mixed
     */
    private function findTranslationByLocaleIdAndParentId($localeId, $parentId)
    {
        return $this->translationModel
            ->remember(1)
            ->where('locale_id', $localeId)
            ->where('translation_id', $parentId)->first();
    }

    /**
     * Creates a translation
     *
     * @param Translation $locale
     * @param string $text
     * @param Translation $parentTranslation
     * @return static
     */
    private function firstOrCreateTranslation($locale, $text, $parentTranslation = NULL)
    {
        /*
         * We'll check to see if there's a cached translation first before we try
         * and hit the database
         */
        $cachedTranslation = $this->getCacheTranslation($locale, $text);

        if($cachedTranslation)
        {
            return $cachedTranslation;
        }

        /*
         * Check if auto translation is enabled, if so we'll run the text through google
         * translate and save the text.
         */
        if($parentTranslation && $this->autoTranslateEnabled())
        {
            $googleTranslate = new GoogleTranslate;

            $googleTranslate->setLangFrom($parentTranslation->locale->code);
            $googleTranslate->setLangTo($locale->code);

            $text = $googleTranslate->translate($text);

            if($this->autoTranslateUcfirstEnabled())
            {
                $text = ucfirst($text);
            }

            $parentId = $parentTranslation->id;
        }

        $translation = $this->translationModel->firstOrCreate(array(
            'locale_id' => $locale->id,
            'translation_id' => (isset($parentTranslation) ? $parentTranslation->id  : NULL),
            'translation' => $text,
        ));

        /*
         * Cache the translation so it's retrieved faster next time
         */
        $this->setCacheTranslation($translation);

        return $translation;
    }

    /**
     * Sets a cache key to the specified locale and text
     *
     * @param $translation
     */
    private function setCacheTranslation($translation)
    {
        $id = $this->getTranslationCacheId($translation->locale, $translation->translation);

        if(!$this->cache->has($id))
        {
            $this->cache->put($id, $translation, $this->cacheTime);
        }
    }

    /**
     * Retrieves the cached translation from the specified locale
     * and text
     *
     * @param $locale
     * @param $text
     * @return bool|string
     */
    private function getCacheTranslation($locale, $text)
    {
        $id = $this->getTranslationCacheId($locale, $text);

        $cachedTranslation = $this->cache->get($id);

        if($cachedTranslation)
        {
            return $cachedTranslation;

        } else {

            return false;
        }
    }

    /**
     * Sets a cache key to the specified locale
     *
     * @param $locale
     */
    private function setCacheLocale($locale)
    {
        if(!$this->cache->has($locale->code))
        {
            $id = sprintf($this->cacheLocaleStr, $locale->code);

            $this->cache->put($id, $locale, $this->cacheTime);
        }
    }

    /**
     * Retrieves a cached locale from the specified locale code
     *
     * @param $code
     * @return bool
     */
    private function getCacheLocale($code)
    {
        $id = sprintf($this->cacheLocaleStr, $code);

        $cachedLocale = $this->cache->get($id);

        if($cachedLocale)
        {
            return $cachedLocale;
        } else
        {
            return false;
        }
    }

    /**
     * Returns a unique translation code by compressing the text
     * using a PHP compression function
     *
     * @param $locale
     * @param $text
     * @return string
     */
    private function getTranslationCacheId($locale, $text)
    {
        $compressed = $this->compressString($text);

        return sprintf($this->cacheTranslationStr, $locale->code, $compressed);
    }

    /**
     * Returns a the english name of the locale code entered from the config file
     *
     * @param $code
     * @return mixed
     * @throws InvalidLocaleCode
     */
    private function getConfigLocaleByCode($code)
    {
        if(array_key_exists($code, $this->config->get('translation::locales')))
        {
            return $this->config->get('translation::locales')[$code];
        } else
        {
            $message = sprintf('Locale Code: %s is invalid, please make sure it is available in the configuration file', $code);

            throw new InvalidLocaleCode($message);
        }
    }

    /**
     * Returns the auto translate configuration option
     *
     * @return mixed
     */
    private function autoTranslateEnabled()
    {
        return $this->config->get('translation::auto_translate');
    }

    /**
     * Returns the auto translate ucfirst configuration option
     *
     * @return mixed
     */
    private function autoTranslateUcfirstEnabled()
    {
        return $this->config->get('translation::auto_translate_ucfirst');
    }

    /**
     * Compresses a string. Used for storing cache keys for translations
     *
     * @param $string
     * @return string
     */
    private function compressString($string)
    {
        return gzcompress($string);
    }

}