<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\ApprovalSla;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * অনুমোদনের ইনবক্সের নিয়ম — চার ট্যাব, ছাঁকনি, সাজানো, দেরি, ধাপ, কার হয়ে, শেষ সময় আর যাত্রাপথ।
 *
 * ── কেন একটা সেবা, ৩০ অক্টোবর ২০২৬ (অনুমোদন ভাগ ৩, দফা ২১) ───────────────
 * পরিকল্পনা: *"অ্যাপে একই ট্যাব, একই নাম আর একই নিয়ম"*। ⓘ নিয়মগুলো এতদিন ওয়েবের ইনবক্সের ভেতরে লেখা ছিল
 * ([[ApprovalInboxController]]), আর ফোনের দরজা ([[ApprovalApiController]]) ওদের কিছুই পেত না — ফোন দেখাত একটাই তালিকা,
 * শেষ সময় নেই, ধাপ "২" (কয়টার মধ্যে, বলে না)। ⛔ ফোনের দরজায় আবার লিখলে একদিন ফোন আর ওয়েব একই কাগজকে দুই রকম
 * "দেরি" বলত। ⭐ তাই নিয়মগুলো এখানে, একবার; ওয়েব আর ফোন দুইটাই এখান থেকে পড়ে। ⓘ পদ্ধতিগুলো ওয়েবের ইনবক্স থেকে হুবহু
 * সরানো — কোনো নিয়ম বদলায়নি।
 */
final class ApprovalInbox
{
    /**
     * ⭐ ইনবক্সের চারটা ট্যাব — অনুমোদন পর্দা ভাগ ২, দফা ২ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; SAP Fiori "My Inbox")।
     *
     *   waiting  আমার অপেক্ষায় — [[ApprovalEngine::pendingQueryFor()]]
     *   sent     আমার পাঠানো — আমার যে অনুরোধ এখনো খোলা (অপেক্ষায় বা সংশোধনে ফেরত); পুরো ইতিহাস "আমার অনুরোধ" পাতায়
     *   done     আমি অনুমোদন দিয়েছি — যেগুলোয় আমার অনুমোদনের সারি আছে
     *   behalf   অন্যের হয়ে — দায়িত্ব হস্তান্তরে আমার কাছে আসা অপেক্ষার কাগজ ([[ApprovalEngine::standInQueryFor()]])
     */
    public const TABS = ['waiting', 'sent', 'done', 'behalf'];

    /** ⭐ সাজানো — দফা ৩: দেরি আগে (অপেক্ষার ডিফল্ট), নতুন আগে, অঙ্ক ধরে (বড় আগে) */
    public const SORTS = ['late', 'new', 'amount'];

    /**
     * ⭐ অঙ্কের ছাঁকনি — দফা ৩। [নিচের সীমা, ওপরের সীমা) — নিচেরটা ধরে, ওপরেরটা নয়, তাই কোনো অঙ্ক দুই ধাপে পড়ে না।
     * ⓘ ধাপগুলো অনুমোদনের নিয়মের চেনা সীমায় (১০ হাজার, ১ লাখ, ৫ লাখ) — "৫ লাখ বা বেশি" এক রকম (পরিকল্পনার চতুর্থ যাচাই)।
     */
    public const AMOUNT_BANDS = [
        'below_10k' => [null, '10000'],
        '10k_1l' => ['10000', '100000'],
        '1l_5l' => ['100000', '500000'],
        '5l_up' => ['500000', null],
    ];

    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ApprovalSla $sla,
    ) {}

    /**
     * ⭐ চার ট্যাবের প্রশ্ন — কোয়েরি হিসেবে, চালানোর আগে (গোনা আর সারি আলাদা চলে)।
     *
     * @return array<string, Builder<Approval>>
     */
    public function tabQueries(User $subject, bool $own): array
    {
        $tabs = ['waiting' => $this->engine->pendingQueryFor($subject)];

        if (! $own) {
            return $tabs;
        }

        $tabs['sent'] = Approval::query()
            ->with('requester')
            ->where('requested_by', $subject->id)
            ->whereIn('status', [Approval::PENDING, Approval::RETURNED]);

        $tabs['done'] = Approval::query()
            ->with('requester')
            ->whereExists(fn ($signed) => $signed->selectRaw('1')
                ->from('approval_decisions')
                ->whereColumn('approval_decisions.approval_id', 'approvals.id')
                ->where('approval_decisions.user_id', $subject->id)
                ->where('approval_decisions.decision', ApprovalDecision::APPROVED));

        $tabs['behalf'] = $this->engine->standInQueryFor($subject);

        return $tabs;
    }

    /**
     * ⭐ শাখা আর অঙ্ক — দফা ৩।
     *
     * ── ⓘ শাখা কাগজের নিজের, অনুমোদনের সারির নয় ─────────────────────
     * `approvals` সারিতে শাখা নেই — কাগজটা কোন শাখার, তা কাগজের নিজের `branch_id`-তে (ইনবক্সের "শাখা" লেখাও সেখান থেকে,
     * [[ApprovalFacts]])। ⚠️ তাই ধরন প্রতি একটা উপ-প্রশ্ন: যে ধরনের সারণিতে `branch_id` নেই (বেতনের রান — গোটা কোম্পানির), শাখা
     * বাছলে সেটা আসে না; আর সেটাই সৎ — ওই কাগজ কোনো এক শাখার নয়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function narrowed($query, int $branch, string $band)
    {
        if ($band !== '') {
            [$from, $below] = self::AMOUNT_BANDS[$band];
            $query->whereNotNull('amount')
                ->when($from !== null, fn ($q) => $q->where('amount', '>=', $from))
                ->when($below !== null, fn ($q) => $q->where('amount', '<', $below));
        }

        if ($branch <= 0) {
            return $query;
        }

        $types = (clone $query)->reorder()->withoutEagerLoads()->distinct()->pluck('approvable_type')->all();

        return $query->where(function (Builder $any) use ($types, $branch): void {
            $any->whereRaw('1 = 0');

            foreach ($types as $type) {
                if (! is_string($type) || ! is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }

                $table = (new $type)->getTable();

                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'branch_id')) {
                    continue;
                }

                $any->orWhere(fn (Builder $one) => $one
                    ->where('approvable_type', $type)
                    ->whereIn('approvable_id', fn ($ids) => $ids->select('id')->from($table)->where('branch_id', $branch)));
            }
        });
    }

    /**
     * ⭐ সাজানো — দফা ৩।
     *
     * ⓘ "দেরি আগে": যেটার শেষ সময় পেরিয়েছে আগে, তারপর যেটার শেষ সময় কাছে, তারপর ঘড়িহীন — প্রতিটা দলে পুরনোটা আগে।
     * ⓘ শেষে সবসময় `id` — একই মুহূর্তের দুই কাগজ পাতা বদলালে জায়গা অদলবদল করে না (পাতা ভাগে একটা কাগজ দুইবার বা একবারও না)।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function sorted($query, string $sort)
    {
        $query->reorder();

        return match ($sort) {
            'new' => $query->orderByDesc('requested_at')->orderByDesc('id'),
            'amount' => $query->orderByRaw('amount IS NULL')->orderByDesc('amount')->orderBy('id'),
            default => $query
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 2 WHEN due_at < ? THEN 0 ELSE 1 END', [now()])
                ->orderBy('due_at')
                ->orderBy('requested_at')
                ->orderBy('id'),
        };
    }

    /**
     * ⭐ সারি ধরে ধাপ — "ধাপ ২ / ৩ · ম্যানেজার" (দফা ৮)। ⓘ ধাপের সংখ্যা ইঞ্জিনের ছক থেকে, স্তর ধরে (একই স্তরে তিনজন = এক ধাপ);
     * নাম থাকলে নাম। ছক এক কাজে একবারই পড়া হয়।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $rows
     * @return array<int, array{at: int, of: int, name: ?string}>
     */
    public function stepsOf($rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $approval) {
            $key = $approval->module.'|'.$approval->action.'|'.$approval->approvable_type;
            $seen[$key] ??= [
                'levels' => $this->engine->stepsFor($approval)->pluck('level')->map(fn ($l) => (int) $l)->unique()->sort()->values()->all(),
                'names' => $this->engine->stepNamesFor($approval),
            ];

            $levels = $seen[$key]['levels'];
            $at = (int) $approval->current_level;

            $out[(int) $approval->id] = [
                'at' => $at,
                'of' => max(count($levels), $at),
                'name' => $seen[$key]['names'][$at] ?? null,
            ];
        }

        return $out;
    }

    /**
     * ⭐ কার হয়ে — "অন্যের হয়ে" ট্যাব আর সারির চিহ্ন (দফা ২)। ⓘ কেবল নিজের ইনবক্সে; প্রশ্নটা ইঞ্জিনের ([[ApprovalEngine::standInFor()]]),
     * যেটা সিদ্ধান্তের `on_behalf_of`-এর একই উত্তর দেয়।
     *
     * @param  \Illuminate\Support\Collection<int, Approval>  $rows
     * @return array<int, string>
     */
    public function standInsOf($rows, User $user, bool $own): array
    {
        if (! $own) {
            return [];
        }

        $out = [];

        foreach ($rows as $approval) {
            if ($approval->status !== Approval::PENDING) {
                continue;
            }

            $for = $this->engine->standInFor($approval, $user);

            if ($for !== null) {
                $out[(int) $approval->id] = (string) User::query()->whereKey($for)->value('name');
            }
        }

        return $out;
    }

    /**
     * ⭐ *"দেরি"* মানে কী — একটাই জায়গায়।
     *
     * ⚠️ তিনটা কোয়েরিতে একই শর্ত লাগে: চিপের সংখ্যা, সারিগুলো,
     * আর মোট। ⛔ তিন জায়গায় লিখলে একদিন একটা বদলাত, আর
     * চিপে ১২ লেখা থাকত যখন তালিকায় সাতটা সারি।
     *
     * ⓘ `due_at` খালি মানে ওই ধাপে ঘড়ি নেই — ওগুলো কখনো দেরি নয়।
     *
     * @template TModel of Approval
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function lateOnes($query, bool $only = true)
    {
        return $query->when($only, fn ($q) => $q
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()));
    }

    /**
     * খোঁজা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: দুই তালিকাতেই খোঁজার ঘর।
     *
     * ⓘ যা পর্দায় দেখা যায় তা ধরেই: অনুরোধকারীর নাম, অনুরোধের কারণ, অঙ্ক, আর
     * কাগজের তথ্য (পক্ষ, কী বাবদ, কোথায় — `payload`)। ⚠️ খালি হলে কিছুই বদলায় না।
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    public static function searched($query, string $term)
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        /*
         * ⭐ কাগজের নম্বর আর পক্ষের নামেও — ফোনের নতুন ইনবক্স (মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok": "নম্বর, পক্ষ বা কে চেয়েছেন — খুঁজুন")।
         * ⓘ আগে কেবল কারণ, payload, অঙ্ক আর চাওয়া মানুষ — "PO-0099" বা "মেঘনা সিমেন্ট" লিখলে কিছুই মিলত না। কাগজের ধরন ধরে তার
         * নিজের টেবিলে উপ-প্রশ্ন ([[narrowed()]]-এর শাখার একই ধাঁচ); যে টেবিলে ঘরটা নেই সেখানে প্রশ্নই নয়।
         */
        $types = (clone $query)->reorder()->withoutEagerLoads()->distinct()->pluck('approvable_type')->all();
        $has = static fn (string $table, string $column): bool => \Illuminate\Support\Facades\Schema::hasColumn($table, $column);

        return $query->where(function ($q) use ($like, $types, $has) {
            $q->where('requested_reason', 'like', $like)
                ->orWhere('payload', 'like', $like)
                ->orWhere('amount', 'like', $like)
                ->orWhereHas('requester', fn ($u) => $u->where('name', 'like', $like));

            foreach ($types as $type) {
                if (! is_string($type) || ! is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }

                $table = (new $type)->getTable();
                $papers = [];

                if ($has($table, 'document_no')) {
                    $papers[] = fn ($ids) => $ids->select('id')->from($table)->where('document_no', 'like', $like);
                }

                foreach (['customer_id' => 'customers', 'supplier_id' => 'suppliers'] as $column => $parties) {
                    if ($has($table, $column) && $has($parties, 'name_en')) {
                        $papers[] = fn ($ids) => $ids->select($table.'.id')->from($table)
                            ->join($parties, $parties.'.id', '=', $table.'.'.$column)
                            ->where(fn ($n) => $n->where($parties.'.name_en', 'like', $like)
                                ->when($has($parties, 'name_bn'), fn ($b) => $b->orWhere($parties.'.name_bn', 'like', $like)));
                    }
                }

                foreach ($papers as $ids) {
                    $q->orWhere(fn ($one) => $one->where('approvable_type', $type)->whereIn('approvable_id', $ids));
                }
            }
        });
    }

    /**
     * ⭐ প্রবাহের প্রতিটা ধাপ, আর এখন সেটা কোন অবস্থায়।
     *
     * ── ⓘ কেন সিদ্ধান্ত নয়, ধাপ ধরে ───────────────────────
     * সিদ্ধান্তগুলো ধরে সাজালে **যে ধাপে এখনো কেউ কিছু করেননি**
     * সেটা তালিকায় আসতই না — আর ঠিক সেটাই পাঠকের প্রশ্ন।
     *
     * @return list<array{level: int, name: string|null, state: string}>
     */
    public function timelineOf(Approval $approval): array
    {
        $names = $this->engine->stepNamesFor($approval);

        $decided = $approval->decisions
            ->groupBy('level')
            ->map(fn ($rows) => $rows->last()?->decision);

        /*
         * ⭐ একটা ধাপে কত সময় গেল — ঘণ্টায়।
         *
         * ⓘ গণনাটা **আগের সিদ্ধান্ত থেকে**, অনুরোধের দিন থেকে
         * নয়। ⛔ অনুরোধ ধরে গুনলে তৃতীয় ধাপে তিন ধাপের সময় যোগ
         * হয়ে দেখাত, আর সবসময় শেষ ধাপটাই সবচেয়ে ধীর দেখাত —
         * যে ধাপে সত্যি দেরি হয় তাকে কখনো দেখা যেত না।
         */
        $took = [];
        $from = $approval->requested_at;

        /*
         * ⛔ হিসাবটা **ধাপ ধরে**, সিদ্ধান্ত ধরে নয়।
         *
         * ⓘ একটা ধাপ **শেষ হয়** তার শেষ সইয়ে, আর **শুরু হয়**
         * আগের ধাপের শেষ সইয়ে (প্রথমটার বেলায় অনুরোধের দিন)।
         *
         * ⚠️ প্রতিটা সিদ্ধান্তে লিখলে দুইজনের সই লাগা ধাপে সংখ্যাটা
         * হত **দুই সইয়ের মাঝের ফাঁক** — অর্থাৎ যে ধাপে কিছু দিন
         * কেউ তাকাননি, সেটাই সবচেয়ে ছোট দেখাত।
         */
        $endOfLevel = $approval->decisions
            ->filter(fn ($d) => $d->decided_at !== null)
            ->groupBy('level')
            ->map(fn ($rows) => $rows->max('decided_at'))
            ->sortKeys();

        foreach ($endOfLevel as $level => $ended) {
            if ($from !== null) {
                // ⛔ Carbon 3-এ diffInHours ভগ্নাংশ আর চিহ্নসহ দেয় — যাত্রাপথে "-1.3752777777778 ঘণ্টা" দেখাত (৩০ অক্টোবর ২০২৬, দফা ২১);
                // পুরো ঘণ্টা, আর ঘড়ির গরমিলে সিদ্ধান্ত অনুরোধের আগে পড়লেও শূন্যের নিচে নয়
                $took[(int) $level] = max(0, (int) $from->diffInHours($ended));
            }

            $from = $ended;
        }

        $out = [];

        /*
         * ⛔ ধাপগুলো [[ApprovalEngine::stepsFor()]] থেকে, নামগুলো থেকে নয়।
         *
         * ⚠️ `stepNamesFor()` **নামহীন ধাপ বাদ দেয়** — ওটা দিয়ে
         * যাত্রাপথ বানালে নাম না বসানো প্রবাহে পর্দাটা **নীরবে
         * খালি** থাকত, আর সেটা দেখতে *"কোনো ধাপ নেই"*-এর মতো।
         */
        $steps = $this->engine->stepsFor($approval);

        /*
         * ⓘ একই স্তরে একাধিক সইকারী থাকতে পারেন (N-of-M)।
         * ⚠️ তাই যাত্রাপথে সারি হয় **স্তর** ধরে, মানুষ ধরে নয় —
         * নাহলে তিনজনের একটা ধাপ তিনটা ধাপ দেখাত।
         */
        foreach ($steps->groupBy('level') as $level => $atLevel) {
            $out[] = [
                'level' => (int) $level,
                'name' => $names[$level] ?? null,
                'took' => $took[(int) $level] ?? null,

                // ⓘ এই স্তরে কয়জনের সই লাগে, আর কয়জন দিয়েছেন
                /*
                 * ⛔ হিসাবটা ইঞ্জিনের, এখানে দ্বিতীয়বার লেখা নয়।
                 *
                 * ⚠️ দুই জায়গায় লিখলে একদিন পর্দা বলত *"২-এর ১"*
                 * আর ইঞ্জিন কাগজটা এগিয়ে দিত — আর পাঠক কোনটাকে
                 * বিশ্বাস করবেন বলতে পারতেন না।
                 */
                'needed' => ApprovalEngine::signaturesNeededAt($atLevel),
                'signed' => $approval->decisions
                    ->where('level', (int) $level)
                    ->where('decision', ApprovalDecision::APPROVED)
                    ->count(),

                'state' => match (true) {
                    isset($decided[$level]) => (string) $decided[$level],
                    (int) $level === (int) $approval->current_level
                        && $approval->status === Approval::PENDING => 'now',
                    default => 'waiting',
                },

                /*
                 * ⭐ এই ধাপে কে কখন কী বললেন — দফা ১০ (অনুমোদন পর্দা ভাগ ২): সময়, মন্তব্য, আর দায়িত্ব হস্তান্তরে হলে কার হয়ে।
                 * ⓘ অন্যের হাতে দেওয়াও থাকে (কার কাছে গেল সহ) — শিকলটা যেন না ছেঁড়ে।
                 */
                'by' => $approval->decisions
                    ->where('level', (int) $level)
                    ->sortBy('decided_at')
                    ->map(fn (ApprovalDecision $d) => [
                        'name' => (string) ($d->user?->name ?? '—'),
                        'decision' => (string) $d->decision,
                        'at' => $d->decided_at,
                        'remarks' => $d->remarks,
                        'for' => $d->onBehalfOf?->name,
                        'to' => $d->forwardedTo?->name,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $out;
    }

    /**
     * ⭐ শেষ সময় — লেখায়, মাউস রাখার অপেক্ষায় নয় (অনুমোদন পর্দা ভাগ ২, দফা ৭): "শেষ সময় আজ 5:00", "শেষ সময় কাল 11:00",
     * "শেষ সময় পেরিয়েছে 3 ঘণ্টা", "দেরি 2 দিন"। ঘড়ি না থাকলে `null` — "—" বসিয়ে জায়গা নেওয়া হয় না।
     *
     * ⓘ রং [[ApprovalSla::stateOf()]]-এর, লেখা নয় — তাই তালিকা, কাগজের পাতা আর ঘণ্টার কমান্ড "দেরি কি না" প্রশ্নে কখনো দুই কথা বলে
     * না। ⛔ রং একা কিছু বলে না: লেখাতেই "পেরিয়েছে"/"দেরি" থাকে (সাদাকালো ছাপা, রং-কানা পাঠক)।
     *
     * @return array{text: string, tone: string}|null
     */
    public function deadlineOf(Approval $approval): ?array
    {
        $due = $approval->due_at;

        if ($due === null || $approval->status !== Approval::PENDING) {
            return null;
        }

        $state = $this->sla->stateOf($approval);
        $now = now();
        $time = $due->format(\App\Core\Support\DateFormat::time());

        $text = match (true) {
            $due->lessThan($now) && (int) floor($due->diffInHours($now, true)) < 24 => __('approval::message.due_passed_hours', [
                'hours' => max(1, (int) floor($due->diffInHours($now, true))),
            ]),
            $due->lessThan($now) => __('approval::message.due_late_days', ['days' => (int) floor($due->diffInDays($now, true))]),
            $due->isSameDay($now) => __('approval::message.due_today', ['time' => $time]),
            $due->isSameDay($now->copy()->addDay()) => __('approval::message.due_tomorrow', ['time' => $time]),
            default => __('approval::message.sla_due', ['when' => \App\Core\Support\DateFormat::spoken($due)]),
        };

        if ($state === ApprovalSla::ESCALATED) {
            $text .= ' · '.__('approval::message.sla_escalated');
        }

        return [
            'text' => $text,
            'tone' => match ($state) {
                ApprovalSla::LATE, ApprovalSla::ESCALATED => 'danger',
                ApprovalSla::NEAR => 'warning',
                default => 'success',
            },
        ];
    }
}
