<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Models\Approval;
use App\Models\ApprovalLimit;
use App\Models\User;

/**
 * এই মানুষটা এই কাগজে সই দেওয়ার মতো ক্ষমতা রাখেন কি না।
 *
 * ── ⚠️ এটা "অনুমতি" নয়, "কর্তৃত্ব" ──────────────────────────────────
 * ⓘ অনুমতি বলে *"ইনি অনুমোদনের পর্দায় ঢুকতে পারেন"* (`approval.decide`)।
 * ⭐ কর্তৃত্ব বলে *"ইনি **এত টাকা পর্যন্ত** সই দিতে পারেন"*। ⛔ দুইটা
 * এক করে ফেললে হয় সবাই সব অঙ্কে সই দিতে পারত, নয় কেউ কিছুতেই পারত না।
 */
final class AuthorityService
{
    /**
     * ⭐ এই মানুষটার সীমা — `null` মানে সীমা নেই।
     *
     * ── ⓘ কেন সবচেয়ে **উঁচু** সীমাটা জেতে ──────────────────────────
     * একজনের একাধিক রোল থাকতে পারে। ⚠️ সবচেয়ে নিচু সীমাটা ধরলে একটা
     * ছোট রোল যোগ করলেই মানুষটার ক্ষমতা কমে যেত — অর্থাৎ **রোল যোগ
     * করা শাস্তি** হয়ে দাঁড়াত, আর সেটা কেউ অনুমান করতে পারত না।
     */
    public function limitFor(User $user, string $module, string $action, ?int $branchId): ?string
    {
        $roleIds = $user->roles()->pluck('id')->all();

        if ($roleIds === []) {
            return null;
        }

        $rows = ApprovalLimit::query()
            ->whereIn('role_id', $roleIds)
            ->get()
            ->filter(fn (ApprovalLimit $l) => $l->fits($module, $action, $branchId));

        if ($rows->isEmpty()) {
            return null;
        }

        /*
         * ⭐ রোল ধরে ধরে: প্রতিটা রোলের **সবচেয়ে নির্দিষ্ট** সারিটা, আর
         * তারপর রোলগুলোর মধ্যে সবচেয়ে উঁচু সীমাটা।
         *
         * ⛔ সোজা সবচেয়ে উঁচু সারিটা নিলে একটা সাধারণ সারি
         * ("সবখানে ৫ লাখ") একটা নির্দিষ্ট সারিকে ("এই শাখায় ১ লাখ")
         * হারিয়ে দিত — আর তখন শাখার সীমাটা লেখা থাকত, চলত না।
         */
        $best = null;

        foreach ($rows->groupBy('role_id') as $forRole) {
            $row = $forRole->sortByDesc(fn (ApprovalLimit $l) => $l->weight())->first();

            if ($row->max_amount === null) {
                return null;   // ⓘ এই রোলে সীমা নেই — সবচেয়ে উদার উত্তর
            }

            $amount = (string) $row->max_amount;

            if ($best === null || bccomp($amount, $best, 4) > 0) {
                $best = $amount;
            }
        }

        return $best;
    }

    /**
     * ⛔ এই অনুরোধটা ইনার সীমার ভিতরে?
     *
     * ── ⚠️ অঙ্ক জানা না থাকলে **হ্যাঁ** ─────────────────────────────
     * ⓘ `amount` খালি মানে কাজটা টাকার নয় (যেমন বছর বন্ধ করা)। ⛔ ওখানে
     * "না" বললে ঐ কাজগুলো কেউ কোনোদিন অনুমোদন করতে পারত না।
     *
     * ⚠️ এটা [[ApprovalEngine::withinSelfLimit()]]-এর উল্টো — সেখানে
     * অঙ্ক না জানা মানে "না", কারণ সেটা **নিজের** অনুরোধ, আর সন্দেহে
     * কড়া দিকটাই নিরাপদ। এখানে অন্যের কাগজ, আর কড়া দিকটা কাজ থামায়।
     */
    public function allows(User $user, Approval $approval, ?int $branchId = null): bool
    {
        if ($approval->amount === null) {
            return true;
        }

        /*
         * ⭐ শাখাটা কাগজ থেকে আসে।
         *
         * ⚠️ `approvals`-এ নিজের কোনো `branch_id` নেই — অনুরোধটা
         * কোম্পানির, কাগজটা শাখার। ⓘ তাই প্রশ্নটা কাগজকেই
         * করা হয়, আর যে কাগজে শাখা নেই তার জন্য সাধারণ নিয়মটাই খাটে।
         */
        $branchId = $branchId ?? ($approval->approvable->branch_id ?? null);

        $limit = $this->limitFor($user, $approval->module, $approval->action, $branchId === null ? null : (int) $branchId);

        if ($limit === null) {
            return true;
        }

        return bccomp((string) $approval->amount, $limit, 4) <= 0;
    }
}
