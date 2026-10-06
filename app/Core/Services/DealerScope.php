<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বিক্রয়কর্মী কেবল নিজের ডিলার দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ⓘ মালিকের সিদ্ধান্ত (ক), ২৬ সেপ্টেম্বর ২০২৬, আর §ছ-এর উত্তরগুলো
 * (`docs/Plan — বিক্রয়কর্মী ও ডিলারের বাঁধন.md`):
 *  ১ · বাঁধনহীন ডিলার বিক্রয়কর্মী দেখেন না।
 *  ২ · বিক্রয়কর্মী কিছুই তৈরি করেন না — কেবল অর্ডার দেন ([[mayDo()]])।
 *  ৩ · উপরের স্তর (SM · TSM · RSM · DSM) নিজের নিচের গোটা গাছের ডিলার দেখেন।
 *  ৪ · কেবল যা নিজে বাঁধা — এলাকা ধরে চলমান নয়।
 *  ৫ · হাতবদলে নতুনজন পুরনো বিলসহ দেখেন (দেখা ডিলার ধরে, কাগজের তারিখ ধরে নয়)।
 *  ৭ · কোম্পানির সুইচ — চালু থাকলে fail-closed; ডিফল্ট **বন্ধ** (সমন্বয়কারী, ৪ অক্টোবর ২০২৬: মালিক বেঁধে নিজে চালু করেন)।
 *  কাউন্টার, ম্যানেজার আর সুপার অ্যাডমিন সব দেখেন।
 *
 * ── ⛔ শাখার দেয়ালের ঠিক উল্টো নিয়ম ─────────────────────────────────────
 * [[DataScope]]-এ *"সারি নেই মানে সব"*। এখানে *"বাঁধন নেই মানে কিছুই নয়"* —
 * নইলে প্রতিটা নতুন ডিলার প্রথম দিন সব বিক্রয়কর্মীর চোখে থাকত, আর ফাঁসটা নীরবে বাড়ত।
 *
 * ── কে "বিক্রয়কর্মী" — চাবি দিয়ে, রোলের নাম দিয়ে নয় ───────────────────────
 * দেয়াল খাটে যাঁর [[OWN]] চাবি আছে **আর** [[ALL]] নেই। ⓘ ABOS অনেক ব্যবসায়ীর কাছে
 * বিক্রি হয়, আর রোলের নাম কোম্পানিভেদে আলাদা (SR, SO, TSO, বিক্রয়কর্মী …);
 * ⛔ নাম ধরে লিখলে কেউ রোলটার নাম বদলালেই দেয়াল নীরবে উঠে যেত।
 * ⚠️ দুইটা চাবি কেন: সুপার অ্যাডমিনের রোল **সব** চাবি পায় ([[PermissionSyncer]]),
 * তাই কেবল "নিজের ডিলার" চাবিতে দেয়াল বসালে মালিকই দেয়ালে আটকাতেন — আগে একবার
 * একটা ৪০৩-দেয়াল মালিককে বাইরে রেখেছিল। [[ALL]] সেটা ঠেকায়, আর সুপার অ্যাডমিনের রোল
 * আলাদা করেও দেখা হয় — দুই তালা।
 *
 * ── যেখানে দেয়াল নেই, ইচ্ছা করে ─────────────────────────────────────────
 * লগইন নেই (কনসোল, সিডার, নির্ধারিত কাজ); পোর্টালের গ্রাহক (`User` নন); কোম্পানির
 * প্রসঙ্গ নেই; সুইচ বন্ধ — তখন আজকের আচরণ অবিকল।
 */
final class DealerScope
{
    /** ⓘ মাঠের বিক্রয়কর্মীর চিহ্ন — কেবল নিজের বাঁধা ডিলার। */
    public const OWN = 'customer.dealers.own';

    /** ⭐ সব ডিলার — চিহ্নের উপরে জেতে (সুপার অ্যাডমিন, বা একজনের ব্যতিক্রম)। */
    public const ALL = 'customer.dealers.all';

    /** কোম্পানির সুইচ — ডিফল্ট বন্ধ; বাঁধনের পরে মালিক চালু করেন (৪ অক্টোবর ২০২৬)। */
    public const SWITCH = 'customer.dealer_scope_enabled';

    /**
     * ⭐ মাঠের রোলের নাম — চলতি কোম্পানিগুলোতে চিহ্নটা এদেরই দেওয়া হয় (মালিক, ৩ অক্টোবর ২০২৬:
     * *"SR, TSM, ASM"* — উত্তর "ক")।
     *
     * ⓘ ছাঁচের নামগুলো (`Field Sales`, `ASM`, `RSM`, `DSM` — গ্রাহক মডিউলের `role_templates`-এ
     * যাদের চিহ্ন আছে) আর মালিকের ডাকনাম (`SR`, `SM`, `TSM`); ডেমোর পুরনো `salesman`ও।
     * ⚠️ চাবি নয় নাম, কেবল **একবারের** স্থানান্তরে — চালু নিয়মটা চাবির ([[walled()]])। ছাঁচে নতুন
     * মাঠের রোল এলে এখানেও আসে কি না, [[TheFieldRolesWereGivenTheDealerWallTest]] দেখে।
     *
     * @var list<string>
     */
    public const FIELD_ROLES = ['Field Sales', 'SR', 'SM', 'TSM', 'ASM', 'RSM', 'DSM', 'salesman'];

    /** ⛔ এই নামের রোল কখনো চিহ্ন পায় না — অফিসের কাজ, সব ডিলার লাগে। */
    public const NEVER_MARKED = ['super_admin', 'Manager', 'Accountant', 'Counter', 'Warehouse'];

    /** ⭐ দেয়ালের ভিতরের মানুষ যে একটা কাজই তৈরি করতে পারেন — অর্ডার দেওয়া। */
    public const MAY_CREATE = ['sales.order.create'];

    /**
     * ⛔ তৈরি/বদল/বাতিল/সিদ্ধান্তের চাবি — চাবির শেষ অংশ ধরে।
     *
     * ⓘ মালিক: *"বিক্রয়কর্মী কিছুই তৈরি করতে পারবেন না — কেবল অর্ডার দেবেন; অনুমতিতে যা
     * আছে তা কেবল দেখবেন"*। ⚠️ তালিকা নয়, নিয়ম: নতুন মডিউল কাল `x.y.create` ঘোষণা করলে
     * সেটাও আপনা থেকে আটকায় — হাতে লেখা তালিকা হলে চুপচাপ খোলা থাকত।
     */
    private const CHANGING = '/\.(create|update|delete|cancel|manage|decide|convert|override|import|portal|opening_balance|approve|reopen|post|edit|store)$/';

    /** @var array<string, list<int>> কোম্পানি:ব্যবহারকারী => নিজে + নিচের গাছ */
    private array $reach = [];

    /**
     * ⭐ এই মানুষটি কি দেয়ালের ভিতরে?
     *
     * ⓘ প্রতিবার নতুন করে প্রশ্ন — চাবি কেড়ে নিলে পরের কোয়েরিতেই দেয়াল ওঠে বা নামে।
     */
    public function walled(mixed $user = null): bool
    {
        $user = func_num_args() === 0 ? auth()->user() : $user;

        if (! $user instanceof User || CompanyContext::id() === null) {
            return false;
        }

        if (! $this->switchOn()) {
            return false;
        }

        // ⛔ মালিক কখনো দেয়ালে নন — রোল আর চাবি, দুই তালা
        if ($user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE)) {
            return false;
        }

        return $user->can(self::OWN) && ! $user->can(self::ALL);
    }

    /** কোম্পানির সুইচ — মডিউল বন্ধ থাকলে (সংজ্ঞা নেই) বন্ধ ধরা হয়। */
    public function switchOn(): bool
    {
        return (bool) app(SettingsService::class)->get(self::SWITCH, false);
    }

    /**
     * যাঁদের বাঁধন এই মানুষটির দেখায় গোনা হয় — নিজে আর নিচের গোটা গাছ।
     *
     * ⓘ গাছ ছোট (কোম্পানির কর্মী কয়েকশো), তাই একবারে তুলে PHP-তে হাঁটা; চক্র থাকলেও
     * ঘোরে না (`$seen`)।
     *
     * @return list<int>
     */
    public function reachOf(User $user): array
    {
        $companyId = (int) CompanyContext::id();
        $key = $companyId.':'.$user->id;

        if (array_key_exists($key, $this->reach)) {
            return $this->reach[$key];
        }

        $children = [];

        foreach (DB::table('staff_supervisors')->where('company_id', $companyId)->get(['user_id', 'supervisor_id']) as $row) {
            $children[(int) $row->supervisor_id][] = (int) $row->user_id;
        }

        $seen = [(int) $user->id => true];
        $queue = [(int) $user->id];

        while ($queue !== []) {
            $next = array_shift($queue);

            foreach ($children[$next] ?? [] as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $this->reach[$key] = array_keys($seen);
    }

    /**
     * ⭐ দেখা ডিলারের আইডি — সাবকোয়েরি, তালিকা নয় (অনেক বাঁধনে `whereIn` লম্বা হত)।
     *
     * ⓘ "আজ চালু": শুরু আজ বা আগে, শেষ ফাঁকা বা আজ বা পরে। হাতবদলের পরদিন থেকে পুরনো জন
     * আর দেখেন না, নতুন জন ডিলারের **সব** কাগজ দেখেন (মালিকের উত্তর ৫)।
     */
    public function dealerIds(User $user): Builder
    {
        $today = Carbon::today()->toDateString();

        return DB::table('dealer_bindings')
            ->select('dealer_bindings.customer_id')
            ->where('dealer_bindings.company_id', CompanyContext::id())
            ->whereIn('dealer_bindings.user_id', $this->reachOf($user))
            ->where('dealer_bindings.starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('dealer_bindings.ends_on')->orWhere('dealer_bindings.ends_on', '>=', $today));
    }

    /**
     * ⭐ যেকোনো কোয়েরিতে দেয়াল — `DB::table`-এর পথের একমাত্র দরজা।
     *
     * ⓘ ডিলারহীন সারি (`null`) দেয়ালের ভিতরের মানুষ দেখেন না — fail-closed।
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function restrict(BuilderContract $query, string $column, mixed $user = null): BuilderContract
    {
        $user = func_num_args() < 3 ? auth()->user() : $user;

        if (! $this->walled($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->dealerIds($user));
    }

    /** এই ডিলার কি এই মানুষটির নাগালে — দেয়ালের বাইরের মানুষের কাছে সবাই। */
    public function allowsCustomer(?int $customerId, mixed $user = null): bool
    {
        $user = func_num_args() < 2 ? auth()->user() : $user;

        if (! $this->walled($user)) {
            return true;
        }

        if ($customerId === null) {
            return false;
        }

        return DB::query()->fromSub($this->dealerIds($user), 'd')->where('d.customer_id', $customerId)->exists();
    }

    /**
     * ⛔ দেয়ালের ভিতরের মানুষের "তৈরির" চাবি — `Gate::before`-এর উত্তর।
     *
     * `false` = আটকানো; `null` = আমার কিছু বলার নেই (স্বাভাবিক নিয়মে সিদ্ধান্ত)।
     * ⓘ অর্ডার দেওয়া ছাড়া সব তৈরি-বদল-বাতিল আটকায়, দেখার চাবিগুলো যেমন ছিল তেমন।
     */
    public function mayDo(User $user, string $ability): ?bool
    {
        if (in_array($ability, self::MAY_CREATE, true) || preg_match(self::CHANGING, $ability) !== 1) {
            return null;
        }

        return $this->walled($user) ? false : null;
    }

    /** বাঁধন বা গাছ বদলালে জমানো উত্তর ভুল। */
    public function forget(): void
    {
        $this->reach = [];
    }
}
