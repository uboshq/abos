<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AssistantEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ⭐ কোপাইলটের ইঞ্জিন (assistant) — নাম, কোন অংশ চালু, আর খাতা (মালিকের "ok", পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2)।
 *
 * ⓘ ইঞ্জিন কোনো মডিউলের নাম জানে না — নজরের উৎস, প্রস্তাব আর খসড়া কোপাইলটের মডিউল (app/Modules/Assistant) সাজায়।
 * ⛔ এখানে কোনো সংখ্যা গোনা হয় না।
 */
final class AssistantEngine
{
    /** কোপাইলটের পাঁচ অংশ — প্রতিটা কোম্পানি ধরে আলাদা চালু/বন্ধ */
    public const PARTS = ['watch', 'brief', 'suggest', 'draft', 'ask'];

    /** ⓘ একই মানুষ টাইপ চালিয়ে গেলে এর মধ্যে একই খোঁজার সারি — প্রতিটা অক্ষরে নতুন সারি নয় */
    public const SEARCH_MERGE_SECONDS = 20;

    /** ⓘ একই পাতা এর মধ্যে আবার খুললে একই সারি */
    public const VISIT_MERGE_SECONDS = 60;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * ⭐ প্রশ্নের উত্তর (পূর্ণ পরিকল্পনা অংশ ২, ধাপ ৪) — মডেল বোঝে, ABOS-এর দরজা গোনে।
     *
     * ধাপ: অংশ চালু? → দিনের সীমা → মডেল (NEXUS-এর সার্ভার) "কোন দরজা, কী মান" বলে; না পেলে নিজের শব্দ-তালিকা ([[WordMatcher]]) →
     * দরজা খোলা — চাবি আবার দেখা হয় ([[ToolRegistry::call()]]) → খাতায় প্রশ্ন, উত্তর, কোন দরজা কোন মানে, কোন মডেল, কত সময়।
     * ⓘ ফল একটা অবস্থা-চিহ্ন (off, limit, not_understood, blocked, answered) — কথাগুলো কোপাইলটের মডিউল বলে, কোর নয়।
     *
     * @return array{state: string, tool: ?string, result: ?array<string, mixed>, model: ?string}
     */
    public function ask(User $user, string $question, ?string $page = null): array
    {
        $question = trim(mb_substr($question, 0, 500));
        $none = ['tool' => null, 'result' => null, 'model' => null];

        if (! $this->on('ask')) {
            return ['state' => 'off'] + $none;
        }

        $limit = max(1, (int) $this->settings->get('assistant.daily_questions', 200));
        $today = AssistantEvent::query()->where('kind', AssistantEvent::QUESTION)->where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::today())->count();

        if ($today >= $limit) {
            $this->record(AssistantEvent::BLOCKED, $user, $question, ['page' => $page, 'outcome' => 'daily_limit']);

            return ['state' => 'limit'] + $none;
        }

        $registry = app(ToolRegistry::class);
        $mine = $registry->for($user);
        $start = microtime(true);
        $picked = app(ModelClient::class)->interpret($question, app()->getLocale(), $page, $registry->describeFor($user, app()->getLocale()));
        $model = $picked['model'] ?? null;

        if ($picked === null || $picked['tool'] === null) {
            $words = app(WordMatcher::class)->match($question, $mine);
            $picked = $words === null ? null : $words + ['model' => 'words'];
            $model = $words === null ? $model : 'words';
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($picked === null) {
            $this->record(AssistantEvent::QUESTION, $user, $question, ['page' => $page, 'model' => $model, 'ms' => $ms, 'outcome' => 'not_understood']);

            return ['state' => 'not_understood', 'tool' => null, 'result' => null, 'model' => $model];
        }

        $tools = [['name' => $picked['tool'], 'args' => $picked['args']]];
        $result = $registry->call($user, (string) $picked['tool'], (array) $picked['args']);

        if ($result === null) {
            $this->record(AssistantEvent::BLOCKED, $user, $question, ['page' => $page, 'model' => $model, 'ms' => $ms, 'outcome' => 'no_key:'.$picked['tool']], $tools);

            return ['state' => 'blocked', 'tool' => (string) $picked['tool'], 'result' => null, 'model' => $model];
        }

        $this->record(AssistantEvent::QUESTION, $user, $question, [
            'page' => $page, 'model' => $model, 'ms' => (int) round((microtime(true) - $start) * 1000),
            'outcome' => 'answered', 'answer' => mb_substr((string) $result['summary'], 0, 2000),
        ], $tools);

        return ['state' => 'answered', 'tool' => (string) $picked['tool'], 'result' => $result, 'model' => $model];
    }

    /** ⭐ 👍 👎 — নিজের প্রশ্নের উত্তরে; ভুল ধরতে (পূর্ণ পরিকল্পনা অংশ ৮) */
    public function feedback(User $user, AssistantEvent $answer, int $value): void
    {
        abort_unless($answer->kind === AssistantEvent::QUESTION && (int) $answer->user_id === (int) $user->id, 404);

        $answer->forceFill(['feedback' => $value > 0 ? 1 : -1])->save();
    }

    /**
     * ⭐ পর্দায় যে নাম — কন্ট্রোল প্যানেল থেকে, ভাষা ধরে (মালিক, ১০ নভেম্বর ২০২৬: "নাম সেটিং থেকে বদলাবে, কোড লাগবে না")।
     * ⓘ খালি রাখলে "Copilot"।
     */
    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = trim((string) $this->settings->get($locale === 'en' ? 'assistant.name_en' : 'assistant.name_bn', 'Copilot'));

        return $name === '' ? 'Copilot' : mb_substr($name, 0, 40);
    }

    public function on(string $part): bool
    {
        return in_array($part, self::PARTS, true) && (bool) $this->settings->get('assistant.'.$part, true);
    }

    /** ⓘ কোপাইলট কি এই মানুষের জন্য — চাবি আছে আর কোম্পানিতে মডিউল চালু */
    public function availableTo(?User $user): bool
    {
        return $user !== null && $user->can('assistant.use');
    }

    /**
     * ⭐ খাতায় এক সারি।
     *
     * @param  array<int, array<string, mixed>>|null  $tools
     */
    public function record(string $kind, ?User $user, ?string $text = null, array $extra = [], ?array $tools = null): AssistantEvent
    {
        return AssistantEvent::query()->create([
            'company_id' => CompanyContext::id(),
            'user_id' => $user?->id,
            'kind' => $kind,
            'text' => $text === null ? null : mb_substr($text, 0, 2000),
            'tools' => $tools,
        ] + array_intersect_key($extra, array_flip(['module', 'page', 'answer', 'model', 'ms', 'outcome', 'feedback', 'reason'])) + self::where());
    }

    /**
     * ⭐ কোথা থেকে, কীভাবে — শাখা, ওয়েব না অ্যাপ, IP, ব্রাউজার বা ফোন (মালিক, ১১ নভেম্বর ২০২৬: "কার আইডি থেকে কখন, কোথায়, কীভাবে,
     * কোন জায়গা থেকে")। ⓘ রাতের কাজ (কমান্ড) হলে "system", IP নেই।
     *
     * @return array{branch_id: ?int, channel: string, ip_address: ?string, user_agent: ?string}
     */
    public static function where(): array
    {
        // ⓘ আসল অনুরোধ মানে রুটসহ অনুরোধ — কমান্ডে (রাতের কাজ) রুট থাকে না; পরীক্ষায় অনুরোধ আসল, তাই runningInConsole() নয়
        $request = app()->bound('request') && request()->route() !== null ? request() : null;

        return [
            'branch_id' => CompanyContext::branchId(),
            'channel' => $request === null ? 'system' : ($request->is('api/*') ? 'app' : 'web'),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
        ];
    }

    /**
     * ⭐ কে কোন পাতা খুলল বা কী নামাল — একই মানুষ একই পাতা [[VISIT_MERGE_SECONDS]]-এর মধ্যে আবার খুললে নতুন সারি নয়
     * (পাতা নতুন করে আনা, পেছনে ফেরা), সময়টা কেবল এগোয় ([[RecordsVisits]])।
     */
    public function recordVisit(User $user, string $path, string $label): void
    {
        if (CompanyContext::id() === null) {
            return;
        }

        $path = mb_substr($path, 0, 191);
        $same = AssistantEvent::query()->where('kind', AssistantEvent::VISIT)->where('user_id', $user->id)->where('page', $path)
            ->where('updated_at', '>=', Carbon::now()->subSeconds(self::VISIT_MERGE_SECONDS))->latest('id')->first();

        if ($same !== null) {
            $same->touch();

            return;
        }

        $this->record(AssistantEvent::VISIT, $user, mb_substr($label, 0, 191), ['page' => $path]);
    }

    /**
     * ⭐ উপরের খোঁজার ঘর — কে কী খুঁজল (মালিক, ১০ নভেম্বর ২০২৬: "ke ki search korlo … control penel e alada vabe")।
     *
     * ⓘ ঘরটা প্রতিটা অক্ষরে সার্ভারে আসে; একই মানুষ [[SEARCH_MERGE_SECONDS]]-এর মধ্যে আগের লেখা বাড়িয়ে বা কমিয়ে টাইপ করলে আগের
     *   সারিটাই শেষ লেখায় বদলায় — খাতায় "র", "রহ", "রহি" নয়, "রহিম ট্রেডার্স" একবার।
     */
    public function recordSearch(User $user, string $term, int $hits, ?string $page = null): void
    {
        $term = mb_substr(trim($term), 0, 191);

        if (mb_strlen($term) < 2 || CompanyContext::id() === null) {
            return;
        }

        $last = AssistantEvent::query()->where('kind', AssistantEvent::SEARCH)->where('user_id', $user->id)
            ->where('updated_at', '>=', Carbon::now()->subSeconds(self::SEARCH_MERGE_SECONDS))->latest('id')->first();
        $before = (string) ($last?->text ?? '');

        if ($last !== null && $before !== '' && (str_starts_with($term, $before) || str_starts_with($before, $term))) {
            $last->forceFill(['text' => $term, 'outcome' => (string) $hits])->save();

            return;
        }

        $this->record(AssistantEvent::SEARCH, $user, $term, ['page' => $page === null ? null : mb_substr($page, 0, 191), 'outcome' => (string) $hits]);
    }

    /**
     * ⓘ মেয়াদ পেরোনো প্রশ্ন আর উত্তরের লেখা মোছা — "কে কখন কী" থাকে (সেটিং `assistant.keep_days`)।
     *
     * @return int কয়টা সারির লেখা মুছল
     */
    public function forgetOldText(): int
    {
        $days = max(30, (int) $this->settings->get('assistant.keep_days', 365));

        return AssistantEvent::query()->where('created_at', '<', Carbon::now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('text')->orWhereNotNull('answer'))
            ->update(['text' => null, 'answer' => null, 'updated_at' => Carbon::now()]);
    }
}
