<?php

declare(strict_types=1);

namespace App\Modules\Approval\Widgets;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PaperParties;
use App\Core\Services\PartyRegistry;
use App\Core\Widgets\WidgetSummary;
use App\Http\Controllers\Api\ApprovalApiController;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ⭐ "সইয়ের অপেক্ষা" — ফোনের হোম-স্ক্রিন উইজেট `approvals`-এর সার্ভার-অংশ (উইজেটের চুক্তি §৩; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ দ্বিতীয় ইনবক্স নয় — তালিকাটা ফোনের সই-বাক্সের হুবহু ([[ApprovalApiController::pending()]]): একই প্রশ্ন
 * [[ApprovalEngine::pendingQueryFor()]] (কেবল এই মানুষের সইয়ের কাগজ: ছকের স্তর, হাতে দেওয়া, সময় পেরিয়ে উপরে আসা; নিজের
 * অনুরোধ সীমার উপরে নয় — maker-checker; এই স্তরে আগেই সই দেওয়া নয়), আর একই ফোনের ছাঁকনি [[ApprovalApiController::forThePhone()]]।
 * ⓘ সই বন্ধ কোম্পানি (সব ছক `is_active = false`) — ইঞ্জিন বন্ধ ছক পড়েই না, তাই সই-বাক্স আর উইজেট দুজনেই খালি।
 *
 * ⛔ বেতন নয়: [[ApprovalApiController::forThePhone()]] জনবল মডিউল আর বেতনের কাগজ (PayrollRun, Payslip) প্রশ্নেই বাদ দেয়, তাই
 * বেতনের কোনো কাগজ এই উইজেটে আসেই না — না সারিতে, না `count`-এ; অঙ্ক null করার কিছু থাকে না।
 *
 * ⓘ ক্রম: সই-বাক্স পুরনো আগে (`requested_at`, তারপর `id`); উইজেট ঠিক তার উল্টো — নতুন আগে, সর্বোচ্চ [[ROWS]]টা।
 * `count` সব অপেক্ষমাণ কাগজের, কেবল দেখানো সারির নয়।
 *
 * ⓘ পক্ষ: গ্রাহক "নাম · পয়েন্ট · শাখা", সরবরাহকারী "নাম · শাখা" — পক্ষের এক নিয়ম ([[PartyRegistry::labelsOf()]], যা
 * [[Customer::nameWithPoint()]]-এর একই জোড়া), নাম/পয়েন্ট/শাখা আলাদা ঘরেও। ধরন অন্য কিছু (কর্মী, ব্যক্তি) হলে `other`।
 *
 * ⓘ অঙ্ক: সইয়ের অঙ্ক (`approvals.amount`, bcmath ৪ দশমিক) — ওয়েবের সই-বাক্সের অঙ্ক, যেখানে `link` নিয়ে যায়; অঙ্ক না থাকলে null।
 * ⓘ সময় (`as_of`) এখানে নয় — দরজা নিজেই উপরে `asOf` পাঠায়, আর তথ্যে সময় থাকলে দরজার ETag কখনো ৩০৪ দিত না।
 * ⓘ `link` ওয়েবের পথ — সারির সই-পাতা আর গোটা সই-বাক্স। ⛔ সই উইজেটে কখনো নয়; কেবল পড়া।
 */
final class ApprovalsSummary implements WidgetSummary
{
    /** বড় মাপের উইজেটে সর্বোচ্চ সারি (চুক্তি) */
    public const ROWS = 8;

    public function __construct(
        private readonly ApprovalFacts $facts,
        private readonly PaperParties $papers,
        private readonly PartyRegistry $parties,
        private readonly ApprovalFlowService $flows,
    ) {}

    public function key(): string
    {
        return 'approvals';
    }

    /** ফোনের সই-বাক্সের মডিউল-সুইচ — রুটের `RefuseModulesOffOnThePhone:approval` */
    public function module(): string
    {
        return 'approval';
    }

    /** ফোনের সই-বাক্সের রুটের চাবি (`can:approval.decide`) — ওয়েবের ইনবক্সের মেনুরও */
    public function permission(): ?string
    {
        return 'approval.decide';
    }

    public function data(User $user): array
    {
        $inbox = $this->inboxOf($user);

        /** @var Collection<int, Approval> $approvals */
        $approvals = (clone $inbox)->reorder()
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(self::ROWS)
            ->get();

        return [
            'count' => (clone $inbox)->reorder()->count(),
            'rows' => $this->rowsOf($approvals),
            'link' => route('approval.inbox.index', [], false),
        ];
    }

    /** ফোনের সই-বাক্সের প্রশ্নটাই — হুবহু [[ApprovalApiController::pending()]]-এর প্রথম লাইন */
    private function inboxOf(User $user): Builder
    {
        return ApprovalApiController::forThePhone(app(ApprovalEngine::class)->pendingQueryFor($user));
    }

    /**
     * @param  Collection<int, Approval>  $approvals
     * @return list<array<string, mixed>>
     */
    private function rowsOf(Collection $approvals): array
    {
        if ($approvals->isEmpty()) {
            return [];
        }

        $facts = $this->facts->of($approvals, true); // ⓘ সইকারী কাগজটা দেখেনই — দেখার দেয়াল ছাড়া, সই-বাক্সের মতো
        $documents = $this->documentsOf($approvals);
        $kinds = $this->kindLabels();

        $keys = [];
        foreach ($approvals as $approval) {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;
            $key = $document === null ? null : $this->papers->keyOf($document);
            if ($key !== null) {
                $keys[(int) $approval->id] = $key;
            }
        }
        $parties = $this->partiesOf(array_values($keys));

        return $approvals->map(function (Approval $approval) use ($facts, $documents, $kinds, $keys, $parties): array {
            $document = $documents[$approval->approvable_type][(int) $approval->approvable_id] ?? null;
            $fact = $facts[(int) $approval->id] ?? [];
            $key = $keys[(int) $approval->id] ?? null;
            $number = $document?->getAttribute('document_no') ?? ($fact['no'] ?? null);

            return [
                'party' => $key !== null && isset($parties[$key[0].':'.$key[1]])
                    ? $parties[$key[0].':'.$key[1]]
                    : self::loose($fact['party'] ?? null),
                'kind_label' => $kinds[$approval->module.'.'.$approval->action] ?? $approval->module.' · '.$approval->action,
                'number' => $number === null ? null : (string) $number,
                'amount' => $approval->amount === null ? null : bcadd((string) $approval->amount, '0', 4),
                'link' => route('approval.inbox.show', ['approval' => $approval->id], false),
            ];
        })->values()->all();
    }

    /**
     * পক্ষগুলো — ধরন প্রতি একবারে, পক্ষের এক নিয়মে ([[PartyRegistry]])।
     *
     * ⓘ `label` = "নাম · পয়েন্ট · শাখা" ([[PartyRegistry::labelsOf()]]); জায়গা ([[PartyRegistry::pointAndBranchOf()]]) আর
     * কেবল শাখা থেকে পয়েন্ট আর নাম আলাদা হয় — লেবেলটা ঠিক নাম + " · " + জায়গা, জায়গাটা পয়েন্ট + " · " + শাখা।
     *
     * @param  list<array{0: string, 1: int}>  $pairs
     * @return array<string, array{label: string, name: string, point: ?string, branch: ?string, kind: string}>
     */
    private function partiesOf(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $labels = $this->parties->labelsOf($pairs);
        $places = $this->parties->pointAndBranchOf($pairs);
        $branches = $this->parties->pointAndBranchOf($pairs, true);

        $out = [];
        foreach ($pairs as [$type, $id]) {
            $slot = $type.':'.$id;
            $label = $labels[$slot] ?? null;
            if ($label === null) {
                continue;
            }
            $place = $places[$slot] ?? null;
            $branch = $branches[$slot] ?? null;
            $point = match (true) {
                $place === null, $place === $branch => null,
                $branch === null => $place,
                default => self::before($place, $branch),
            };

            $out[$slot] = [
                'label' => $label,
                'name' => $place === null ? $label : self::before($label, $place),
                'point' => $point,
                'branch' => $branch,
                'kind' => in_array($type, ['customer', 'supplier'], true) ? $type : 'other',
            ];
        }

        return $out;
    }

    /** পক্ষের ধরন চেনা যায়নি, কাগজে কেবল হাতে লেখা নাম — সই-বাক্সের একই লেখা ([[ApprovalFacts]]) */
    private static function loose(?string $label): ?array
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        return ['label' => $label, 'name' => $label, 'point' => null, 'branch' => null, 'kind' => 'other'];
    }

    /** "ক · খ"-এর শেষের " · খ" বাদ */
    private static function before(string $whole, string $tail): string
    {
        $suffix = ' · '.$tail;

        return str_ends_with($whole, $suffix) ? substr($whole, 0, -strlen($suffix)) : $whole;
    }

    /**
     * কাগজের ধরনের নাম — ওয়েবের সই-বাক্সের একই ঘোষণা ([[ApprovalFlowService::labels()]], `module.action` → "বিক্রয়ে ছাড়"),
     * বাংলায় (ফোনের উইজেট বাংলায়; মালিক কেবল বাংলা পড়েন)।
     *
     * @return array<string, string>
     */
    private function kindLabels(): array
    {
        $was = app()->getLocale();
        app()->setLocale('bn');

        try {
            return $this->flows->labels();
        } finally {
            app()->setLocale($was);
        }
    }

    /**
     * কাগজগুলো — ধরন ধরে একবারে, দেখার দেয়াল ছাড়া (সই-বাক্সের `documentsOf()`-এর একই পড়া)।
     *
     * @param  Collection<int, Approval>  $approvals
     * @return array<string, Collection<int, Model>>
     */
    private function documentsOf(Collection $approvals): array
    {
        $found = [];

        foreach ($approvals->groupBy('approvable_type') as $class => $group) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $found[$class] = $class::query()
                ->withoutGlobalScopes(ApprovalFacts::VIEW_WALLS)
                ->whereKey($group->pluck('approvable_id')->unique()->values()->all())
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey());
        }

        return $found;
    }
}
