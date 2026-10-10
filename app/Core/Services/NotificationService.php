<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\Drillable;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\MailReach;
use App\Core\Support\NotificationKinds;
use App\Models\Notification;
use App\Models\NotificationChoice;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserDataScope;
use App\Notifications\NewsByMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * খবর পাঠানো ও পড়া।
 *
 * ── একটাই নিয়ম, আর সেটা কড়া ─────────────────────────────────────────
 * বিজ্ঞপ্তি কেবল তখনই যায় যখন **পাওয়া মানুষটার কিছু করার বা জানার আছে**।
 * "সিস্টেম চালু হয়েছে", "রিপোর্ট তৈরি হয়েছে" — এসব পাঠালে মানুষ ঘণ্টাটা
 * দেখা বন্ধ করে দেন, আর তারপর যেদিন সত্যিকারের খবর আসে সেদিনও দেখেন না।
 *
 * ঘণ্টার একটা না-পড়া সংখ্যা তখনই কাজের, যখন সংখ্যাটা শূন্য হওয়া সম্ভব।
 *
 * ── ⭐ আর ঘণ্টাটা ভবনের বাইরেও শোনা যায় — ২২ সেপ্টেম্বর ২০২৬ ─────────
 * ⛔ এতদিন যায়নি: প্রতিটা খবর কেবল একটা সারি, আর সারিটা অ্যাপের ভিতরে।
 * যিনি লগইন করেন না তিনি কোনোদিন জানতেন না। ⓘ কারণটা ও সীমাগুলো লেখা
 * আছে `2026_11_30_100000_the_news_never_left_the_building` মাইগ্রেশনে।
 *
 * ⚠️ জোড়াটা **এখানে**, প্রতিটা ডাকার জায়গায় নয়। কারণ ছয়টা জায়গায়
 * আলাদা করে চিঠি পাঠালে সপ্তম জায়গাটা লেখার দিন ভুল হত — আর ঘণ্টা
 * বাজত, চিঠি যেত না, কোথাও কিছু লাল হত না।
 */
final class NotificationService
{
    /** @var array<int, list<string>> এই অনুরোধে কার কোন ধরন বন্ধ, একবার দেখা */
    private array $silenced = [];

    /** @var array<int, array<string, bool>> চিঠির ব্যাপারে কে কী বলেছেন, একবার দেখা */
    private array $mailChoices = [];

    /**
     * নিজের কাজ নিজে করলে নিজেকে খবর দেওয়ার মানে নেই।
     *
     * ── ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ (মালিকের স্পেক, ১০ অক্টোবর ২০২৬) ─────────────────────────────────────
     * এখন প্রতিটা খবর আগে একটা ঘটনা ([[NotificationEvent]]) — বিষয়বস্তু একবার — তারপর প্রত্যেক প্রাপকের নিজের সারি।
     *   · `$key` — idempotency চাবি: একই চাবিতে আবার ডাকলে নতুন খবর যায় না, যিনি আগে পেয়েছেন তিনি আবার পান না
     *     (একই ঘটনায় একজনের একটাই সারি)। চাবি না দিলে আগের মতো প্রতিবার নতুন খবর।
     *   · `$about` — কোন কাগজের খবর: তার শাখা খবরে বসে (শাখার দেয়াল), আর খোলার আগে দেখা হয় প্রাপক কাগজটা দেখতে পান কি না।
     *   · `$priority` — না দিলে ধরনের নিজের গুরুত্ব ([[NotificationKinds::classify()]])।
     * ⓘ ব্যবসার লেনদেনের ভিতর থেকে ডাকা হলে সারিগুলোও ঐ লেনদেনে লেখা হয় (transactional outbox) — লেনদেন ফিরে গেলে খবরও
     * যায় না; চিঠি যায় লেনদেন পাকা হওয়ার পরে।
     */
    public function send(
        User|int $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        /*
         * ⓘ নিজের কাজের খবরও — কেবল যখন কাজটার **ফল** তিনি দেখেননি: সই দিলেন, তারপর সইয়ের পরের ধাপ থেমে গেল
         * (লাইভ DRF-0008, ৭ অক্টোবর ২০২৬; [[HeldCounterSaleFinisher]])। ডিফল্টে বন্ধ — বাকি সব আগের মতো।
         */
        bool $evenToSelf = false,
        ?string $priority = null,
        ?string $key = null,
        ?Model $about = null,
    ): ?Notification {
        return $this->deliver($user, fn () => $this->event($type, $title, $body, $url, $priority, $key, $about), $type, $evenToSelf);
    }

    /**
     * একজন প্রাপকের সারি — ঘটনাটা দরকার হলে তবেই লেখা হয় (নিজের কাজ বা বন্ধ করা ধরন হলে ঘটনাও বসে না)।
     *
     * @param  \Closure(): NotificationEvent  $event
     */
    private function deliver(User|int $user, \Closure $event, string $type, bool $evenToSelf): ?Notification
    {
        $userId = $user instanceof User ? $user->id : $user;

        /*
         * নিজের করা কাজের খবর নিজের কাছে যায় না।
         *
         * নিজের ছোট খরচ নিজে অনুমোদন করলে (self_limit-এর নিচে) নিজেই
         * নিজেকে "আপনার দাবি অনুমোদিত" পাঠাত। ওরকম একটা খবর ঘণ্টায়
         * বসে থাকে, কিছু জানায় না, শুধু সংখ্যাটা বাড়ায়।
         */
        if (! $evenToSelf && $userId === Actor::userId()) {
            return null;
        }

        /*
         * ⭐ যিনি এই ধরনের খবর চান না, তাঁকে পাঠানো হয় না — ২০ সেপ্টেম্বর
         * ২০২৬, মালিকের *"বিজ্ঞপ্তির সেটিংস ta koro"*।
         *
         * ⓘ ছাঁকনিটা এখানে, পর্দায় নয়: না-দেখানো সারিও ঘণ্টার সংখ্যায়
         * গোনা হত, আর "৩টা নতুন" দেখে খুলে কিছুই না পাওয়ার চেয়ে খারাপ
         * কিছু নেই। ⛔ পছন্দ না লেখা থাকলে খবরটা যায় — চুপচাপ গিলে ফেলার
         * চেয়ে বাড়তি একটা খবর ভালো।
         */
        if (! $this->wants($userId, $type)) {
            return null;
        }

        $event = $event();

        /*
         * ⛔ একই ঘটনায় একজনের একটাই সারি — চাবি আর অনন্য সূচক মিলে (`notify_recipient_once`)। ⓘ দুইজন একসাথে একই ঘটনা
         * পাঠালেও একটাই বসে: দ্বিতীয়টা সূচকে আটকায়, আর `createOrFirst` তখন আগেরটা ফেরত দেয়।
         */
        $bell = Notification::query()->createOrFirst(
            ['event_id' => $event->id, 'user_id' => $userId],
            [
                'company_id' => $event->company_id,
                'branch_id' => $event->branch_id,
                'type' => $event->type,
                'module' => $event->module,
                'category' => $event->category,
                'priority' => $event->priority,
                'title' => $event->title,
                'body' => $event->body,
                'url' => $event->url,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
            ],
        );

        if (! $bell->wasRecentlyCreated) {
            return null;
        }

        $event->increment('recipients');

        $known = $user instanceof User ? $user : null;

        // ⓘ চিঠি লেনদেন পাকা হওয়ার পরে — লেনদেন ফিরে গেলে চিঠি যায় না, আর SMTP-র দেরি লেনদেন আটকে রাখে না
        DB::afterCommit(fn () => $this->post($bell, $known));

        return $bell;
    }

    /**
     * ঘটনাটা — চাবি থাকলে আগের ঘটনাই, নাহলে নতুন।
     */
    private function event(string $type, string $title, ?string $body, ?string $url, ?string $priority, ?string $key, ?Model $about): NotificationEvent
    {
        $kind = NotificationKinds::classify($type);

        $values = [
            'company_id' => CompanyContext::id(),
            'branch_id' => $about !== null && array_key_exists('branch_id', $about->getAttributes()) ? $about->getAttribute('branch_id') : null,
            'module' => $kind['module'],
            'type' => $type,
            'category' => $kind['category'],
            'priority' => NotificationKinds::isPriority($priority) ? $priority : $kind['priority'],
            'title' => mb_substr($title, 0, 191),
            'body' => $body === null ? null : mb_substr($body, 0, 500),
            'url' => $url,
            'subject_type' => $about instanceof Drillable ? $about::drillSourceType() : null,
            'subject_id' => $about instanceof Drillable ? (int) $about->getKey() : null,
            'actor_id' => Actor::userId(),
        ];

        if ($key === null) {
            return NotificationEvent::query()->create($values);
        }

        return NotificationEvent::query()->createOrFirst(
            ['company_id' => $values['company_id'], 'idempotency_key' => mb_substr($key, 0, 191)],
            $values,
        );
    }

    /**
     * ⭐ আর খবরটা ইনবক্সেও — যদি এই ধরনের খবর চিঠি পাওয়ার কথা থাকে।
     *
     * ── ⛔ কেন গোটা জিনিসটা `try` দিয়ে মোড়া ─────────────────────────
     * ঘণ্টা ইতিমধ্যে বেজে গেছে — সারিটা লেখা। ⚠️ SMTP বন্ধ থাকলে বা
     * ঠিকানাটা ভুল হলে যে ব্যতিক্রম ওঠে, সেটা এখান থেকে উপরে গেলে
     * **ডাকা কাজটাই ভেঙে পড়ত**: জমার মেয়াদের ক্রন মাঝপথে থামত, একটা
     * অনুমোদনের সিদ্ধান্ত সংরক্ষিত হয়েও পর্দায় ৫০০ দেখাত।
     *
     * ⓘ অর্থাৎ চিঠি না যাওয়া একটা **কম খারাপ** ব্যর্থতা — খবরটা তবু
     * ঘণ্টায় আছে। ⭐ কিন্তু নীরবে গিলে ফেলা হয় না: `report()` ওটাকে
     * লগে ও ত্রুটির খাতায় বসায়, তাই কেউ খুঁজলে পায়।
     */
    private function post(Notification $bell, ?User $user): void
    {
        /*
         * ⛔ প্রথম পাহারা, আর এটাই সবচেয়ে দামি: `MAIL_MAILER=log` হলে
         * Laravel চিঠিটা **সফলভাবে** ফাইলে লেখে। ⚠️ তখন চেষ্টা করাটাই
         * অর্থহীন — আর খারাপ দিকটা হলো কোড ভাবত কাজটা হয়েছে।
         */
        if (MailReach::silent()) {
            return;
        }

        /* মডেলটা হাতে না থাকলে তুলে আনা — কোম্পানির বেড়া ছাড়া, কারণ
           ক্রনের কোনো কোম্পানি-প্রসঙ্গ নেই আর তখন মানুষটাকে পাওয়াই যেত না */
        $user ??= User::query()->withoutGlobalScope('company')->find($bell->user_id);

        if ($user === null || blank($user->email)) {
            return;
        }

        if (! $this->wantsMail((int) $user->id, (string) $bell->type)) {
            return;
        }

        try {
            $user->notify(new NewsByMail($bell));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * এই মানুষটা এই ধরনের খবর চিঠিতেও চান কি না।
     *
     * ⓘ তিনি নিজে কিছু বলে থাকলে সেটাই চূড়ান্ত; না বললে ধরনটার নিজের
     * নিয়ম ([[NotificationKinds]])।
     */
    private function wantsMail(int $userId, string $type): bool
    {
        if (! array_key_exists($userId, $this->mailChoices)) {
            $this->mailChoices[$userId] = NotificationChoice::mailChoicesFor($userId);
        }

        return $this->mailChoices[$userId][$type]
            ?? NotificationKinds::mailedByDefault($type);
    }

    /**
     * একই খবর একাধিক জনকে।
     *
     * @param  iterable<User|int>  $users
     * @return Collection<int, Notification>
     */
    /**
     * এই মানুষটা এই ধরনের খবর চান কি না।
     *
     * ⚠️ উত্তরটা অনুরোধের মধ্যে মনে রাখা হয়: একটা ছকে দশজন অনুমোদনকারী
     * থাকলে sendMany() দশবার একই প্রশ্ন করত।
     */
    private function wants(int $userId, string $type): bool
    {
        if (! array_key_exists($userId, $this->silenced)) {
            $this->silenced[$userId] = NotificationChoice::silencedFor($userId);
        }

        return ! in_array($type, $this->silenced[$userId], true);
    }

    public function sendMany(
        iterable $users,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?string $priority = null,
        ?string $key = null,
        ?Model $about = null,
    ): Collection {
        $sent = collect();
        $seen = [];

        // ⭐ একই খবর সবার জন্য একটাই ঘটনা — প্রথম যাঁকে সত্যিই পাঠানো হয় তখন লেখা হয় (ধাপ ১)
        $event = null;
        $once = function () use (&$event, $type, $title, $body, $url, $priority, $key, $about): NotificationEvent {
            return $event ??= $this->event($type, $title, $body, $url, $priority, $key, $about);
        };

        foreach ($users as $user) {
            $id = $user instanceof User ? $user->id : (int) $user;

            // একই ছকে একজন দুইবার থাকলে দুইটা খবর যেত
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            /*
             * ⓘ মডেলটা হাতে থাকলে সেটাই পাঠানো হয়, আইডি নয় — নাহলে
             * ⚠️ `post()` প্রতিটা প্রাপকের জন্য আবার একটা কোয়েরি করত।
             * একটা ছকে দশজন অনুমোদনকারী থাকলে দশটা বাড়তি কোয়েরি।
             */
            $one = $this->deliver($user instanceof User ? $user : $id, $once, $type, false);

            if ($one !== null) {
                $sent->push($one);
            }
        }

        return $sent;
    }

    /** @return Collection<int, Notification> */
    public function unreadFor(User|int $user, int $limit = 20): Collection
    {
        return $this->mine($user)
            ->inbox()
            ->unread()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(User|int $user): int
    {
        return $this->mine($user)->inbox()->unread()->count();
    }

    /**
     * ⛔ এই মানুষটার দেখার মতো খবর — নিজের, আর কাগজের শাখা নাগালে ([[Notification::scopeVisibleTo()]])।
     *
     * @return Builder<Notification>
     */
    public function mine(User|int $user): Builder
    {
        $user = $user instanceof User ? $user : User::query()->withoutGlobalScope('company')->findOrFail($user);

        return Notification::query()->visibleTo($user);
    }

    /**
     * একটা খবর পড়া হয়েছে।
     *
     * অন্যের খবর পড়া হিসেবে চিহ্নিত করা যায় না — নাহলে একটা বানানো
     * অনুরোধ দিয়ে অন্যের ঘণ্টা খালি করে দেওয়া যেত, আর তিনি কোনোদিন
     * জানতেন না তাঁর দাবিটা বাতিল হয়েছিল।
     */
    public function markRead(Notification $notification, User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($notification->user_id !== $userId) {
            return false;
        }

        if ($notification->isUnread()) {
            $notification->update(['read_at' => now(), 'seen_at' => $notification->seen_at ?? now()]);
        }

        return true;
    }

    public function markAllRead(User|int $user): int
    {
        $rows = $this->mine($user)->inbox()->unread();

        (clone $rows)->whereNull('seen_at')->update(['seen_at' => now()]);

        return $rows->update(['read_at' => now()]);
    }

    /**
     * ⭐ বাছা কয়েকটা — পড়া, আর্কাইভ বা ফেরত (ধাপ ১; স্পেক §৪ "Bulk Read, Archive")। কেবল নিজের, দেখার মতো সারি।
     *
     * @param  list<int>  $ids
     * @return int কয়টা বদলাল
     */
    public function act(User $user, array $ids, string $action): int
    {
        $rows = $this->mine($user)->whereIn('notifications.id', $ids);

        return match ($action) {
            'read' => $this->readThese((clone $rows)->unread()),
            'unread' => (clone $rows)->whereNotNull('read_at')->update(['read_at' => null]),
            'archive' => (clone $rows)->whereNull('archived_at')->update(['archived_at' => now()]),
            'restore' => (clone $rows)->whereNotNull('archived_at')->update(['archived_at' => null]),
            default => 0,
        };
    }

    /** @param  Builder<Notification>  $rows */
    private function readThese(Builder $rows): int
    {
        // ⓘ পড়া মানে দেখাও — আগে দেখা থাকলে সেই সময়টাই থাকে
        (clone $rows)->whereNull('seen_at')->update(['seen_at' => now()]);

        return $rows->update(['read_at' => now()]);
    }

    /**
     * ⭐ চোখে পড়েছে — খোলা হয়নি (স্পেক: দেখা আর পড়া আলাদা)। ঘণ্টার তালিকা আর "আমার বিজ্ঞপ্তি" পাতায় যা দেখানো হলো।
     *
     * @param  list<int>  $ids
     */
    public function markSeen(User $user, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->mine($user)->whereIn('notifications.id', $ids)->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    /**
     * ⛔ খবরটা খোলা যায় কি — নিজের, শাখা নাগালে, আর যে কাগজের খবর সেটা এখনো আছে ও প্রাপকের নাগালে (স্পেক §১৩)।
     *
     * ⓘ কাগজ খোঁজা হয় কোম্পানির দেয়াল মেনে, কিন্তু হেডারের শাখা না মেনে — খবরটা পাঠানোর সময় তিনি যে শাখায় ছিলেন, আজ
     * হেডারে অন্য শাখা থাকলেও কাগজটা তাঁর নাগালে থাকলে খোলে। নাগাল দেখা হয় কাগজের নিজের শাখা ধরে।
     */
    public function mayOpen(Notification $notification, User $user): bool
    {
        if ($notification->user_id !== $user->id) {
            return false;
        }

        $scope = app(DataScope::class);

        if (! $scope->allows($user, UserDataScope::BRANCH, $notification->branch_id === null ? null : (int) $notification->branch_id)) {
            return false;
        }

        if ($notification->subject_type === null || $notification->subject_id === null) {
            return true;
        }

        $class = app(DrillResolver::class)->map()[$notification->subject_type] ?? null;

        if ($class === null) {
            return true;
        }

        $paper = $class::query()->withoutGlobalScope('user-branch')->find($notification->subject_id);

        if ($paper === null) {
            return false;
        }

        $branch = array_key_exists('branch_id', $paper->getAttributes()) ? $paper->getAttribute('branch_id') : null;

        return $scope->allows($user, UserDataScope::BRANCH, $branch === null ? null : (int) $branch);
    }
}
