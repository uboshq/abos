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

    public function __construct(private readonly SettingsService $settings) {}

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
        ] + array_intersect_key($extra, array_flip(['module', 'page', 'answer', 'model', 'ms', 'outcome', 'feedback', 'reason'])));
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
