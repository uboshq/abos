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

    /** ⓘ সার্ভারের ধরন — NEXUS-এর পুরনো চুক্তি, বা OpenAI-ধাঁচের চ্যাট (NEXUS-এর গেটওয়ে / Ollama; `assistant.model_kind`) */
    public const NEXUS = 'nexus';

    public const CHAT = 'chat';

    public const CHAT_PATH = '/v1/chat/completions';

    /** ⓘ Mac mini-তে বসানো মডেল (১২ নভেম্বর ২০২৬) — সেটিং খালি হলে এটাই */
    public const DEFAULT_MODEL = 'qwen3:4b-instruct';

    public const EMBED_PATH = '/v1/embeddings';

    /** ⓘ মানে-ছাপের মডেল — বাংলা আর ইংরেজি এক জায়গায় (১৩ নভেম্বর ২০২৬) */
    public const DEFAULT_EMBED_MODEL = 'bge-m3:latest';

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

        if ($this->kind() === self::CHAT) {
            return $this->viaChat($question, $locale, $page, $tools, $start);
        }

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

    /**
     * ⭐ OpenAI-ধাঁচের চ্যাট (`/v1/chat/completions`, দরজাগুলো `tools`-এ) — NEXUS-এর গেটওয়ে (nexus-60, ১২ নভেম্বর ২০২৬: Mac mini-র
     *   মডেলের সামনে চাবিওয়ালা Cloudflare Tunnel) আর Ollama নিজেও একই রূপ বোঝে।
     *
     * ⓘ দরজার নামে বিন্দু চলে না (`^[a-zA-Z0-9_-]+$`) — `sales.summary` যায় `sales__summary` হয়ে, ফেরার পথে আবার বিন্দু।
     * ⓘ তাপমাত্রা ০ — একই প্রশ্নে একই দরজা; মডেল দরজা না বাছলে null, তখন নিজের শব্দ-তালিকা ([[WordMatcher]])।
     * ⛔ তালিকার বাইরের নাম বললে দরজা নেই — আন্দাজে কিছু খোলে না; চাবি তবু [[AssistantEngine]]-এ আবার দেখা।
     *
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>, required: list<string>}>  $tools
     * @return array{tool: ?string, args: array<string, mixed>, model: string, ms: int}|null
     */
    private function viaChat(string $question, string $locale, ?string $page, array $tools, float $start): ?array
    {
        $wire = [];

        foreach ($tools as $t) {
            $wire[self::wireName($t['name'])] = $t['name'];
        }

        $system = 'You help a user of a business ERP. Call exactly one of the given tools when it answers the question; '
            .'if none fits, answer with no tool call. Keep product and party names exactly as written. '
            .'The question is in '.($locale === 'bn' ? 'Bengali' : 'English').'.'
            .($page !== null && $page !== '' ? ' The user is on the ERP page "'.$page.'".' : '');
        $name = $this->modelName();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()
                ->withToken((string) config('services.assistant.key', ''))
                ->post($this->url().self::CHAT_PATH, [
                    'model' => $name,
                    'stream' => false,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => self::mask($question)],
                    ],
                    'tools' => array_map(fn (array $t): array => ['type' => 'function', 'function' => [
                        'name' => self::wireName($t['name']),
                        'description' => $t['description'],
                        'parameters' => ['type' => 'object', 'properties' => $t['parameters'] === [] ? new \stdClass : $t['parameters'], 'required' => $t['required']],
                    ]], $tools),
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $call = $response->json('choices.0.message.tool_calls.0.function');
        $tool = is_array($call) ? ($wire[(string) ($call['name'] ?? '')] ?? null) : null;
        $raw = is_array($call) ? ($call['arguments'] ?? null) : null;
        $args = is_string($raw) ? json_decode($raw, true) : $raw;

        return [
            'tool' => $tool,
            'args' => $tool !== null && is_array($args) ? $args : [],
            'model' => mb_substr((string) ($response->json('model') ?: $name), 0, 60),
            'ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * ⭐ মানে-ছাপ (embedding) — একই গেটওয়ের `/v1/embeddings`, মডেল `assistant.embed_model` (Mac mini-তে bge-m3, বাংলা আর ইংরেজি এক জায়গায়;
     *   ১৩ নভেম্বর ২০২৬)। ⓘ কেবল চ্যাট-ধরনের গেটওয়েতে; ঠিকানা খালি, বন্ধ বা ভাঙা উত্তর — null, তখন শব্দের মিলই শেষ কথা।
     * ⛔ যায় কেবল পণ্যের নাম বা প্রশ্নের পণ্য-শব্দ — লম্বা অঙ্কের সারি ঢাকা ([[mask()]])।
     *
     * @param  list<string>  $texts
     * @return list<list<float>>|null
     */
    public function embed(array $texts): ?array
    {
        if (! $this->configured() || $this->kind() !== self::CHAT || $texts === []) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()
                ->withToken((string) config('services.assistant.key', ''))
                ->withUserAgent('abos-erp/1.0')
                ->post($this->url().self::EMBED_PATH, [
                    'model' => $this->embedModel(),
                    'input' => array_map(fn (string $t): string => self::mask($t), array_values($texts)),
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $rows = $response->successful() ? $response->json('data') : null;

        if (! is_array($rows) || count($rows) !== count($texts)) {
            return null;
        }

        $out = [];

        foreach ($rows as $row) {
            $vector = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($vector) || $vector === []) {
                return null;
            }

            $out[] = array_map('floatval', array_values($vector));
        }

        return $out;
    }

    public function embedModel(): string
    {
        $name = trim((string) $this->settings->get('assistant.embed_model', self::DEFAULT_EMBED_MODEL));

        return $name !== '' ? $name : self::DEFAULT_EMBED_MODEL;
    }

    /** ⓘ `sales.summary` → `sales__summary` — OpenAI-র দরজার নামে বিন্দু চলে না */
    public static function wireName(string $name): string
    {
        return str_replace('.', '__', $name);
    }

    private function kind(): string
    {
        return (string) $this->settings->get('assistant.model_kind', self::NEXUS) === self::CHAT ? self::CHAT : self::NEXUS;
    }

    private function modelName(): string
    {
        $name = trim((string) $this->settings->get('assistant.model_name', self::DEFAULT_MODEL));

        return $name !== '' ? $name : self::DEFAULT_MODEL;
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
