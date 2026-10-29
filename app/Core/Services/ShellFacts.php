<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\UserDataScope;
use Illuminate\Support\Collection;

/**
 * খোলসের ঘরগুলোর তথ্য — প্রতি অনুরোধে একবার, ব্লেডের ভিতরে নয়।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৪.২, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"ব্লেডের ভেতরের ৫টি সরাসরি কোয়েরি সরান — বিশেষত কোম্পানি-সুইচারের
 * শাখা তালিকা (**প্রতিটি পাতায় চলে**)।"*
 *
 * ── ⚠️ কেন ব্লেডের ভিতরে কোয়েরি খারাপ ───────────────────────────────
 * এক. কেউ জানে না ওটা ওখানে আছে। ⓘ ধীর পাতার কারণ খুঁজতে সবাই
 * কন্ট্রোলার আর সার্ভিস দেখে; কেউ একটা কম্পোনেন্টের `@php` ব্লক খোলে না।
 *
 * দুই. ⛔ কম্পোনেন্টটা দুইবার আঁকা হলে কোয়েরিটাও দুইবার চলত, আর কোথাও
 * কোনো চিহ্ন থাকত না।
 *
 * ── ⭐ কেন ক্যাশ নয়, স্মৃতি ─────────────────────────────────────────
 * ক্যাশ বসালে নতুন শাখা খোলার পর সেটা মেনুতে আসতে দেরি হত, আর কেউ
 * বলতে পারত না কতক্ষণ। ⚠️ *"কেন আমার নতুন শাখা দেখা যাচ্ছে না"* — এই
 * প্রশ্নটার উত্তর "একটু পরে আসবে" হওয়া উচিত নয়।
 *
 * ⓘ এই ক্লাসটা `scoped` ([[AppServiceProvider]]), `singleton` নয় — তাই
 * স্মৃতিটা **একটা অনুরোধ পর্যন্তই** থাকে। ⚠️ `singleton` হলে উত্তরটা
 * প্রক্রিয়া বাঁচা পর্যন্ত আটকে থাকত (octane, queue worker), আর নতুন শাখা
 * কোনোদিন দেখা যেত না।
 *
 * ⓘ ফল একই কথা অন্যভাবে:
 * অর্থাৎ পাতায় যতবারই লাগুক, কোয়েরি একবার; আর পরের পাতায় আবার তাজা।
 */
final class ShellFacts
{
    /** @var Collection<int, Company>|null */
    private ?Collection $companies = null;

    /** @var Collection<int, Branch>|null */
    private ?Collection $branches = null;

    /**
     * এই ব্যবহারকারী কোন কোন কোম্পানিতে ঢুকতে পারেন।
     *
     * @return Collection<int, Company>
     */
    public function companies(): Collection
    {
        return $this->companies ??= auth()->user()?->companies()
            ->orderBy('name_en')
            ->get() ?? collect();
    }

    /**
     * চলতি কোম্পানির চালু শাখাগুলো।
     *
     * ⓘ কোম্পানির ছাঁকনিটা গ্লোবাল স্কোপই বসায় — এখানে আবার লেখা হয় না,
     * নাহলে নিয়মটা দুই জায়গায় থাকত।
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        /*
         * ⛔ কেবল যে শাখায় মানুষটার প্রবেশাধিকার আছে — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ শাখার অধিকার ঠিক হয় [[DataScope]] দিয়ে (`branch` ধরন); কোনো সারি
         * না থাকলে সব শাখা। ⚠️ আগে এই তালিকা কোম্পানির **সব** সচল শাখা দেখাত —
         * এক শাখায় বাঁধা কর্মীর সুইচারে অন্য শাখাগুলোও আসত, আর দরজাও আটকাত না।
         */
        $allowed = app(DataScope::class)->idsFor(auth()->user(), UserDataScope::BRANCH);

        return $this->branches ??= Branch::query()
            ->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->ordered()
            ->get();
    }

    /**
     * বদলানোর মতো কিছু আছে কি না।
     *
     * ⓘ একটাই কোম্পানি আর একটাই শাখা হলে মেনুটা দেখানোর মানে নেই —
     * ⚠️ একটা বোতাম যা চাপলে কিছু হয় না, সেটা না থাকার চেয়ে খারাপ।
     */
    public function canSwitch(): bool
    {
        return $this->companies()->count() > 1 || $this->branches()->count() > 1;
    }

    /**
     * ⭐ শাখার নাম সবসময় — মালিকের স্থায়ী নিয়ম ("কোম্পানি · শাখা ▾" সব পাতায়; ওয়েবের মান ধাপ ২, ভুল ৪, ২০ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে নামটা আসত কেবল `currentBranch` থেকে, আর সেটা খালি থাকলে (লাইভে ঠিক তাই — শাখা কখনো বাছা হয়নি, এক শাখার
     * কোম্পানি) বারে শুধু কোম্পানি থাকত। ⓘ এখন: নাগালে একাধিক শাখা আর "সব শাখা" হলে সেটা; নাহলে বাছা শাখা, দেখার শাখা
     * ([[ViewedBranch::one()]], হোমের সংখ্যা যেটা মানে), নয়তো নাগালের প্রথম শাখা — কিছু না কিছু সবসময়।
     * ⓘ বারের "কোম্পানি · শাখা ▾" আর পাতার মাথার "তারিখ · কোম্পানি · শাখা" — দুই জায়গায় এই একটাই উত্তর।
     */
    public function branchLabel(?\App\Models\User $user, ?Branch $chosen = null): ?string
    {
        $branches = $this->branches();

        if ($branches->count() > 1 && (bool) ($user?->view_all_branches ?? true)) {
            return __('core.company.all_branches');
        }

        $id = \App\Core\Support\ViewedBranch::one($user);

        return ($chosen ?? ($id ? $branches->firstWhere('id', $id) : null) ?? $branches->first())?->name();
    }
}
