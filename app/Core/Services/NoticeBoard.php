<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\Notice;
use App\Models\NoticeDismissal;
use App\Models\NoticeRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * কে কোন নোটিশ দেখবেন — প্রশ্নটার একটাই উত্তর, একটাই জায়গায়।
 *
 * ── ⛔ কেন এটা একটা সেবা, দুই জায়গায় দুইটা কোয়েরি নয় ─────────────────
 * উত্তরটা **দুইবার** দরকার: বোর্ডের তালিকায়, আর নিচের চলন্ত বারে।
 * ⚠️ দুই জায়গায় দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা পুরনো নিয়মে
 * চলত — আর পার্থক্যটা **নীরব** হত: বারে একটা নোটিশ ঘুরত যেটা বোর্ডে
 * খুলে পড়া যেত না, বা উল্টোটা।
 *
 * ⓘ আজকের দিনেই এই ভুলটা রিপোতে দুইবার ধরা পড়েছে — সইকারীর তালিকা আর
 * ফরওয়ার্ডের বাছাই, আর ইনবক্সের কোয়েরি ও বোতামের নিয়ম।
 */
final class NoticeBoard
{
    /**
     * ⭐ এই মানুষটা আজ যে নোটিশগুলো দেখবেন।
     *
     * ⚠️ ভূমিকাগুলো **নাম ধরে** নেওয়া হয়, আর সেটা চলতি কোম্পানির
     * ভূমিকাই ([[Notice::scopeForRoles()]]-এ কারণ লেখা)।
     *
     * @return Collection<int, Notice>
     */
    public function forUser(User $user, ?Carbon $day = null): Collection
    {
        return $this->queryFor($user, $day)->get();
    }

    /**
     * ⭐ একই উত্তর, তবে সারি নয় — কোয়েরি।
     *
     * ── ⚠️ কেন এই দুইটা আলাদা ─────────────────────────────
     * ⓘ পর্দা আর বার সব সারি চায় — সংখ্যাটা একটা অফিসে ছোট।
     * ⛔ কিন্তু API-তে নয়: যে ক্লায়েন্ট রোজ পোল করে, সে ছয় মাস
     * পরে প্রতিটা কলে গোটা টেবিল টানত, আর একদিন মেমরি শেষ।
     *
     * ⓘ ধরা পড়েছে [[EveryListScreenPaginatesTest]]-এ, abos-41-এর রানে —
     * ⚠️ আর সেটাই প্রমাণ যে পুরো ডিরেক্টরি চালানোর নিয়মটা অলংকার
     * নয়: পাহারাটা নোটিশের সাথে কোনো সম্পর্কের নয়, তবু সেই ধরল।
     *
     * @return \Illuminate\Database\Eloquent\Builder<Notice>
     */
    public function queryFor(User $user, ?Carbon $day = null)
    {
        $query = Notice::query()
            ->liveOn($day ?? Carbon::today())
            // ⛔ কেবল প্রকাশিত ([[NoticeStatus::isLive()]]) — খসড়া, ফেরানো, স্থগিত, বাতিল কর্মীর তালিকায়, গোনায় আর ফোনে নয়
            // (Notice লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[OnlyAPublishedNoticeReachesStaffTest]])। খসড়াও `is_active` জন্মায়, তাই সুইচ যথেষ্ট নয়।
            ->where('status', \App\Core\Support\NoticeStatus::PUBLISHED->value)
            ->with(['author', 'targets']);

        /*
         * ⭐ ছয় স্তরে লক্ষ্য — ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ আগে এখানে `forRoles()` ছিল, আর ওটা কেবল ভূমিকা চিনত।
         * ⚠️ *"ঢাকা শাখার সবাইকে"* বা *"শুধু রহিম সাহেবকে"* বলার
         * কোনো পথ ছিল না।
         *
         * ⛔ পুরনো সারিগুলো হারায়নি: মাইগ্রেশনে `notice_roles`-এর
         * প্রতিটা সারি `role:<নাম>` চাবি হয়ে নতুন ঘরে বসেছে।
         */
        $query = app(NoticeAudience::class)->scopeVisibleTo($query, $user);

        return $query
            ->orderByDesc('starts_on')
            ->orderByDesc('id');
    }

    /**
     * ⭐ নিচের চলন্ত বারে যেগুলো যাবে।
     *
     * ⓘ ঐ তালিকাটা উপরেরটারই একটা ছাঁকা রূপ — আলাদা কোয়েরি নয়। ⚠️
     * আলাদা করে লিখলে দুইটা নিয়ম হত, আর এই ফাইলটা যে কারণে আছে সেটাই
     * ভেঙে যেত।
     *
     * @return Collection<int, Notice>
     */
    public function forTicker(User $user, ?Carbon $day = null): Collection
    {
        /*
         * ⭐ জরুরিটা এখানে নয় — মালিক, ২১ অক্টোবর ২০২৬: *"জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত"*।
         * ⓘ জরুরি টিক দেওয়া নোটিশ উপরের স্থির ফিতায় ([[forStrip()]]); একই নোটিশ দুই জায়গায় কখনো নয়।
         *
         * ⭐ বাকি প্রতিটা প্রকাশিত ঘোষণা এখানে — সাধারণ অগ্রাধিকারেরও (fe, ২১ অক্টোবর ২০২৬; মালিকের নিয়ম: কিছুই না করা
         * বোতাম থাকবে না)। ⛔ আগে বারে উঠত কেবল "গুরুত্বপূর্ণ" বা তার উপরে (`in_ticker`, অগ্রাধিকার দেখে বসানো), আর ফর্মের
         * "নিচের চলন্ত বারেও দেখান" টিকটা কিছুই করত না — লেখক টিক দিতেন, নোটিশ বারে উঠত না। ⓘ টিক উঠে গেছে, ফটকও; ঘরটা
         * টেবিলে রইল, কেউ পড়ে না। ভিড় সামলায় ক্রম (অগ্রাধিকার, তারপর নতুনটা) আর সীমা ([[howManyFitOnTheBar()]])।
         */
        return $this->riding($this->forUser($user, $day)->where('is_urgent', false), $user);
    }

    /**
     * ⭐ উপরের স্থির ফিতায় যেগুলো যাবে — লেখক নিজে "জরুরি" টিক দিয়েছেন (মালিক, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ অগ্রাধিকার যা-ই হোক — জায়গা ঠিক করে কেবল লেখকের টিক।
     *
     * @return Collection<int, Notice>
     */
    public function forStrip(User $user, ?Carbon $day = null): Collection
    {
        return $this->riding($this->forUser($user, $day)->where('is_urgent', true), $user);
    }

    /**
     * ⓘ দুই বারের সব — জরুরিগুলো আগে। ফোনের বারের জন্য ([[NoticeApiController::bar()]]): ফোনে একটাই লাইন, তাই সেখানে
     * দুইটাই যায়, আর ভাগটা ফোনের নিজের পর্দার।
     *
     * @return Collection<int, Notice>
     */
    public function onTheBars(User $user, ?Carbon $day = null): Collection
    {
        return $this->forStrip($user, $day)->concat($this->forTicker($user, $day))->values();
    }

    /**
     * ⓘ বারে ওঠার বাকি নিয়ম — দুই বারের একই: সরানো বাদ, মেয়াদ পেরোনো বাদ, অগ্রাধিকার ধরে সাজানো, সীমা মেনে।
     *
     * @param  Collection<int, Notice>  $wanted
     * @return Collection<int, Notice>
     */
    private function riding(Collection $wanted, User $user): Collection
    {
        /*
         * ⛔ মেয়াদের ঘড়ি পেরোলে বারে নয় — নিজে থেকেই (মালিক, ২১ অক্টোবর ২০২৬: মেয়াদ পেরোলে নিজে থেকে উধাও)।
         * ⓘ শেষ তারিখ (`ends_on`) [[Notice::scopeLiveOn()]] আগেই ধরে; `expires_at` ঘণ্টা ধরে, আর ওটাকে অবস্থায় বদলায় সময়ের
         * কাজ — সেটা চলার আগের ফাঁকে বারে একটা মেয়াদ পেরোনো ঘোষণা ঘুরত।
         */
        $wanted = $wanted->reject(fn (Notice $notice) => $notice->expires_at !== null && $notice->expires_at->isPast());

        /*
         * ⭐ ক্রমটা অগ্রাধিকার ধরে, তারপর নতুনটা আগে।
         *
         * ⚠️ সাজানোটা PHP-তে, SQL-এ নয় — আর সেটা ইচ্ছাকৃত।
         * ⓘ অগ্রাধিকারের ক্রম লেখার বর্ণমালায় নয় (`critical` <
         * `low` < `normal`), আর SQL-এ করতে গেলে একটা `CASE` লিখতে
         * হত যেটা [[NoticePriority]]-এর সংখ্যাগুলোর দ্বিতীয় কপি।
         * ⛔ দুই কপি একদিন আলাদা হত, আর জরুরি নোটিশটা নিচে নেমে
         * যেত — নীরবে।
         *
         * ⓘ সংখ্যাটা ছোট (বারে কয়টা ধরে?), তাই PHP-তে সাজানোর দাম নেই।
         */
        /*
         * ⛔ যেগুলো এই মানুষটা সরিয়ে দিয়েছেন — তবে সবগুলো নয়।
         *
         * ⓘ যে নোটিশ সরানোই যায় না (CRITICAL আর তার উপরে), তার
         * সারি থাকলেও সে ফিরে আসে। ⚠️ কারণ অগ্রাধিকার পরেও বাড়তে
         * পারে: গতকাল যেটা সাধারণ ছিল, আজ সেটাই জরুরি হতে পারে।
         * ⛔ সরানোটা চিরকালের ধরলে ওই বদলটা কারও চোখে পড়ত না।
         */
        $pushedAside = NoticeDismissal::query()
            ->where('user_id', $user->getKey())
            ->pluck('notice_id')
            ->all();

        return $wanted
            ->reject(fn (Notice $notice) => in_array($notice->id, $pushedAside, true)
                && ($notice->priority?->canBeDismissed() ?? true))
            // ⭐ অগ্রাধিকার আগে (বেশিটা আগে), তারপর নতুনটা আগে — সমান অগ্রাধিকারে নতুন লেখা পুরনোকে বার থেকে ঠেলে নামায়
            ->sort(fn (Notice $a, Notice $b) => [$b->priority?->rank() ?? 0, $b->id] <=> [$a->priority?->rank() ?? 0, $a->id])
            ->take($this->howManyFitOnTheBar())
            ->values();
    }

    /**
     * বারে একসাথে কয়টা নোটিশ ধরে।
     *
     * ── ⚠️ কেন এর একটা সীমা লাগে ───────────────────────
     * ⓘ একটা অফিসে যেকোনো দিন পাঁচ-ছয়টা নোটিশ সক্রিয় থাকে।
     * ⛔ সবগুলো একসাথে বারে দিলে জরুরি কথাটা ভিড়ে হারায়, আর
     * তখন বারটা মানুষ পড়াই বন্ধ করে দেয়।
     *
     * ⚠️ আর ঠিক সেদিনই আগুন লাগার নোটিশটা ওখানে থাকে।
     */
    private function howManyFitOnTheBar(): int
    {
        $many = (int) app(SettingsService::class)->get('notice.bar_max', 3);

        /* ⓘ শূন্য বা বিয়োগ বসালে বারটা নীরবে উধাও হয়ে যেত */
        return max(1, $many);
    }

    /**
     * ⓘ পড়া হয়েছে বলে দাগ দেওয়া — একবারই।
     *
     * ⚠️ `firstOrCreate` ইচ্ছাকৃত: দ্বিতীয়বার খুললে **প্রথমবারের সময়টাই**
     * থাকে। ⛔ প্রতিবার বসালে *"কবে প্রথম দেখেছিলেন"* প্রশ্নের উত্তর
     * হারাত, আর ঐটাই একমাত্র প্রশ্ন যার জন্য হিসাবটা রাখা।
     */
    /**
     * ⛔ নিজের চোখের সামনে থেকে সরিয়ে দেওয়া — পড়া নয়।
     *
     * ── ⚠️ যা সরানো যায় না তা সরে না ─────────────────────
     * ⓘ পাহারাটা এখানে, পর্দায় নয় — ⛔ বারে ক্রসটা না আঁকলেও
     * কেউ সরাসরি ঠিকানায় অনুরোধ পাঠাতে পারেন, আর তখন CRITICAL
     * নোটিশটাও নীরবে সরে যেত।
     */
    public function pushAside(Notice $notice, User $user): bool
    {
        if (! ($notice->priority?->canBeDismissed() ?? true)) {
            return false;
        }

        NoticeDismissal::query()->firstOrCreate(
            ['notice_id' => $notice->id, 'user_id' => $user->id],
            ['company_id' => $notice->company_id, 'dismissed_at' => now()],
        );

        // ⓘ সরানো নোটিশ বার থেকে সাথে সাথে — এক মিনিটের ক্যাশে নয়
        StatusNotices::forget((int) $notice->company_id, (int) $user->id);

        return true;
    }

    public function markRead(Notice $notice, User $user): void
    {
        NoticeRead::query()->firstOrCreate(
            ['notice_id' => $notice->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /**
     * ⭐ এই মানুষটার না-পড়া নোটিশ কয়টা।
     *
     * ⓘ ঘণ্টার লাল বিন্দুটা এই সংখ্যাটাই দেখায়।
     */
    public function unreadCount(User $user): int
    {
        return count($this->unreadIds($user));
    }

    /**
     * ⓘ কোনগুলো এখনো পড়া হয়নি — আইডি ধরে।
     *
     * ⚠️ পড়ার সারিগুলো **একটা কোয়েরিতে** তোলা হয়, নোটিশ ধরে ধরে নয়।
     * ⛔ নোটিশপ্রতি একটা `exists()` চালালে ঘণ্টার বিন্দুটা আঁকতে বিশটা
     * কোয়েরি লাগত, আর ওটা **প্রতিটা পাতায়** আঁকা হয়।
     *
     * @return list<int>
     */
    public function unreadIds(User $user): array
    {
        $mine = $this->forUser($user);

        if ($mine->isEmpty()) {
            return [];
        }

        $seen = NoticeRead::query()
            ->whereIn('notice_id', $mine->modelKeys())
            ->where('user_id', $user->id)
            ->pluck('notice_id')
            ->all();

        return array_values(array_diff($mine->modelKeys(), $seen));
    }
}
