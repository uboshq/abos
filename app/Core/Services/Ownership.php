<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একটা কোম্পানিতে `owner` ঠিক একজন — না কম, না বেশি।
 *
 * ── মালিকের সিদ্ধান্ত, ১৩ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * আলোচনা হয়েছিল আলাদা একটা "super admin" রোল লাগবে কি না। সিদ্ধান্ত:
 * **না** — `owner` রোলটাই সবার উপরে, সব অনুমতিসহ, আর সব কোম্পানির
 * উপরে বসা কোনো অ্যাকাউন্ট থাকবে না।
 *
 * ⓘ কারণটা এই ব্যবস্থার নকশাতেই: পুরোটা দাঁড়িয়ে আছে কোম্পানি-ভিত্তিক
 * বিচ্ছিন্নতার উপর, আর পাঁচটা পাহারা-পরীক্ষা ঐ দেয়ালটা রক্ষা করে।
 * সব কোম্পানি দেখতে পায় এমন একটা অ্যাকাউন্ট ঐ দেয়ালে ইচ্ছাকৃত ফুটো —
 * আর সেটা চুরি গেলে একটা কোম্পানি নয়, সবগুলো একসাথে যায়।
 *
 * ── দুইটা নিয়ম, আর কেন দুইটাই একসাথে লাগে ────────────────────────────
 * ⓵ **শেষ owner সরানো যায় না।** নিষ্ক্রিয় করা, রোল কেড়ে নেওয়া, মুছে
 *    ফেলা — তিনটাই প্রত্যাখ্যাত। ⛔ নইলে একজন owner নিজের চেকবক্সটা
 *    তুলে দিলে ভেতর থেকে আর কেউ কোনোদিন ঢুকতে পারতেন না, আর ফেরার
 *    একমাত্র পথ হত SSH আর `tinker` — যেটা মালিক পারবেন না।
 * ⓶ **দ্বিতীয় owner বানানো যায় না।** একজনই।
 *
 * ⚠️ এই দুইটা নিয়ম **একা একা** লিখলে একটা ফাঁদ তৈরি হত: owner চলে
 * গেলে, মারা গেলে বা অ্যাকাউন্ট হারালে নতুন কাউকে বসানোর কোনো উপায়ই
 * থাকত না — ⓵ পুরনোজনকে সরাতে দিত না, আর ⓶ নতুনজনকে বসাতে দিত না।
 * তালাটা তখন নিরাপত্তা নয়, কবর হত।
 *
 * ⭐ তাই তৃতীয় জিনিসটা অপরিহার্য: **হস্তান্তর**। owner রোলটা সরানো যায়
 * না, কেবল হাতবদল হয় — `transfer()` এক লেনদেনে দেয় আর নেয়, তাই
 * সংখ্যাটা কখনো ২ হয় না, কখনো ০-ও হয় না।
 *
 * ── ⚠️ দুইটা আলাদা "মালিক", একই শব্দ ─────────────────────────────────
 * অর্থ মডিউলের `finance::who.owner` = "মালিক" **সম্পূর্ণ আলাদা জিনিস** —
 * ওটা বলে কার টাকা ব্যবসায় খাটছে (মূলধনের খাতা), কে সিস্টেম চালায় তা
 * নয়। যিনি কেবল টাকা দেন তাঁর কোনো অ্যাকাউন্টই লাগে না। এখানকার
 * `owner` মানে **সিস্টেমের চাবি**, আর ওখানকার "মালিক" মানে **টাকার
 * উৎস**। একই শব্দ দুই অর্থে বসে আছে, আর সেটাই বিভ্রান্তির উৎস।
 */
final class Ownership
{
    public function __construct(private readonly AuditEngine $audit) {}

    /**
     * রোলের নামটা এখানে আবার লেখা হয়নি — ইচ্ছাকৃতভাবে।
     *
     * ⓘ `PermissionSyncer::SUPER_ADMIN_ROLE` ইতিমধ্যেই ঐ নামের একমাত্র উৎস,
     * আর সেটা `CompanyProvisioner`, `RoleController` ও `GovernanceChecks`
     * তিন জায়গা থেকে পড়া হয়। এখানে দ্বিতীয় একটা ধ্রুবক বসালে একই
     * সংখ্যার দ্বিতীয় কপি তৈরি হত — আর তখন কেউ একটা বদলালে অন্যটা
     * নীরবে দ্বিমত করত।
     *
     * ⚠️ প্রাসঙ্গিক, কারণ নামটা বদলাতে পারে: ERP-র জগতে কেউ "owner"
     * বলে না — Odoo, Tally, NetSuite সবাই বলে Administrator। সিদ্ধান্ত
     * এলে বদলটা যেন **এক লাইনের** হয়, সেজন্যই উৎসটা একটাই রাখা।
     */
    public function role(): string
    {
        return PermissionSyncer::SUPER_ADMIN_ROLE;
    }

    /**
     * এই কোম্পানিতে যাঁরা সত্যিই owner হিসেবে ঢুকতে পারেন।
     *
     * ── তিনটা শর্তই লাগে, আর প্রতিটার কারণ আলাদা ─────────────────────
     * ⓵ `model_has_roles`-এ এই কোম্পানির জন্য owner রোল — রোল এখন
     *    কোম্পানির ভেতরে বাঁধা (কমিট `245fd97`), তাই গণনাটা **কোম্পানি
     *    ধরে**; বিশ্বজনীন গণনা এক কোম্পানির owner-কে অন্য কোম্পানির
     *    পাহারায় গুনত। ⓘ একজন মানুষ দুই কোম্পানিতে owner হতে পারেন, আর
     *    সেটা বৈধ — `DemoSeeder` ঠিক সেটাই করে।
     * ⓶ ব্যবহারকারী নিজে সক্রিয় — নিষ্ক্রিয় owner দরজা খুলতে পারেন না,
     *    তাই তাঁকে গুনলে "একজন আছেন" বলে আশ্বস্ত হওয়া যেত অথচ ভেতরে
     *    কেউ থাকত না।
     * ⓷ কোম্পানিতে তাঁর প্রবেশ সক্রিয় (`company_user.is_active`) — রোল
     *    থেকেও প্রবেশ কেড়ে নেওয়া থাকলে তিনি ঐ কোম্পানির কিছুই দেখেন না।
     *
     * ── ⚠️ এখানে `withoutGlobalScopes()` **নেই**, আর সেটাও ইচ্ছাকৃত ───
     * নিয়মটা (অনুপস্থিতি প্রমাণে স্কোপ ছাড়া) `BelongsToCompany` মডেলের
     * জন্য, যেখানে গ্লোবাল স্কোপ কোম্পানি ধরে ছাঁকে। ⓘ `User` ওই দলে
     * নয় — সে বিশ্বজনীন (`users` টেবিলে `company_id` নেই), কোম্পানির
     * সাথে সম্পর্ক pivot দিয়ে, আর সেটা উপরে হাতে ছাঁকা হয়েছে।
     *
     * ⛔ উল্টো দিকে, `User`-এ `SoftDeletes` আছে — তাই এখানে অন্ধভাবে
     * `withoutGlobalScopes()` লিখলে **মুছে ফেলা ব্যবহারকারীরাও গণনায়
     * ফিরত**, আর তখন দুইটা নিয়মই ভুল দিকে যেত: একটা মৃত owner দেখে
     * পাহারা ⓵ আসল শেষজনকে সরাতে দিত, আর ⓶ নতুন কাউকে বসাতে দিত না।
     *
     * @return Collection<int, User>
     */
    public function activeOwnersIn(int $companyId): Collection
    {
        return User::query()
            ->where('users.is_active', true)
            ->whereIn('users.id', function ($query) use ($companyId) {
                $query->select('mhr.model_id')
                    ->from('model_has_roles as mhr')
                    ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                    ->where('mhr.model_type', User::class)
                    ->where('mhr.company_id', $companyId)
                    ->where('r.name', $this->role());
            })
            ->whereExists(function ($query) use ($companyId) {
                $query->select(DB::raw(1))
                    ->from('company_user as cu')
                    ->whereColumn('cu.user_id', 'users.id')
                    ->where('cu.company_id', $companyId)
                    ->where('cu.is_active', true);
            })
            ->orderBy('users.id')
            ->get();
    }

    /** ইনি কি এই কোম্পানির সক্রিয় owner? */
    public function isOwnerIn(User $user, int $companyId): bool
    {
        return $this->activeOwnersIn($companyId)->contains('id', $user->id);
    }

    /**
     * নিয়ম ⓶ — দ্বিতীয় owner বসানো যাবে না।
     *
     * ⭐ শর্তটা "একজনও নেই" নয়, "**ইনি ছাড়া** আর কেউ নেই" — আর ঐ
     * পার্থক্যটাই প্রথম সেটআপ ও `DemoSeeder` দুইটাকেই অক্ষত রাখে:
     * · একদম নতুন কোম্পানিতে owner শূন্য, তাই প্রথমজন নির্বিঘ্নে বসেন
     *   (`CompanyProvisioner::grantAccess()`) — আলাদা কোনো ব্যতিক্রম
     *   লিখতে হয়নি, আর **না-লেখা ব্যতিক্রমই সবচেয়ে নিরাপদ**।
     * · যিনি ইতিমধ্যেই owner, তাঁর সারি আবার সংরক্ষণ করলে বাধা আসে না।
     */
    public function assertMayBecomeOwner(User $user, int $companyId, string $field = 'roles'): void
    {
        $owners = $this->activeOwnersIn($companyId);

        /*
         * ── ইনি যদি **আগে থেকেই** মালিক হন, তাহলে কিছুই "হচ্ছে" না ─────
         * নিয়মটা মালিক **হওয়া** ঠেকায়, মালিক **থাকা** নয়।
         *
         * ⛔ এই লাইনটা ছাড়া একটা ফাঁদ ছিল: কোনো কারণে একটা কোম্পানিতে
         * দুইজন মালিক থাকলে (পুরনো কোনো ইনস্টল, বা হাতে বসানো সারি)
         * তাঁদের **কারো** সারি আর সংরক্ষণ করাই যেত না — এমনকি শুধু নাম
         * শোধরাতে গেলেও "ইতিমধ্যে একজন মালিক আছেন" বলে আটকাত।
         *
         * ⚠️ অর্থাৎ তালাটা তখন ভাঙা অবস্থাটা সারানোর পথটাই বন্ধ করে
         * রাখত — আর যে নিরাপত্তা নিজের ভুল শোধরাতে দেয় না, মানুষ তার
         * চারপাশ দিয়ে ঘোরার পথ খোঁজে।
         */
        if ($owners->contains('id', $user->id)) {
            return;
        }

        if ($owners->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('system_admin::validation.owner_already_exists', [
                'name' => $owners->first()?->name ?? '',
            ]),
        ]);
    }

    /**
     * নিয়ম ⓵ — এই বদলের পরেও কোম্পানিতে একজন owner থাকতে হবে।
     *
     * ⓘ প্রশ্নটা "ইনি owner কি না" নয়, "**ইনি সরে গেলে কেউ থাকে কি
     * না**"। তাই যিনি owner নন তাঁর বেলায় কিছুই ঘটে না, আর যিনি owner
     * কিন্তু একা নন তাঁর বেলায়ও নয়।
     *
     * @param  bool  $keepsRole  বদলের পরেও owner রোলটা থাকছে?
     * @param  bool  $staysActive  বদলের পরেও অ্যাকাউন্টটা সক্রিয় থাকছে?
     */
    public function assertCompanyKeepsAnOwner(
        User $user,
        int $companyId,
        bool $keepsRole,
        bool $staysActive,
        string $field = 'roles',
    ): void {
        if ($keepsRole && $staysActive) {
            return;
        }

        if (! $this->isOwnerIn($user, $companyId)) {
            return;
        }

        $remaining = $this->activeOwnersIn($companyId)
            ->reject(fn (User $owner) => $owner->id === $user->id);

        if ($remaining->isNotEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('system_admin::validation.last_owner_must_remain'),
        ]);
    }

    /**
     * এই মানুষটা মুছে গেলে কোনো কোম্পানি মালিকহীন হয়ে পড়ে কি না।
     *
     * ── কেন এখানে প্রশ্নটা আলাদা ─────────────────────────────────────
     * উপরের দুইটা পাহারা **একটা কোম্পানির প্রসঙ্গে** দাঁড়িয়ে চলে — কেউ
     * একটা পর্দায় বসে একটা কোম্পানির রোল বদলাচ্ছেন। ⛔ কিন্তু মোছা
     * ব্যক্তিটাকে **সব কোম্পানি থেকে** একসাথে সরায়, আর সেটা প্রায়ই ঘটে
     * এমন জায়গা থেকে যেখানে কোনো কোম্পানির প্রসঙ্গই বসানো নেই (কনসোল,
     * কমান্ড, সিডার)।
     *
     * ⚠️ তাই "চলতি কোম্পানিতে কী হবে" জিজ্ঞেস করলে উত্তরটা হত `null`,
     * আর তখন পাহারাটা চুপচাপ পাশ করত — অর্থাৎ ঠিক সেই আকারের ভুল যেটা
     * এই রিপোতে এক দিনে পাঁচবার ধরা পড়েছে: সবুজ, অথচ কিছুই দেখেনি।
     *
     * ⭐ তাই প্রশ্নটাই বদলানো হয়েছে: *"ইনি যে যে কোম্পানির মালিক, তার
     * কোনোটা কি ইনি চলে গেলে খালি হয়ে যায়?"* — প্রসঙ্গ লাগে না, আর
     * ফাঁকও থাকে না।
     */
    public function assertNoCompanyLosesItsOwner(User $user, string $field = 'user'): void
    {
        $companyIds = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', User::class)
            ->where('mhr.model_id', $user->id)
            ->where('r.name', $this->role())
            ->pluck('mhr.company_id');

        foreach ($companyIds as $companyId) {
            $remaining = $this->activeOwnersIn((int) $companyId)
                ->reject(fn (User $owner) => $owner->id === $user->id);

            if ($remaining->isEmpty()) {
                throw ValidationException::withMessages([
                    $field => __('system_admin::validation.last_owner_must_remain'),
                ]);
            }
        }
    }

    /**
     * চাবিটা হাতবদল — এক লেনদেনে, দুইটা কাজ একসাথে।
     *
     * ── কেন এক লেনদেনে ───────────────────────────────────────────────
     * ⛔ আলাদা দুই ধাপে করলে মাঝখানে একটা মুহূর্ত থাকত যেখানে হয় দুইজন
     * owner (তালা ভাঙা), নয়তো শূন্যজন (সবাই বাইরে)। ⓘ আর ঠিক ঐ
     * মুহূর্তে প্রসেসটা মরে গেলে ব্যবস্থাটা স্থায়ীভাবে ঐ অবস্থায় থেকে
     * যেত — দ্বিতীয় ক্ষেত্রে কেউ আর ভেতরে ঢুকতেই পারতেন না।
     *
     * ── কেন রোল বসানোর আগে প্রবেশাধিকার ──────────────────────────────
     * নতুন owner ঐ কোম্পানিতে না থাকলে রোলটা এমন একজনের উপর বসত যিনি
     * কোম্পানিটাই খুলতে পারেন না — নিয়ম মতে owner আছেন, বাস্তবে নেই।
     */
    public function transfer(User $from, User $to, int $companyId): void
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'user_id' => __('system_admin::validation.owner_transfer_to_self'),
            ]);
        }

        if (! $this->isOwnerIn($from, $companyId)) {
            throw ValidationException::withMessages([
                'user_id' => __('system_admin::validation.owner_transfer_not_owner'),
            ]);
        }

        /*
         * ── ⛔ নিষ্ক্রিয় কারো হাতে চাবি দেওয়া যায় না ────────────────────
         *
         * ⚠️ এই যাচাইটা প্রথম খসড়ায় ছিল না, আর অনুপস্থিতিটা ঠিক সেই ফলটা
         * ঘটাত যেটা ঠেকাতে পুরো শ্রেণিটা লেখা: `activeOwnersIn()` গণনা
         * করে `users.is_active` ধরে, তাই নিষ্ক্রিয় কাউকে হস্তান্তরের পর
         * কোম্পানিতে **একজনও সক্রিয় মালিক থাকত না**।
         *
         * ── কীভাবে ওখানে পৌঁছানো যেত ─────────────────────────────────
         * ⓘ ক্লিক করে নয় — পাতাটা কেবল সক্রিয় প্রার্থী দেখায়। ⚠️ কিন্তু
         * পথটা দুর্ভাবনার নয়, **সময়ের**: মালিক হস্তান্তরের পাতা খুলে
         * রেখেছেন, এর মধ্যে একজন প্রশাসক ঐ মানুষটিকে নিষ্ক্রিয় করলেন,
         * তারপর মালিক জমা দিলেন। কোনো আক্রমণ লাগে না — কেবল সময়।
         *
         * ⓘ এটা `is_active` ঘরানার সব যাচাইয়ের একই রোগ: পর্দা যা
         * দেখিয়েছিল আর জমা যা পাঠায়, দুইটার মাঝে সময় গড়ায়। তাই
         * সিদ্ধান্তটা পর্দার নয়, **লেখার মুহূর্তের**।
         */
        if (! $to->is_active) {
            throw ValidationException::withMessages([
                'user_id' => __('system_admin::validation.owner_transfer_to_inactive', [
                    'name' => $to->name,
                ]),
            ]);
        }

        DB::transaction(function () use ($from, $to, $companyId) {
            $to->companies()->syncWithoutDetaching([$companyId]);

            CompanyContext::forCompany($companyId, function () use ($from, $to) {
                $to->assignRole($this->role());
                $from->removeRole($this->role());
            });

            /*
             * ⓘ অডিট সারিটা **নতুন** owner-এর নামে, কারণ নিরীক্ষার প্রথম
             * প্রশ্নটা "এই চাবিটা এখন কার হাতে, আর কবে থেকে"। আগের
             * জনের নামটা কারণের লেখায় থাকে, তাই দুই দিকই পড়া যায়।
             */
            $this->audit->recordAction($to, 'ownership_transferred',
                $from->name.' ('.$from->email.') → '.$to->name.' ('.$to->email.')');

            /*
             * ── ⭐ কাজটা সত্যিই হয়েছে কি না, **গুনে** দেখা ────────────────
             *
             * উপরের যাচাইটা একটা **জানা** কারণ ঠেকায় (নিষ্ক্রিয় গ্রহীতা)।
             * ⚠️ কিন্তু `activeOwnersIn()` তিনটা শর্ত দেখে — ব্যবহারকারী
             * সক্রিয়, কোম্পানিতে রোল, আর প্রবেশ সক্রিয় — আর ভবিষ্যতে
             * সেখানে চতুর্থ একটা শর্ত যোগ হতে পারে, বা spatie-র রোল বসানো
             * নীরবে ব্যর্থ হতে পারে।
             *
             * ⛔ তখন পুরনো মালিকের রোল চলে যেত, নতুনজন গণনায় আসতেন না, আর
             * কোম্পানিটা **তালাবদ্ধ** হয়ে থাকত — কোনো ত্রুটি ছাড়াই, কারণ
             * প্রতিটা ধাপ আলাদাভাবে সফল।
             *
             * ⭐ তাই লেনদেনের ভিতরেই শেষ প্রশ্নটা করা হয়: *"এখন কি সত্যিই
             * একজন সক্রিয় মালিক আছেন?"* উত্তর না হলে ব্যতিক্রম, আর ব্যতিক্রম
             * মানে পুরো লেনদেনটা ফিরে যায় — পুরনো মালিক তাঁর রোল ফেরত পান।
             *
             * ⓘ অর্থাৎ পাহারাটা অনুমান করে না যে সে কাজ করেছে; **দেখে নেয়**।
             */
            if ($this->activeOwnersIn($companyId)->isEmpty()) {
                throw ValidationException::withMessages([
                    'user_id' => __('system_admin::validation.owner_transfer_left_nobody'),
                ]);
            }
        });
    }
}
