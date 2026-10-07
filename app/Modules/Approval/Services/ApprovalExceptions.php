<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Engines\Approval\DocumentFingerprint;
use App\Models\Approval;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use Illuminate\Support\Collection;

/**
 * নিয়মের বাইরে যা কিছু — এক জায়গায়।
 *
 * ── ⚠️ কেন একটা আলাদা সারি ──────────────────────────────────────────
 * ⓘ এই ছয়টার প্রতিটাই **নীরব**: কিছুই ভাঙে না, কোনো পর্দা লাল হয় না,
 * আর ব্যবস্থাটা দেখতে ঠিকই লাগে। ⛔ কেউ খুঁজতে না গেলে কোনোদিন জানা যায় না।
 *
 * ⭐ তাই ছয়টাকে একটা পর্দায় আনা — *"আজ কী কী নিয়মের বাইরে"* প্রশ্নের
 * একটাই উত্তর।
 */
final class ApprovalExceptions
{
    /**
     * ⛔ একটা ধরনে সর্বোচ্চ কতগুলো সারি দেখা হয়।
     *
     * ── ⚠️ সীমাটা কেন লাগল, ২৪ সেপ্টেম্বর ২০২৬ ──────────
     * ⓘ দুইটা ধরন কাগজের সাথে বাড়ে: সময় পার হওয়া, আর
     * সইয়ের পর বদলানো। ⛔ দ্বিতীয়টা সবচেয়ে খরচে: প্রতিটা
     * সারির কাগজ তোলা হয়, আর প্রতিটার একটা করে ছাপ নেওয়া হয়।
     *
     * ⚠️ সীমা ছাড়া পাঁচ হাজার অনুমোদিত কাগজের কোম্পানিতে এই
     * একটা পাতা দশ হাজার কোয়েরি পাঠাত — আর পর্দাটা খুলতই না,
     * অর্থাৎ ঠিক যে প্রতিষ্ঠানে সবচেয়ে বেশি দরকার সেখানেই নয়।
     *
     * ── ⓘ পাতা ভাগ কেন নয় ─────────────────────────────
     * সারিগুলো ধরন ধরে দলে বাঁধা, আর প্রতিটা দলের মাথায় গোনা।
     * ⛔ পাতা ভাগ করলে কাট পড়ত একটা ধরনের মাঝখানে — আর
     * মাথার গোনাটা মিথ্যা হয়ে যেত। ⓘ বদলে [[ApprovalInboxController]]-এর
     * ছাঁচ: সীমা + পর্দায় **লেখা থাকে** কতটা দেখা হয়েছে।
     */
    public const LOOK_AT = 200;

    public function __construct(private readonly ApprovalSla $sla) {}

    /**
     * @return Collection<int, array{kind: string, what: string, detail: string, id: int|null}>
     */
    public function all(): Collection
    {
        return collect([
            ...$this->clocksWithNowhereToGo(),
            ...$this->pastTheirTime(),
            ...$this->changedAfterSigning(),
            ...$this->expiredDelegations(),
            ...$this->flowsThatCanNeverCatch(),
            ...$this->actionsWithNoFlow(),
        ]);
    }

    /**
     * ⛔ ঘড়ি বসানো, যাওয়ার জায়গা নেই।
     *
     * ⓘ মালিক সময়সীমা বসিয়ে ধরে নিয়েছেন দেরি হলে কিছু একটা হবে।
     * ⚠️ গন্তব্য না বসালে কাগজ **কোথাও যায় না**, আর তিনি কোনোদিন জানতেন না।
     */
    private function clocksWithNowhereToGo(): array
    {
        $out = [];

        foreach (ApprovalFlow::query()->with('steps')->get() as $flow) {
            foreach ($flow->steps as $step) {
                if ($this->sla->hasHoleAt($step)) {
                    $out[] = [
                        'kind' => 'no_escalation_target',
                        'what' => $flow->code ?? ($flow->module.'.'.$flow->action),
                        'detail' => __('approval::exception.step', ['level' => $step->level]),
                        'id' => (int) $flow->id,
                    ];
                }
            }
        }

        return $out;
    }

    /** ⛔ সময় পার, এখনো কারও হাতে যায়নি। */
    private function pastTheirTime(): array
    {
        return Approval::query()
            ->where('status', Approval::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('escalated_at')
            // ⓘ সবচেয়ে পুরনোটা আগে — সেটাই সবচেয়ে বেশি কাউকে আটকে রেখেছে
            ->orderBy('due_at')
            ->limit(self::LOOK_AT)
            ->get()
            ->map(fn (Approval $a) => [
                'kind' => 'sla_breached',
                'what' => $a->module.' · '.$a->action,
                'detail' => __('approval::exception.waiting_since', [
                    'date' => $a->requested_at?->format('d-m-Y') ?? '—',
                ]),
                'id' => (int) $a->id,
            ])
            ->all();
    }

    /**
     * ⛔ সই দেওয়ার পর কাগজটা বদলেছে।
     *
     * ⓘ ছাপটা মেলে না মানে কাগজের কোনো ঘর বদলেছে — আর মালিকের
     * সিদ্ধান্তে সেটা সই বাতিল করে। ⚠️ এখানে গোনা হয় যাতে কেউ দেখতে
     * পারেন **কত ঘন ঘন** এটা ঘটছে; ঘন ঘন ঘটলে কোথাও একটা কাজের ধরন ভুল।
     */
    private function changedAfterSigning(): array
    {
        return Approval::query()
            ->where('status', Approval::APPROVED)
            ->whereNotNull('state_hash')

            /*
             * ⛔ সীমাটা এখানে সবচেয়ে জরুরি।
             *
             * ⓘ ছাঁকনিটা SQL-এ লেখা যায় না: ছাপটা PHP-তে হিসাব
             * করতে হয়, আর তার জন্য প্রতিটা কাগজ তুলতে হয়।
             * ⚠️ তাই এখানে সারির সংখ্যা = কোয়েরির সংখ্যা।
             *
             * ⓘ সাম্প্রতিকগুলো আগে, কারণ সইয়ের পর কাগজ বদলানোর
             * প্রশ্নটা কাজে লাগে যতক্ষণ কাগজটা জীবিত থাকে।
             */
            ->latest('id')
            ->limit(self::LOOK_AT)
            ->get()
            ->filter(function (Approval $a) {
                $document = $a->approvable;

                if ($document === null) {
                    return false;
                }

                return ! hash_equals(
                    (string) $a->state_hash,
                    app(DocumentFingerprint::class)->of($document),
                );
            })
            ->map(fn (Approval $a) => [
                'kind' => 'changed_after_approval',
                'what' => $a->module.' · '.$a->action,
                'detail' => __('approval::exception.signed_on', [
                    'date' => $a->decided_at?->format('d-m-Y') ?? '—',
                ]),
                'id' => (int) $a->id,
            ])
            ->all();
    }

    /** ⓘ মেয়াদ শেষ হয়ে গেছে — তথ্য, ভুল নয়; কিন্তু দেখা দরকার। */
    private function expiredDelegations(): array
    {
        return ApprovalDelegation::query()
            ->whereNull('revoked_at')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->subDays(30)->toDateString())
            ->with('to')
            ->get()
            ->map(fn (ApprovalDelegation $d) => [
                'kind' => 'delegation_expired',
                'what' => $d->to?->name ?? '—',
                'detail' => $d->ends_on?->format('d-m-Y') ?? '—',
                'id' => (int) $d->id,
            ])
            ->all();
    }

    /**
     * ⛔ এমন শর্ত যা কখনো মিলতেই পারে না।
     *
     * ⚠️ শর্তটা এমন একটা ঘরের উপর বসানো যা ঐ কাজের মডিউল কখনো পাঠায়
     * না। ⓘ তখন প্রবাহটা **কখনো ধরে না**, আর মালিক ভাবেন অনুমোদন
     * বসানো আছে — এটাই এই তালিকার সবচেয়ে বিপজ্জনক সারি।
     */
    private function flowsThatCanNeverCatch(): array
    {
        $out = [];

        foreach (ApprovalFlow::query()->with('conditions')->get() as $flow) {
            if ($flow->conditions->isEmpty()) {
                continue;
            }

            /*
             * ⓘ কোন ঘরগুলো সত্যিই আসে — সেই কাজের কোনো অনুরোধের
             * `payload` থেকে। ⚠️ একটাও অনুরোধ না থাকলে বলার কিছু নেই,
             * আর তখন চুপ থাকাই সৎ: অনুমান করে "মিলবে না" বলা একটা
             * মিথ্যা লাল।
             */
            $seen = Approval::query()
                ->where('module', $flow->module)
                ->where('action', $flow->action)
                ->whereNotNull('payload')
                ->latest('id')
                ->limit(20)
                ->pluck('payload')
                /*
                 * ⓘ `fields_seen` থাকলে সেটাই — ওটাই ঘরের পূর্ণ তালিকা।
                 * ⚠️ পুরনো সারিতে ওটা নেই, তাই তখন পুরনো নিয়ম:
                 * `payload`-এর চাবিগুলো।
                 */
                ->flatMap(fn ($p) => (array) (((array) $p)['fields_seen'] ?? array_keys((array) $p)))
                ->unique()
                ->all();

            if ($seen === []) {
                continue;
            }

            $unknown = $flow->unknownFields($seen);

            if ($unknown !== []) {
                $out[] = [
                    'kind' => 'condition_never_matches',
                    'what' => $flow->code ?? ($flow->module.'.'.$flow->action),
                    'detail' => implode(', ', $unknown),
                    'id' => (int) $flow->id,
                ];
            }
        }

        return $out;
    }

    /**
     * ⓘ যে কাজ অনুমোদন চায়, অথচ কোনো প্রবাহ নেই।
     *
     * ⚠️ তখন কাগজটা **কোনো সই ছাড়াই** পার হয়ে যায় — ইঞ্জিন `null`
     * ফেরায়, আর সেটা "এগিয়ে যাও" মানে।
     */
    private function actionsWithNoFlow(): array
    {
        $out = [];

        /*
         * ⓘ একটাই কোয়েরি, কাজ প্রতি একটা নয়।
         *
         * ⚠️ আগে প্রতিটা ঘোষিত কাজের জন্য একটা `exists()` যেত —
         * প্রায় চল্লিশটা কোয়েরি, একটা পাতায়। ⓘ ভাঙত না, কিন্তু
         * এটা ঠিক সেই পর্দা যেটা খোলা হয় যখন কিছু একটা গোলমাল।
         */
        $live = ApprovalFlow::query()
            ->where('is_active', true)
            ->get(['module', 'action'])
            ->map(fn (ApprovalFlow $flow) => $flow->module.'.'.$flow->action)
            ->flip();

        foreach (app(ApprovalFlowService::class)->choices() as $module => $choice) {
            foreach (array_keys($choice['actions']) as $action) {
                if (! isset($live[$module.'.'.$action])) {
                    $out[] = [
                        'kind' => 'no_flow',
                        'what' => $module.' · '.$action,
                        'detail' => __('approval::exception.passes_unsigned'),
                        'id' => null,
                    ];
                }
            }
        }

        return $out;
    }
}
