<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Approval\ApprovalSla;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * দেরি হওয়া অনুমোদনগুলো — মনে করানো, আর সময় পার হলে উপরে পাঠানো।
 *
 * ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * একটা অনুরোধ বসত, আর তারপর **কিছুই হত না**। ⚠️ যিনি সই দেবেন তিনি
 * ইনবক্স না খুললে কোনোদিন জানতেনই না, আর কাগজটা মাসের পর মাস পড়ে
 * থাকতে পারত।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত: গন্তব্য ধাপে বসানো ────────────────────────
 * দেরি হলে কাগজ **পরের ধাপের জনের কাছে যায় না** — যায় ধাপে নাম ধরে
 * বসানো মানুষের কাছে। ⓘ কারণটা ব্যবসার: দেরি করছেন যিনি, তাঁর উপরের
 * জন সবসময় প্রবাহের পরের ধাপ নন — অনেক সময় পরের ধাপই থাকে না।
 *
 * ── ⚠️ কেন প্রতি ঘণ্টায়, প্রতি মিনিটে নয় ────────────────────────────
 * ⓘ সময়গুলো ঘণ্টায় মাপা (`sla_hours`), তাই মিনিটে চালানো মানে
 * ষাটবার একই প্রশ্ন করে ঊনষাটবার "না" শোনা।
 */
final class ApprovalsDue extends Command
{
    protected $signature = 'abos:approvals-due';

    protected $description = 'Remind and escalate approval requests that have run past their step SLA';

    public function handle(ApprovalSla $sla, NotificationService $notify): int
    {
        $reminded = 0;
        $escalated = 0;
        $holes = 0;

        /*
         * ⚠️ কোম্পানি ধরে ধরে, একসাথে নয়।
         *
         * ⓘ [[Approval]]-এ কোম্পানির গ্লোবাল স্কোপ আছে, আর কমান্ড চলে
         * কোনো কোম্পানি বাছা ছাড়াই। ⛔ স্কোপ ছাড়া চালালে এক কোম্পানির
         * কাগজ অন্য কোম্পানির মানুষের কাছে চলে যেত।
         */
        foreach (Company::query()->pluck('id') as $companyId) {
            /*
             * ⛔ `forCompany()`, সরাসরি `set()` নয় — শেষে আগের
             * প্রসঙ্গটা ফিরে আসে।
             *
             * ⚠️ প্রথমে `set()` লেখা হয়েছিল, আর তাতে কমান্ড শেষে
             * প্রসঙ্গ **শেষ কোম্পানিতে** আটকে থাকত। ⓘ সময়সূচি
             * একই প্রসেসে কয়েকটা কমান্ড চালায়, তাই পরের কমান্ড
             * নীরবে ভুল কোম্পানির ডাটা দেখত।
             *
             * ⭐ ধরা পড়েছে একটা পরীক্ষায়: খাতায় লেখা হয়েছিল,
             * অথচ বার্তাটা অন্য কোম্পানির প্রসঙ্গে খোঁজা হচ্ছিল।
             */
            CompanyContext::forCompany((int) $companyId, function () use ($sla, $notify, &$reminded, &$escalated, &$holes) {
                /*
                 * ⚠️ টুকরো টুকরো, একসাথে নয়।
                 *
                 * ⛔ `get()` লেখা ছিল, আর সেটা ঘড়ি বসানো **প্রতিটা**
                 * অপেক্ষমাণ অনুরোধ একসাথে মেমরিতে তুলত। ⓘ প্রতিটার
                 * পিছনে আবার `currentStep()` আর বার্তা পাঠানো।
                 *
                 * ⚠️ এটা পর্দা নয়, প্রতি ঘণ্টার কাজ — আর ঠিক সেই
                 * কারণে বেশি বিপজ্জনক: কেউ তাকিয়ে নেই, আর ওই কোম্পানিতেই
                 * সবচেয়ে বেশি অনুরোধ জমে যেখানে কেউ সই দিচ্ছেন না।
                 *
                 * ⓘ `chunkById`, `chunk` নয়: ভিতরে সারিগুলো লেখা হয়
                 * (`reminded_at`), আর `chunk` তখন পাতা সরিয়ে ফেলে বলে
                 * কিছু সারি নীরবে বাদ পড়ত।
                 */
                Approval::query()
                    ->where('status', Approval::PENDING)
                    ->whereNotNull('due_at')
                    ->chunkById(200, function ($waiting) use ($sla, $notify, &$reminded, &$escalated, &$holes) {
                        foreach ($waiting as $approval) {
                            $state = $sla->stateOf($approval);

                            if ($state === ApprovalSla::NEAR && $approval->reminded_at === null) {
                                $this->remind($approval, $notify);
                                $approval->update(['reminded_at' => now()]);
                                $reminded++;

                                continue;
                            }

                            if ($state !== ApprovalSla::LATE) {
                                continue;
                            }

                            $target = $sla->escalationTarget($approval->currentStep());

                            /*
                             * ⛔ ঘড়ি আছে, যাওয়ার জায়গা নেই।
                             *
                             * ⚠️ এটা **চুপ করে পার করা যায় না**। ⓘ ধাপে সময়সীমা
                             * বসিয়ে মালিক ধরে নিয়েছেন দেরি হলে কিছু একটা হবে; না
                             * হলে তিনি কোনোদিন জানতে পারতেন না। তাই গোনা হয়, আর
                             * কমান্ডের শেষে সংখ্যাটা বলা হয়।
                             */
                            if ($target === null) {
                                $holes++;

                                continue;
                            }

                            /*
                             * ⓘ দুইবার পাঠানো ঠেকানোর পাহারাটা এখানে নয়।
                             *
                             * ⛔ এখানে `escalated_at !== null` লেখা ছিল, আর সেটা
                             * **কখনো চলত না**: [[ApprovalSla::stateOf()]] উপরেই
                             * `ESCALATED` ফেরায়, আর ওপরের `!== LATE` শর্তটাই
                             * সারিটা ছেঁকে দেয়।
                             *
                             * ⚠️ মরা শাখাটা নিজে ক্ষতি করত না, কিন্তু তার মন্তব্য
                             * করত: সে দাবি করত পাহারাটা ওখানেই, আর কেউ উপরের
                             * শর্তটা বদলালে নিচেরটা কিছুই ধরত না — আর তখন প্রতি
                             * ঘণ্টায় একই কাগজ উপরে পাঠানো হত।
                             *
                             * ⭐ একটা নিয়ম, এক জায়গায়।
                             */
                            $this->escalate($approval, $target, $notify);
                            $escalated++;
                        }

                        return true;
                    });
            });
        }

        $this->info("reminded={$reminded} escalated={$escalated} no_target={$holes}");

        if ($holes > 0) {
            /*
             * ⚠️ ব্যর্থ নয়, কিন্তু চুপও নয়।
             *
             * ⓘ সময়সূচি চালানো কাজ ব্যর্থ হলে খবর যায়; এটা ব্যর্থতা নয়
             * — একটা **অসম্পূর্ণ সাজানো**। ⛔ তবু লগে না উঠলে কেউ
             * কোনোদিন জানত না।
             */
            $this->warn("⛔ {$holes}টা ধাপে সময়সীমা বসানো আছে, অথচ দেরি হলে কার কাছে যাবে বলা নেই।");
        }

        return self::SUCCESS;
    }

    private function remind(Approval $approval, NotificationService $notify): void
    {
        foreach ($this->signers($approval) as $userId) {
            $notify->send(
                $userId,
                'approval.reminder',
                __('core.notify.approval_reminder', ['document' => $approval->module.' · '.$approval->action]),
                null,
                Route::has('approval.inbox.index') ? route('approval.inbox.index') : null,
            );
        }
    }

    /**
     * @param  array{type: string, id: int}  $target
     */
    private function escalate(Approval $approval, array $target, NotificationService $notify): void
    {
        $users = $target['type'] === 'user'
            ? [$target['id']]
            : User::query()->whereHas('roles', fn ($q) => $q->whereKey($target['id']))->pluck('id')->all();

        /*
         * ⚠️ `escalated_to` একজনের ঘর, আর রোলে অনেকজন থাকতে পারেন।
         * ⓘ তখন প্রথম জনের নাম বসে — কাগজটা কার হাতে গেল সেটা জানার
         * জন্য, দখল নেওয়ার জন্য নয়। ⛔ ইনবক্স রোল ধরেই দেখায়, তাই
         * বাকিরাও কাগজটা পান।
         */
        $approval->update([
            'escalated_at' => now(),
            'escalated_to' => $users[0] ?? null,
        ]);

        foreach ($users as $userId) {
            $notify->send(
                $userId,
                'approval.escalated',
                __('core.notify.approval_escalated', ['document' => $approval->module.' · '.$approval->action]),
                null,
                Route::has('approval.inbox.index') ? route('approval.inbox.index') : null,
            );
        }
    }

    /**
     * এই ধাপে কারা সই দিতে পারেন।
     *
     * @return list<int>
     */
    private function signers(Approval $approval): array
    {
        $step = $approval->currentStep();

        if ($step === null) {
            return [];
        }

        if ($step->approver_type === ApprovalFlowStep::BY_USER) {
            return [(int) $step->approver_id];
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereKey($step->approver_id))
            ->pluck('id')
            ->all();
    }
}
