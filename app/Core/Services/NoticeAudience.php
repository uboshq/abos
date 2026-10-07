<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * এই নোটিশটা এই মানুষটার জন্য কি না — একটাই উত্তর, একটাই জায়গা।
 *
 * ── ⚠️ কেন একটাই জায়গা ──────────────────────────────────────────────
 * প্রশ্নটা আসে অন্তত চার জায়গা থেকে: নিচের বার, নোটিশের তালিকা, একক
 * নোটিশের পাতা, আর অপঠিতের গোনা। ⛔ চার জায়গায় চারবার লিখলে একদিন
 * একটায় শাখার শর্ত যোগ হত, অন্যটায় নয় — আর তখন তালিকায় না থাকা একটা
 * নোটিশ সরাসরি ঠিকানা লিখে খোলা যেত।
 *
 * ⓘ ঠিক এই আকারের ফাঁক ABOS-এ আগেও হয়েছে: তালিকা ছেঁকে রাখা আর পাতা
 * পাহারা দেওয়া দুইটা আলাদা কাজ, আর দ্বিতীয়টা ভুলে যাওয়া সহজ।
 *
 * ── ⓘ কোর যে চারটা চেনে ─────────────────────────────────────────────
 * `company` · `branch` · `role` · `user` — চারটাই কোরের নিজের টেবিল।
 *
 * ⛔ বিভাগ, পদ আর কর্মী কোরের সম্পত্তি নয়, আর কোর কোনো মডিউলের নাম
 * জানতে পারে না ([[BoundariesTest]], §১৯.৭)। ⚠️ তাই ওগুলো যে মডিউলের,
 * সে নিজে এসে যোগ করে — [[extend()]] দিয়ে, তার service provider থেকে।
 * ⓘ কোর ডাক পায়, ডাকে না।
 */
final class NoticeAudience
{
    /**
     * মডিউলের যোগ করা স্তরগুলো।
     *
     * @var array<string, callable(User): list<string>>
     */
    private array $extra = [];

    /**
     * ⭐ একটা মডিউল নিজের স্তর যোগ করে।
     *
     * ⓘ যেমন HR তার service provider-এ:
     *
     *     app(NoticeAudience::class)->extend('department', fn (User $u) =>
     *         Employee::forUser($u)?->department_id ? ['department:'.$id] : []);
     *
     * ⚠️ ফেরত আসে **চাবির তালিকা**, একটা id নয় — ⓘ একজন মানুষ একাধিক
     * শাখা বা একাধিক দলে থাকতে পারেন, আর সেটা মডিউলের নিজের কথা।
     *
     * @param  callable(User): list<string>  $keys
     */
    public function extend(string $kind, callable $keys): void
    {
        $this->extra[$kind] = $keys;
    }

    /**
     * এই মানুষটা যে যে চাবির আওতায় পড়েন।
     *
     * ⚠️ ভূমিকার বেলায় id নয়, **নাম** — ⓘ Spatie-তে ভূমিকার id
     * কোম্পানিভেদে আলাদা, আর নোটিশ এক কোম্পানি থেকে অন্যটায় নকল হলে
     * id-টা অন্য কারও ভূমিকায় গিয়ে পড়ত, নীরবে।
     *
     * @return list<string>
     */
    public function keysFor(User $user): array
    {
        $keys = ['user:'.$user->getKey()];

        /*
         * ⚠️ কোম্পানি ও শাখা আসে চলতি প্রসঙ্গ থেকে, ব্যবহারকারীর সারি
         * থেকে নয় — আর তফাতটা দামি।
         *
         * ⓘ একজন মানুষ কয়েকটা কোম্পানিতে থাকতে পারেন, আর
         * `current_company_id` বলে তিনি **এখন কোথায় দাঁড়িয়ে**। ⛔ সারির
         * ঘরটা ধরলে কোম্পানি বদলানোর পরেও পুরনো কোম্পানির নোটিশ দেখা
         * যেত, আর সেটাই স্পেকের ১০ নম্বর ধারার ঠিক উল্টো।
         */
        $companyId = CompanyContext::id() ?? $user->current_company_id;
        $branchId = CompanyContext::branchId() ?? $user->current_branch_id;

        if ($companyId !== null) {
            $keys[] = 'company:'.$companyId;
        }

        if ($branchId !== null) {
            $keys[] = 'branch:'.$branchId;
        }

        foreach ($user->getRoleNames() as $role) {
            $keys[] = 'role:'.$role;
        }

        /*
         * ⚠️ মডিউলের যোগ করা স্তরগুলো — আর তাদের ভুল যেন গোটা পর্দা
         * না নামায়।
         *
         * ⛔ একটা মডিউলের resolver ব্যতিক্রম ছুড়লে গোটা নোটিশের
         * তালিকাটাই সাদা হয়ে যেত, অথচ দোষটা ঐ মডিউলের। ⓘ তাই এখানে
         * ধরা হয়: ঐ স্তরটা এবারের জন্য বাদ পড়ে, বাকিটা চলে।
         */
        foreach ($this->extra as $kind => $resolve) {
            try {
                foreach ($resolve($user) as $key) {
                    $keys[] = $key;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * ⭐ এই মানুষটা যে নোটিশগুলো দেখবেন।
     *
     * ── ⓘ কোনো লক্ষ্য বসানো না থাকলে সবাই দেখেন ─────────────────────
     * ⚠️ উল্টোটা ধরলে লক্ষ্য বসাতে ভুলে যাওয়া নোটিশটা **কেউই** দেখত
     * না, আর লেখক ভাবতেন পাঠানো হয়ে গেছে। ⛔ নীরবে না-পৌঁছানো নোটিশের
     * চেয়ে বেশি মানুষের কাছে পৌঁছানো নিরাপদ — একই যুক্তি
     * [[Notice::scopeForRoles]]-এও।
     *
     * ⓘ কোম্পানির সীমাটা এখানে লেখা হয়নি, ইচ্ছাকৃতভাবে: [[Notice]]
     * `BelongsToCompany` ব্যবহার করে, তাই প্রতিটা কোয়েরি এমনিতেই চলতি
     * কোম্পানিতে বাঁধা। ⚠️ এখানে আবার লিখলে দুই জায়গায় দুইটা নিয়ম
     * থাকত, আর একদিন একটা বদলাত।
     *
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $keys = $this->keysFor($user);

        return $query->where(function (Builder $q) use ($keys): void {
            $q->whereDoesntHave('targets')
                ->orWhereHas('targets', fn (Builder $t) => $t->whereIn('match_key', $keys));
        });
    }

    /**
     * ⛔ একক নোটিশের পাতার পাহারা।
     *
     * ── ⚠️ কেন তালিকার ছাঁকনি যথেষ্ট নয় ─────────────────────────────
     * ⓘ তালিকায় না দেখানো আর **খুলতে না দেওয়া** এক জিনিস নয়। ⛔ ঠিকানা
     * জানা থাকলে যেকোনো নোটিশ খোলা যেত, আর ঠিকানাটা ক্রমিক সংখ্যা —
     * অনুমান করা সহজ।
     *
     * ⓘ ABOS-এ এই আকারের ভুল আগেও হয়েছে, তাই উত্তরটা এখানেই, তালিকার
     * পাশেই।
     */
    public function reaches(Notice $notice, User $user): bool
    {
        $targets = $notice->relationLoaded('targets')
            ? $notice->getRelation('targets')
            : $notice->targets()->get();

        if ($targets->isEmpty()) {
            return true;
        }

        $keys = $this->keysFor($user);

        return $targets->contains(fn ($target) => in_array($target->match_key, $keys, true));
    }

    /**
     * একটা নোটিশের লক্ষ্য বসানো — যা ছিল তার বদলে।
     *
     * ⚠️ পুরনোগুলো মুছে নতুন বসে, যোগ হয় না। ⓘ সম্পাদনার পর্দায় মানুষ
     * টিক **তুলেও** দেন, আর যোগ করলে তোলা টিকটা কোনোদিন কাজ করত না।
     *
     * @param  list<string>  $keys
     */
    public function aimAt(Notice $notice, array $keys): void
    {
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($key) => trim((string) $key), $keys),
            fn (string $key) => $key !== '' && str_contains($key, ':'),
        )));

        $notice->targets()->delete();

        foreach ($clean as $key) {
            $notice->targets()->create([
                'company_id' => $notice->company_id,
                'match_key' => $key,
            ]);
        }

        $notice->unsetRelation('targets');
    }
}
