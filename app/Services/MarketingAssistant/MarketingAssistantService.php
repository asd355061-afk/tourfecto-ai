<?php

/**
 * Tourfecto - Marketing Assistant Service
 * أدوات تسويقية سريعة (شعارات، عناوين إعلانات، أفكار حملات...) مبنية على
 * مكتبة برومبتات مأخوذة من الموديول الأصلي (ai-marketing-assistant)،
 * لكن مُنفّذة عبر GeminiClient الموحّد بدل AIClientFactory/OpenAIClient/
 * MockAIClient المنفصلين الأصليين.
 * @version 1.0.0
 */
class MarketingAssistantService
{
    /** @var GeminiClient */
    private $ai;

    /** قوالب برومبت لكل أداة - نفس الأفكار من PromptLibrary.php الأصلي */
    private const PROMPTS = [
        'ad_copy' => 'اكتب 3 نسخ مختلفة لإعلان قصير عن: "%s". كل نسخة بحد أقصى سطرين.',
        'slogan' => 'اقترح 5 شعارات تسويقية قصيرة وجذابة لـ: "%s".',
        'email_subject' => 'اكتب 5 عناوين بريد إلكتروني تسويقي جذابة عن: "%s".',
        'social_bio' => 'اكتب نبذة (bio) قصيرة احترافية لحساب سوشيال ميديا عن: "%s".',
        'product_description' => 'اكتب وصف منتج/خدمة تسويقي مقنع عن: "%s" في حدود 100 كلمة.',
        'campaign_ideas' => 'اقترح 5 أفكار حملة تسويقية إبداعية لـ: "%s".',
    ];

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    public function availableTools(): array
    {
        return array_keys(self::PROMPTS);
    }

    public function run(int $userId, string $type, string $input): AIAssistantInteraction
    {
        if (!isset(self::PROMPTS[$type])) {
            throw new InvalidArgumentException("أداة غير معروفة: {$type}");
        }

        $prompt = sprintf(self::PROMPTS[$type], $input);
        $response = $this->ai->generateContent($prompt, ['maxOutputTokens' => 1024]);

        $output = ($response['success'] ?? false)
            ? (string) ($response['data'] ?? '')
            : ('خطأ: ' . ($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'));

        $interaction = new AIAssistantInteraction([
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($input, 0, 100),
            'input_payload' => json_encode(['input' => $input], JSON_UNESCAPED_UNICODE),
            'output' => $output,
        ]);
        $interaction->save();

        ActivityLog::record('marketing_assistant', 'tool.used', [
            'user_id' => $userId, 'subject_type' => 'ai_assistant_interactions',
            'subject_id' => (int) $interaction->getAttribute('id'), 'meta' => ['type' => $type],
        ]);

        return $interaction;
    }
}
