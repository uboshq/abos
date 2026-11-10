<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

use App\Core\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ⭐ ভাষার মডেলের সাথে সংযোগ — NEXUS-এর সার্ভার (অফিসের Mac mini; পূর্ণ পরিকল্পনা অংশ ৪, ১৩)।
 *
 * ⓘ মডেল কেবল প্রশ্ন বোঝে: প্রশ্ন + এই মানুষের দরজাগুলোর নাম ও বর্ণনা যায়, ফেরে "কোন দরজা, কী মান"। সংখ্যা মডেল বানায় না —
 *   দরজা ABOS-এর নিজের হিসাব থেকে আনে।
 * ⓘ ঠিকানা কন্ট্রোল প্যানেলে (`assistant.model_url`) — VPS-এ সরাতে কেবল ঠিকানা বদল; চাবি .env-এ (ASSISTANT_MODEL_KEY), পর্দায় নয়।
 * ⓘ ঠিকানা খালি, Mac বন্ধ, সময় পেরোল বা উত্তর ভাঙা — null; তখন নিজের শব্দ-তালিকা ([[WordMatcher]])।
 * ⛔ যা যায় তা কেবল প্রশ্ন, পাতা আর দরজার তালিকা — খাতার সারি, মোবাইল, NID যায় না (মোবাইল আর NID-এর মতো অঙ্ক ঢাকা হয়, [[mask()]])।
 */
final class ModelClient
{
    public const TIMEOUT_SECONDS = 20;

    /** ⓘ চুক্তির পথ — NEXUS-এর সাথে ঠিক করা এক রূপ, দুই ERP */
    public const PATH = '/v1/assistant/interpret';

    public function __construct(private readonly SettingsService $settings) {}

    public function configured(): bool
    {
        return $this->url() !== '';
    }

    /**
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>, required: list<string>}>  $tools
     * @return array{tool: ?string, args: array<string, mixed>, model: string, ms: int}|null
     */
    public function interpret(string $question, string $locale, ?string $page, array $tools): ?array
    {
        if (! $this->configured() || $tools === []) {
            return null;
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()
                ->withToken((string) config('services.assistant.key', ''))
                ->post($this->url().self::PATH, [
                    'question' => self::mask($question),
                    'locale' => $locale,
                    'page' => $page,
                    'tools' => $tools,
                    'client' => 'abos',
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $tool = $response->json('tool');
        $args = $response->json('args');

        return [
            'tool' => is_string($tool) && $tool !== '' ? $tool : null,
            'args' => is_array($args) ? $args : [],
            'model' => mb_substr((string) $response->json('model', 'nexus'), 0, 60),
            'ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /** ⓘ ১০ বা তার বেশি অঙ্কের সারি (মোবাইল, NID, হিসাব নম্বর) ঢাকা — মডেলের দরকার নেই */
    public static function mask(string $text): string
    {
        return (string) preg_replace('/[0-9০-৯]{10,}/u', '••••', $text);
    }

    private function url(): string
    {
        return rtrim(trim((string) $this->settings->get('assistant.model_url', '')), '/');
    }
}
