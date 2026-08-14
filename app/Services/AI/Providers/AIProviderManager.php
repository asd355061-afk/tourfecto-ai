<?php
/**
 * Tourfecto - AI Chat Platform
 * AI Provider Manager - نقطة الدخول الوحيدة لتوليد ردود الذكاء الاصطناعي
 * في كل ميزات AI Chat. لا يجب على أي كود آخر استدعاء GeminiClient أو أي
 * مزود مباشرة؛ يجب المرور من هنا فقط (بند 20: AI Provider Abstraction).
 *
 * المسؤوليات:
 *   - بناء قائمة المزودين المتاحين والمهيّئين فقط.
 *   - تجربة المزود المفضّل، وعند الفشل تجربة التالي بالترتيب (Fallback).
 *   - تسجيل كل محاولة (نجاح/فشل) في ai_usage_logs (بند 21).
 *   - عدم كشف أي API Key للـ Frontend مطلقًا (كل الاستدعاءات من السيرفر فقط).
 *
 * الاستخدام:
 *   $manager = new AIProviderManager();
 *   $result = $manager->generateReply($systemPrompt, $messages, [
 *       'website_id' => $websiteId,
 *       'user_id' => $userId,
 *       'conversation_id' => $conversationId,
 *       'feature' => 'chat_reply',
 *   ]);
 *
 * @version 1.0.0
 */

class AIProviderManager {

    /** @var AIProviderInterface[] */
    private $providers = [];

    /** @var Database */
    private $db;

    /**
     * @param array $preferredOrder ترتيب مخصص اختياري لأسماء المزودين، مثال: ['openai', 'gemini']
     */
    public function __construct(array $preferredOrder = []) {
        $this->db = Database::getInstance();

        $registry = [
            'gemini' => function () { return new GeminiProvider(); },
            'openai' => function () { return new OpenAIProvider(); },
            'deepseek' => function () { return new DeepSeekProvider(); },
            'kimi' => function () { return new KimiProvider(); },
        ];

        $order = !empty($preferredOrder) ? $preferredOrder : $this->getDefaultOrder();

        foreach ($order as $providerName) {
            if (isset($registry[$providerName])) {
                $this->providers[] = $registry[$providerName]();
            }
        }
    }

    /**
     * الترتيب الافتراضي لمحاولة المزودين، قابل للتحكم عبر AI_PROVIDER_PRIORITY
     * في .env (مثال: "openai,gemini,deepseek,kimi"). Gemini أولاً افتراضيًا
     * لأنه المزود الوحيد المُفعّل فعليًا في هذا المشروع حاليًا.
     * @return array
     */
    private function getDefaultOrder(): array {
        $configured = defined('AI_PROVIDER_PRIORITY') ? AI_PROVIDER_PRIORITY : (env('AI_PROVIDER_PRIORITY') ?: '');
        if (!empty($configured)) {
            return array_map('trim', explode(',', $configured));
        }
        return ['gemini', 'openai', 'deepseek', 'kimi'];
    }

    /**
     * قائمة أسماء المزودين المهيّئين فعليًا (لهم API key صالح).
     * @return string[]
     */
    public function getConfiguredProviders(): array {
        $names = [];
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                $names[] = $provider->getName();
            }
        }
        return $names;
    }

    /**
     * توليد رد، مع تجربة المزودين بالترتيب حتى ينجح أحدهم (Fallback mechanism).
     *
     * @param string $systemPrompt
     * @param array $messages
     * @param array $context ['website_id','user_id','conversation_id','feature','temperature','max_tokens','model']
     * @return array نفس شكل AIProviderInterface::generateReply + 'fallback_used' => bool
     */
    public function generateReply(string $systemPrompt, array $messages, array $context = []): array {
        $feature = $context['feature'] ?? 'chat_reply';
        $attempted = [];
        $lastResult = null;

        $available = array_filter($this->providers, function ($p) {
            return $p->isConfigured();
        });

        if (empty($available)) {
            $error = 'No AI provider is configured. Add at least one API key (GEMINI_API_KEY, OPENAI_API_KEY, DEEPSEEK_API_KEY, or KIMI_API_KEY) in .env.';
            Logger::error('AIProviderManager: no configured provider', ['feature' => $feature]);
            return [
                'success' => false, 'content' => null, 'provider' => null, 'model' => null,
                'tokens_input' => 0, 'tokens_output' => 0, 'tokens_total' => 0,
                'estimated_cost_usd' => 0, 'duration_ms' => 0, 'error' => $error, 'fallback_used' => false,
            ];
        }

        foreach ($available as $index => $provider) {
            $result = $provider->generateReply($systemPrompt, $messages, $context);
            $attempted[] = $provider->getName();
            $lastResult = $result;

            $this->logUsage($result, $context, $result['success'] ? ($index > 0 ? 'fallback_used' : 'success') : 'failed');

            if ($result['success']) {
                $result['fallback_used'] = $index > 0;
                return $result;
            }

            Logger::warning('AIProviderManager: provider failed, trying next', [
                'provider' => $provider->getName(),
                'error' => $result['error'],
                'feature' => $feature,
            ]);
        }

        // كل المزودين فشلوا
        Logger::error('AIProviderManager: all providers failed', ['attempted' => $attempted, 'feature' => $feature]);
        $lastResult['fallback_used'] = count($attempted) > 1;
        return $lastResult;
    }

    /**
     * تسجيل محاولة استخدام في ai_usage_logs (بند 21).
     * @param array $result
     * @param array $context
     * @param string $status success|failed|fallback_used
     */
    private function logUsage(array $result, array $context, string $status): void {
        try {
            $log = new AiUsageLog();
            $log->fill([
                'website_id' => $context['website_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'conversation_id' => $context['conversation_id'] ?? null,
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? null,
                'feature' => $context['feature'] ?? 'chat_reply',
                'tokens_input' => $result['tokens_input'] ?? 0,
                'tokens_output' => $result['tokens_output'] ?? 0,
                'tokens_total' => $result['tokens_total'] ?? 0,
                'estimated_cost_usd' => $result['estimated_cost_usd'] ?? 0,
                'status' => $status === 'fallback_used' ? 'fallback_used' : ($result['success'] ? 'success' : 'failed'),
                'duration_ms' => $result['duration_ms'] ?? null,
                'error_message' => $result['error'] ?? null,
            ]);
            $log->save();
        } catch (Exception $e) {
            // فشل التسجيل نفسه لا يجب أبدًا أن يوقف رد الـAI للعميل
            Logger::warning('AIProviderManager: failed to write usage log', ['message' => $e->getMessage()]);
        }
    }
}
