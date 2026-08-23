<?php

namespace App\Services\Core;

/**
 * Translation API Service
 * 
 * Integrates with external translation APIs for auto-translation.
 * Supports Google Translate, DeepL, and Microsoft Translator.
 */
class TranslationApiService
{
    private string $defaultProvider = 'google';
    private array $providers = ['google', 'deepl', 'microsoft'];
    private ?string $apiKey = null;

    public function __construct(?string $provider = null)
    {
        if ($provider && in_array($provider, $this->providers)) {
            $this->defaultProvider = $provider;
        }
        
        // Load API keys from environment
        $this->apiKey = $_ENV['TRANSLATION_API_KEY'] ?? null;
    }

    /**
     * Translate text from source to target language
     */
    public function translate(string $text, string $sourceLang, string $targetLang): ?string
    {
        if (empty(trim($text))) {
            return $text;
        }

        switch ($this->defaultProvider) {
            case 'deepl':
                return $this->translateWithDeepL($text, $sourceLang, $targetLang);
            case 'microsoft':
                return $this->translateWithMicrosoft($text, $sourceLang, $targetLang);
            case 'google':
            default:
                return $this->translateWithGoogle($text, $sourceLang, $targetLang);
        }
    }

    /**
     * Translate using Google Translate API
     */
    private function translateWithGoogle(string $text, string $sourceLang, string $targetLang): ?string
    {
        // Free endpoint (limited usage)
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl={$sourceLang}&tl={$targetLang}&dt=t&q=" . urlencode($text);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data[0])) {
            return null;
        }
        
        $translated = '';
        foreach ($data[0] as $chunk) {
            if (isset($chunk[0])) {
                $translated .= $chunk[0];
            }
        }
        
        return $translated ?: null;
    }

    /**
     * Translate using DeepL API
     */
    private function translateWithDeepL(string $text, string $sourceLang, string $targetLang): ?string
    {
        if (!$this->apiKey) {
            return null;
        }

        $url = 'https://api-free.deepl.com/v2/translate';
        
        $postData = http_build_query([
            'auth_key' => $this->apiKey,
            'text' => $text,
            'source_lang' => strtoupper($sourceLang),
            'target_lang' => strtoupper($targetLang),
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['translations'][0]['text'])) {
            return null;
        }
        
        return $data['translations'][0]['text'];
    }

    /**
     * Translate using Microsoft Translator API
     */
    private function translateWithMicrosoft(string $text, string $sourceLang, string $targetLang): ?string
    {
        if (!$this->apiKey) {
            return null;
        }

        $url = 'https://api.cognitive.microsofttranslator.com/translate';
        
        $params = [
            'api-version' => '3.0',
            'from' => $sourceLang,
            'to' => $targetLang,
        ];
        
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([['Text' => $text]]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->apiKey,
            'Content-Type: application/json',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data[0]['translations'][0]['text'])) {
            return null;
        }
        
        return $data[0]['translations'][0]['text'];
    }

    /**
     * Batch translate multiple texts
     */
    public function batchTranslate(array $texts, string $sourceLang, string $targetLang): array
    {
        $results = [];
        
        foreach ($texts as $key => $text) {
            $results[$key] = $this->translate($text, $sourceLang, $targetLang) ?? $text;
        }
        
        return $results;
    }

    /**
     * Detect language of text
     */
    public function detectLanguage(string $text): ?string
    {
        // Use Google's detect endpoint
        $url = "https://translate.googleapis.com/translate_a/t?client=gtx&sl=auto&tl=en&q=" . urlencode($text);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data[2]) && is_string($data[2])) {
            return $data[2];
        }
        
        return null;
    }

    /**
     * Get supported languages
     */
    public function getSupportedLanguages(): array
    {
        return [
            'af' => 'Afrikaans',
            'sq' => 'Albanian',
            'am' => 'Amharic',
            'ar' => 'Arabic',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'eu' => 'Basque',
            'be' => 'Belarusian',
            'bn' => 'Bengali',
            'bs' => 'Bosnian',
            'bg' => 'Bulgarian',
            'ca' => 'Catalan',
            'ceb' => 'Cebuano',
            'zh' => 'Chinese (Simplified)',
            'zh-TW' => 'Chinese (Traditional)',
            'co' => 'Corsican',
            'hr' => 'Croatian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'nl' => 'Dutch',
            'en' => 'English',
            'eo' => 'Esperanto',
            'et' => 'Estonian',
            'fi' => 'Finnish',
            'fr' => 'French',
            'fy' => 'Frisian',
            'gl' => 'Galician',
            'ka' => 'Georgian',
            'de' => 'German',
            'el' => 'Greek',
            'gu' => 'Gujarati',
            'ht' => 'Haitian Creole',
            'ha' => 'Hausa',
            'haw' => 'Hawaiian',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hmn' => 'Hmong',
            'hu' => 'Hungarian',
            'is' => 'Icelandic',
            'ig' => 'Igbo',
            'id' => 'Indonesian',
            'ga' => 'Irish',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'jv' => 'Javanese',
            'kn' => 'Kannada',
            'kk' => 'Kazakh',
            'km' => 'Khmer',
            'rw' => 'Kinyarwanda',
            'ko' => 'Korean',
            'ku' => 'Kurdish',
            'ky' => 'Kyrgyz',
            'lo' => 'Lao',
            'la' => 'Latin',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'lb' => 'Luxembourgish',
            'mk' => 'Macedonian',
            'mg' => 'Malagasy',
            'ms' => 'Malay',
            'ml' => 'Malayalam',
            'mt' => 'Maltese',
            'mi' => 'Maori',
            'mr' => 'Marathi',
            'mn' => 'Mongolian',
            'my' => 'Myanmar (Burmese)',
            'ne' => 'Nepali',
            'no' => 'Norwegian',
            'ny' => 'Nyanja (Chichewa)',
            'or' => 'Odia (Oriya)',
            'ps' => 'Pashto',
            'fa' => 'Persian',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'pa' => 'Punjabi',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'sm' => 'Samoan',
            'gd' => 'Scots Gaelic',
            'sr' => 'Serbian',
            'st' => 'Sesotho',
            'sn' => 'Shona',
            'sd' => 'Sindhi',
            'si' => 'Sinhala (Sinhalese)',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'so' => 'Somali',
            'es' => 'Spanish',
            'su' => 'Sundanese',
            'sw' => 'Swahili',
            'sv' => 'Swedish',
            'tl' => 'Tagalog (Filipino)',
            'tg' => 'Tajik',
            'ta' => 'Tamil',
            'tt' => 'Tatar',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tr' => 'Turkish',
            'tk' => 'Turkmen',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'ug' => 'Uyghur',
            'uz' => 'Uzbek',
            'vi' => 'Vietnamese',
            'cy' => 'Welsh',
            'xh' => 'Xhosa',
            'yi' => 'Yiddish',
            'yo' => 'Yoruba',
            'zu' => 'Zulu',
        ];
    }

    /**
     * Set API provider
     */
    public function setProvider(string $provider): bool
    {
        if (!in_array($provider, $this->providers)) {
            return false;
        }
        
        $this->defaultProvider = $provider;
        return true;
    }

    /**
     * Set API key
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Test API connection
     */
    public function testConnection(): bool
    {
        $testText = 'Hello';
        $result = $this->translate($testText, 'en', 'es');
        
        return $result !== null && !empty($result);
    }
}
