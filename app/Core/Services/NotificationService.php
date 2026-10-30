<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\Drillable;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Notifications\DeliveryService;
use App\Core\Notifications\NotificationVariables;
use App\Core\Notifications\PreferenceBook;
use App\Core\Notifications\RecipientResolver;
use App\Core\Notifications\RuleEngine;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\NotificationChoice;
use App\Models\NotificationEvent;
use App\Models\NotificationSuppression;
use App\Models\NotificationTemplateVersion;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        /*
         * ⭐ ধাপ ৩ — টেমপ্লেটের চলকের মান (`['amount' => '১,২৫,০০০', 'paper_no' => 'PV-0042']`); কেবল অনুমোদিত চলক থাকে
         * ([[NotificationVariables::clean()]])। নিয়মের শর্তও এগুলোই মেলায়। আর সরাসরি একটা টেমপ্লেট-সংস্করণ (সূচি, পরীক্ষা)।
         */
        ?array $data = null,
        ?NotificationTemplateVersion $template = null,
    ): ?Notification {
        $plan = $this->plan($type, $title, $body, $priority, $about, $data, $template);
        $event = null;
        $once = function () use (&$event, $type, $title, $body, $url, $key, $about, $plan): NotificationEvent {
            return $event ??= $this->event($type, $title, $body, $url, $key, $about, $plan);
        };

        $bell = $this->deliver($user, $once, $type, $evenToSelf, $plan);
        $this->ruleRecipients($plan, $once, $type, $about, [$user instanceof User ? $user->id : (int) $user => true]);

        return $bell;
    }

    /**
     * ⭐ নিয়ম মিলিয়ে এই খবরের পরিকল্পনা — একবারই, সব প্রাপকের জন্য (ধাপ ৩; [[RuleEngine::plan()]])।
     *
     * ⓘ কোনো নিয়ম না থাকলে সব আগের মতো: মডিউলের গুরুত্ব, মডিউলের লেখা, স্বাভাবিক মাধ্যম।
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    private function plan(string $type, string $title, ?string $body, ?string $priority, ?Model $about, ?array $data, ?NotificationTemplateVersion $template): array
    {
        $kind = NotificationKinds::classify($type);
        $branchId = $about !== null && array_key_exists('branch_id', $about->getAttributes()) && $about->getAttribute('branch_id') !== null
            ? (int) $about->getAttribute('branch_id') : null;
        $priority = NotificationKinds::isPriority($priority) ? $priority : $kind['priority'];
        $clean = NotificationVariables::clean($data);

        $rules = app(RuleEngine::class)->plan($type, $branchId, $priority, $clean);
        $template ??= $rules['template'];

        return [
            'kind' => $kind,
            'branch_id' => $branchId,
            'priority' => $rules['priority'] ?? $priority,
            'data' => $clean,
            // ⓘ চলকের মান কেবল টেমপ্লেট থাকলে — নাহলে বাড়তি কোয়েরি নয়
            'values' => $template === null ? [] : $clean + [
                'title' => $title,
                'body' => (string) $body,
                'actor' => (string) (Actor::userId() === null ? '' : User::query()->withoutGlobalScope('company')->whereKey(Actor::userId())->value('name')),
                'company' => (string) (Company::query()->whereKey(CompanyContext::id())->value('name_bn') ?? ''),
                'branch' => (string) ($branchId === null ? '' : Branch::query()->withoutGlobalScopes()->whereKey($branchId)->value('name_bn')),
                'date' => now()->format('d/m/Y'),
            ],
            'template' => $template,
            'channels' => $rules['channels'],
            'rules' => $rules['rules'],
            'delay' => $rules['delay'],
            'expires' => $rules['expires'],
            'cooldown' => $rules['cooldown'],
        ];
    }

    /**
     * ⭐ নিয়মের যোগ করা প্রাপক — মডিউলের নিজের প্রাপকদের পরে, একই ঘটনায় (ধাপ ৩)। দেয়াল [[RecipientResolver]]-এ।
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, true>  $seen
     * @return Collection<int, Notification>
     */
    private function ruleRecipients(array $plan, \Closure $once, string $type, ?Model $about, array $seen): Collection
    {
        $sent = collect();

        foreach ($plan['rules'] as $rule) {
            foreach (app(RecipientResolver::class)->resolve((array) ($rule->recipients ?? []), $plan['branch_id'], $about) as $user) {
                if (isset($seen[$user->id])) {
                    continue;
                }

                $seen[$user->id] = true;

                if (($one = $this->deliver($user, $once, $type, false, $plan)) !== null) {
                    $sent->push($one);
                }
            }
        }

        return $sent;
    }

    /**
     * একজন প্রাপকের সারি — ঘটনাটা দরকার হলে তবেই লেখা হয় (নিজের কাজ বা বন্ধ করা ধরন হলে ঘটনাও বসে না)।
     *
     * @param  \Closure(): NotificationEvent  $event
     * @param  array<string, mixed>  $plan
     */
    private function deliver(User|int $user, \Closure $event, string $type, bool $evenToSelf, array $plan, ?int $onBehalfOf = null): ?Notification
    {
        $forwarded = $onBehalfOf !== null;
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
         * ⭐ একই কাগজের একই খবর একজনের কাছে নিয়মের বলা সময়ের মধ্যে আবার নয় (ধাপ ৩; স্পেক §৮ "Duplicate Prevention")।
         * ⓘ আটকানোটা লেখা থাকে — কেন পাননি তা পরে জানা যায়।
         */
        if ($plan['cooldown'] > 0 && $this->toldRecently($userId, $event, (int) $plan['cooldown'])) {
            NotificationSuppression::query()->create([
                'company_id' => $event->company_id, 'user_id' => $userId, 'event_id' => $event->id,
                'rule_id' => $plan['rules']->first()?->id, 'type' => $event->type, 'reason' => 'cooldown',
            ]);

            return null;
        }

        [$title, $body] = $this->wording($user, $event, $plan);

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
                'title' => $title,
                'body' => $body,
                'url' => $event->url,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'on_behalf_of' => $onBehalfOf,
            ],
        );

        if (! $bell->wasRecentlyCreated) {
            return null;
        }

        $event->increment('recipients');

        $known = $user instanceof User ? $user : null;

        // ⭐ ছুটিতে থাকলে আরেকজনের কাছেও — এক ধাপ, আর দায়িত্বপ্রাপ্তের নিজের শাখার দেয়ালে (স্পেক §১৪ "ছুটিতে Delegate")
        if (! $forwarded) {
            $this->forwardToDelegate($userId, $event, $type, $plan);
        }

        /*
         * ⭐ ঘণ্টার বাইরের মাধ্যম (ইমেইল, Web Push, মোবাইল পুশ) — লেনদেন পাকা হওয়ার পরে কিউয়ে, আবার চেষ্টা আর ব্যর্থ-তালিকাসহ
         * ([[DeliveryService]], ধাপ ২)। ⓘ লেনদেন ফিরে গেলে কিছুই যায় না; প্রোভাইডার বন্ধ থাকলেও ঘণ্টা আর ERP চলে।
         */
        DB::afterCommit(fn () => app(DeliveryService::class)->queueFor($bell, $known));

        return $bell;
    }

    /**
     * ঘটনাটা — চাবি থাকলে আগের ঘটনাই, নাহলে নতুন।
     */
    /** @param  array<string, mixed>  $plan */
    private function event(string $type, string $title, ?string $body, ?string $url, ?string $key, ?Model $about, array $plan): NotificationEvent
    {
        $kind = $plan['kind'];
        $template = $plan['template'];

        // ⓘ টেমপ্লেট থাকলে ঘটনার নিজের লেখা অ্যাপের ভাষায়; প্রত্যেক প্রাপক পান নিজের ভাষায় ([[wording()]])
        if ($template !== null) {
            $locale = (string) config('app.locale');
            $title = NotificationVariables::render($template->part('title', $locale), $plan['values']);
            $body = NotificationVariables::render($template->part('body', $locale), $plan['values']) ?: null;
        }

        $values = [
            'company_id' => CompanyContext::id(),
            'branch_id' => $plan['branch_id'],
            'module' => $kind['module'],
            'type' => $type,
            'category' => $kind['category'],
            'priority' => $plan['priority'],
            'title' => mb_substr($title, 0, 191),
            'body' => $body === null ? null : mb_substr($body, 0, 500),
            'url' => $url,
            ...$this->subjectOf($about),
            'actor_id' => Actor::userId(),
            // ⭐ ধাপ ৩
            'template_version_id' => $template?->id,
            'data' => $plan['data'] ?: null,
            'channels' => $plan['channels'],
            'rule_ids' => $plan['rules']->isEmpty() ? null : $plan['rules']->pluck('id')->all(),
            'deliver_after' => $plan['delay'] > 0 ? now()->addMinutes((int) $plan['delay']) : null,
            'expires_at' => $plan['expires'] !== null ? now()->addMinutes((int) $plan['expires']) : null,
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
     * ⭐ খবরের কাগজ — খোঁজা যায় এমন কাগজের ([[Drillable]]) ড্রিলের নাম; বাকি কাগজের (ডকুমেন্ট, বীমা পলিসি) মডেলের নিজের নাম
     * (fe, ২৪ অক্টোবর ২০২৬: "কাগজটা আর নেই")।
     *
     * ⓘ ড্রিল-ছাড়া কাগজের নামটা কেবল খোলার আগে "কাগজটা এখনো আছে কি" দেখার জন্য ([[whyClosed()]]) — ড্রিলের তালিকায় ওঠে না,
     * তাই খোঁজ, ফোনের সিঙ্ক বা ফোনের `paper.type`-এ যায় না ([[PhoneNoticeTarget]] কেবল ড্রিলের নাম বলে)। ⚠️ ঘরটা ৬৪ অক্ষরের;
     * নাম তার চেয়ে লম্বা হলে কাগজ বসে না (আগের মতো) — ব্যবসার লেনদেনের ভিতরে খবর লিখতে গিয়ে লেনদেন ভাঙা চলে না।
     *
     * @return array{subject_type: ?string, subject_id: ?int}
     */
    private function subjectOf(?Model $about): array
    {
        $type = match (true) {
            $about instanceof Drillable => $about::drillSourceType(),
            $about !== null => $about->getMorphClass(),
            default => null,
        };

        if ($type === null || mb_strlen($type) > 64 || $about?->getKey() === null) {
            return ['subject_type' => null, 'subject_id' => null];
        }

        return ['subject_type' => $type, 'subject_id' => (int) $about->getKey()];
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
    /**
     * এই প্রাপকের ভাষায় শিরোনাম আর বার্তা — টেমপ্লেট থাকলে তাঁর ভাষার লেখায় চলক বসিয়ে, নাহলে ঘটনার নিজের লেখা।
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: string, 1: ?string}
     */
    private function wording(User|int $user, NotificationEvent $event, array $plan): array
    {
        $template = $plan['template'];

        if ($template === null) {
            return [(string) $event->title, $event->body];
        }

        $user = $user instanceof User ? $user : User::query()->withoutGlobalScope('company')->find($user);
        $locale = in_array($user?->locale, ['bn', 'en'], true) ? (string) $user->locale : (string) config('app.locale');
        $values = $plan['values'] + ['recipient' => (string) ($user?->name ?? '')];

        $title = NotificationVariables::render($template->part('title', $locale), $values);
        $body = NotificationVariables::render($template->part('body', $locale), $values);

        return [mb_substr($title !== '' ? $title : (string) $event->title, 0, 191), $body === '' ? null : mb_substr($body, 0, 500)];
    }

    /**
     * ছুটির দায়িত্বপ্রাপ্তের কাছে কপি — তিনি এই কোম্পানির সক্রিয় সদস্য আর খবরের শাখা তাঁর নাগালে হলে তবেই।
     *
     * @param  array<string, mixed>  $plan
     */
    private function forwardToDelegate(int $userId, NotificationEvent $event, string $type, array $plan): void
    {
        $delegate = app(PreferenceBook::class)->for($userId, (int) $event->company_id)->delegateToday();

        if ($delegate === null || $delegate === $userId) {
            return;
        }

        $allowed = app(RecipientResolver::class)->resolve(['users' => [$delegate]], $event->branch_id === null ? null : (int) $event->branch_id);

        if ($allowed->isNotEmpty()) {
            $this->deliver($allowed->first(), fn () => $event, $type, false, $plan, $userId);
        }
    }

    /** একই কাগজের একই ধরনের খবর এই মানুষটা শেষ কয়েক মিনিটে পেয়েছেন কি না */
    private function toldRecently(int $userId, NotificationEvent $event, int $minutes): bool
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $userId)
            // ⛔ নিজের কোম্পানির খবরই — নইলে এক কোম্পানির ঘণ্টা অন্য কোম্পানির নিয়মের সময়ের মধ্যে খবর চেপে দিত
            // (Notification লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[ACooldownStaysInItsCompanyTest]])
            ->where('company_id', $event->company_id)
            ->where('type', $event->type)
            ->where('event_id', '!=', $event->id)
            ->where(fn ($q) => $event->subject_type === null
                ? $q->whereNull('subject_type')
                : $q->where('subject_type', $event->subject_type)->where('subject_id', $event->subject_id))
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

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
        ?array $data = null,
        ?NotificationTemplateVersion $template = null,
    ): Collection {
        $sent = collect();
        $seen = [];
        $plan = $this->plan($type, $title, $body, $priority, $about, $data, $template);

        // ⭐ একই খবর সবার জন্য একটাই ঘটনা — প্রথম যাঁকে সত্যিই পাঠানো হয় তখন লেখা হয় (ধাপ ১)
        $event = null;
        $once = function () use (&$event, $type, $title, $body, $url, $key, $about, $plan): NotificationEvent {
            return $event ??= $this->event($type, $title, $body, $url, $key, $about, $plan);
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
            $one = $this->deliver($user instanceof User ? $user : $id, $once, $type, false, $plan);

            if ($one !== null) {
                $sent->push($one);
            }
        }

        // ⭐ ধাপ ৩ — নিয়মের যোগ করা প্রাপক, মডিউলের তালিকার পরে
        return $sent->concat($this->ruleRecipients($plan, $once, $type, $about, $seen));
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
        return $this->whyClosed($notification, $user) === null;
    }

    /** কাগজটা আর নেই — মোছা হয়েছে (নরম মোছাও) */
    public const GONE = 'gone';

    /** কাগজ আছে, কিন্তু প্রাপকের নাগালে নেই (বা খবরটাই অন্যের) */
    public const OUT_OF_REACH = 'reach';

    /** মাথায় বাছা শাখার ছাঁকনি — খোলার প্রশ্নে খাটে না, নাগাল দেখা হয় কাগজের নিজের শাখা ধরে */
    private const HEADER_SCOPES = ['user-branch', 'viewed-branch'];

    /**
     * ⭐ খবরটা কেন খোলে না — `null` মানে খোলে; নয়তো [[GONE]] বা [[OUT_OF_REACH]] (fe-র নিয়ম, ২৪ অক্টোবর ২০২৬: "খবর যাঁকে কাগজে
     * পাঠায় তিনি সেটা খোলেন; না পারলে কারণটা বাংলায় দেখেন — খালি ৪০৩/৪০৪ কখনো নয়")।
     *
     * ⓘ "নেই" আর "নাগালে নেই" আলাদা: মুছে যাওয়া কাগজের খবরে "অনুমতি নেই" বললে মানুষ প্রশাসকের কাছে অনুমতি চাইতে যান — অথচ
     * খোলার মতো কিছুই নেই। ⓘ "নেই" বলা হয় কেবল যখন কোম্পানির দেয়াল আর মোছার নিয়ম বাদে কোনো ছাঁকনি ছাড়াও কাগজটা পাওয়া যায় না;
     * কাগজের নিজের অন্য দেয়ালে ঢাকা পড়লে সেটা "নাগালে নেই", আগের মতো।
     */
    public function whyClosed(Notification $notification, User $user): ?string
    {
        if ($notification->user_id !== $user->id) {
            return self::OUT_OF_REACH;
        }

        $scope = app(DataScope::class);

        if (! $scope->allows($user, UserDataScope::BRANCH, $notification->branch_id === null ? null : (int) $notification->branch_id)) {
            return self::OUT_OF_REACH;
        }

        if ($notification->subject_type === null || $notification->subject_id === null) {
            return null;
        }

        $type = (string) $notification->subject_type;
        $drill = app(DrillResolver::class)->map()[$type] ?? null;
        // ⓘ ড্রিল-ছাড়া কাগজ — খবরে মডেলের নিজের নাম ([[subjectOf()]])
        $class = $drill ?? (is_a($type, Model::class, true) ? $type : null);

        if ($class === null) {
            return null;
        }

        $id = (int) $notification->subject_id;
        $paper = $drill === null ? null : $drill::query()->withoutGlobalScopes(self::HEADER_SCOPES)->find($id);

        if ($paper === null) {
            $paper = $this->bare($class)->find($id);

            if ($paper === null) {
                return self::GONE;
            }

            // ⓘ খোঁজা যায় এমন কাগজ নিজের অন্য দেয়ালে ঢাকা — আছে, কিন্তু এই মানুষের জন্য নয়
            if ($drill !== null) {
                return self::OUT_OF_REACH;
            }
        }

        $branch = array_key_exists('branch_id', $paper->getAttributes()) ? $paper->getAttribute('branch_id') : null;

        return $scope->allows($user, UserDataScope::BRANCH, $branch === null ? null : (int) $branch) ? null : self::OUT_OF_REACH;
    }

    /**
     * কাগজটা আছে কি না দেখার খোঁজ — কোম্পানির দেয়াল আর মোছার নিয়ম থাকে, বাকি সব ছাঁকনি (শাখা, গুদাম, গোপনীয়তা) সরে।
     *
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function bare(string $class): Builder
    {
        $query = $class::query();
        $others = array_diff(array_keys($query->getModel()->getGlobalScopes()), ['company', SoftDeletingScope::class]);

        return $query->withoutGlobalScopes(array_values($others));
    }
}
