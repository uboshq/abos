<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Services\SettingsService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\LoyaltyEntry;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\LoyaltyKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * পয়েন্টের খাতা — স্পেক §৭-ঠ ও §১৩, লয়্যালটি অফারের একমাত্র দরজা।
 *
 * ── ⭐ কেন একটাই দরজা ───────────────────────────────────────────────
 * ⓘ পয়েন্ট আসে অফার থেকে, যায় বিলের ছাড়ে, ফেরে বিল বাতিলে, আর ফুরোয়
 * রাতের কাজে। ⛔ চারটা পথ চার জায়গায় লিখলে একদিন একটা পথ তালা ছাড়া
 * লিখত — আর তখন একই পয়েন্ট দুই কাউন্টারে একসাথে খরচ হত।
 *
 * ── ⚠️ ব্যালান্স মানে যোগফল, কিন্তু মেয়াদ গোনা হয় খাতা পড়ে ──────────
 * ⓘ সারির `points` যোগ করলেই ব্যালান্স — যতক্ষণ রাতের কাজ
 * ([[expireDue()]]) মেয়াদ-শেষের সারি লিখে রেখেছে। ⛔ কিন্তু রাতের কাজ
 * এক রাত না চললে সরল যোগফল ফুরোনো পয়েন্টও খরচ করতে দিত।
 *
 * ⭐ তাই ব্যালান্স খাতাটা শুরু থেকে পড়ে ([[replay()]]): প্রতিটা অর্জন
 * একটা *"লট"*, খরচ আগে-ফুরোবে-যে-লট থেকে নেয় (FIFO), আর যে লটের দিন
 * পেরিয়েছে সে গোনায় থাকে না — সারি লেখা হোক বা না হোক। ⓘ ফলে উত্তরটা
 * সবসময় `SUM(points) − (দিন পেরোনো অথচ এখনো না-লেখা মেয়াদ-শেষ)`।
 *
 * ── ⛔ তালা ক্রেতার সারিতে ───────────────────────────────────────────
 * ⓘ খাতার সারিতে `FOR UPDATE` দিলে নতুন ক্রেতার (যাঁর কোনো সারি নেই)
 * কিছুই তালাবদ্ধ হত না। ⚠️ ক্রেতার নিজের সারি সবসময় থাকে, তাই একই
 * ক্রেতার দুইটা খরচ একটার পর একটা চলে — দ্বিতীয়টা প্রথমটার লেখা দেখে।
 */
final class LoyaltyLedger
{
    /** ⓘ মেয়াদ-শেষের সারির কাগজ — ছয় মাস পরে *"কেন কমল"* খুঁজলে এটাই দেখায় */
    public const EXPIRY_SOURCE = 'promotion:loyalty-expiry';

    private const SCALE = 4;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * ⭐ একটা বসানো অফার থেকে পয়েন্ট জমা — একবারই।
     *
     * ── ⛔ দুইবার ডাকলে দুইবার জমে না ────────────────────────────────
     * ⓘ বিলের সংরক্ষণ দুইবার চাপা হতে পারে, বা একটা কাজ মাঝপথে ভেঙে
     * আবার চলতে পারে। ⚠️ তখন একই বিলের পয়েন্ট দুইবার জমত — আর ক্রেতা
     * সেটা খরচও করে ফেলতেন। ⭐ তাই আগের অর্জনটাই ফেরত আসে।
     *
     * ── ⓘ কখন কিছুই জমে না (`null`) ─────────────────────────────────
     *   ⓵ সুবিধাটা পয়েন্ট নয়
     *   ⓶ বিলে কোনো ক্রেতা নেই — পয়েন্ট কার নামে লিখব?
     *   ⓷ বসানো অফারটা ইতিমধ্যে ফেরানো (বিল বাতিল)
     *
     * ⚠️ পয়েন্টের সংখ্যা `benefit_amount` থেকে — বসানোর মুহূর্তে জমে
     * যাওয়া সংখ্যা (§১৮)। ⛔ অফারের কাগজ থেকে নতুন করে পড়লে অফার
     * বদলালে পুরনো বিলের পয়েন্টও বদলে যেত।
     *
     * @param  int|null  $validDays  কত দিন খরচ করা যাবে; `null` হলে সেটিং, `0` হলে কখনো ফুরোয় না
     */
    public function earn(PromotionApplication $applied, ?int $validDays = null): ?LoyaltyEntry
    {
        if ($applied->benefit_kind !== BenefitKind::POINTS
            || $applied->customer_id === null
            || $applied->reversed_at !== null) {
            return null;
        }

        $points = (string) $applied->benefit_amount;

        if (bccomp($points, '0', self::SCALE) <= 0) {
            return null;
        }

        $now = Carbon::now();
        $days = $validDays ?? (int) $this->settings->get('promotion.loyalty_valid_days', 0);

        return DB::transaction(function () use ($applied, $points, $now, $days) {
            $this->lockCustomer((int) $applied->customer_id);

            /* ⭐ তালার **ভিতরে** দেখা — বাইরে দেখলে দুইজন একসাথে "নেই" দেখতেন */
            $already = LoyaltyEntry::query()
                ->where('promotion_application_id', $applied->id)
                ->where('kind', LoyaltyKind::EARN->value)
                ->first();

            if ($already !== null) {
                return $already;
            }

            return $this->write([
                'company_id' => $applied->company_id,
                'customer_id' => $applied->customer_id,
                'promotion_application_id' => $applied->id,
                'kind' => LoyaltyKind::EARN,
                'points' => $points,
                'expires_on' => $days > 0 ? $now->copy()->addDays($days)->toDateString() : null,
                'source_type' => $applied->source_type,
                'source_id' => $applied->source_id,
                'occurred_at' => $now,
            ]);
        });
    }

    /**
     * ⭐ ক্রেতা পয়েন্ট খরচ করেন — ব্যালান্সের বেশি কখনো নয়।
     *
     * ── ⛔ সীমানা ছুঁলে চলে, ছাড়ালে থামে ────────────────────────────
     * ⓘ ১০০ পয়েন্টে ১০০ খরচ করাই স্বাভাবিক। ⚠️ `>=` লিখলে শেষ পয়েন্টটা
     * কোনোদিনই খরচ করা যেত না — [[BudgetGuard]]-এর ছাদের একই যুক্তি।
     *
     * ── ⚠️ একটা কাগজে একবারই ─────────────────────────────────────────
     * ⓘ একটা বিলে পয়েন্টের ছাড় একটাই সারি। ⛔ বোতাম দুইবার চাপা হলে
     * দ্বিতীয়টা থামে — নাহলে ব্যালান্স থাকলে একই বিলে দুইবার কাটত।
     * ⭐ বিল বাতিল হয়ে খরচটা ফিরলে আবার খরচ করা যায়।
     */
    public function redeem(int $customerId, string $points, string $sourceType, int $sourceId): LoyaltyEntry
    {
        /* ⚠️ কেবল সাধারণ দশমিক — `1e3`-এর মতো লেখা bcmath-এ ভেঙে পড়ে, থামে না */
        if (preg_match('/^\d+(\.\d+)?$/', $points) !== 1 || bccomp($points, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([
                'points' => __('promotion::loyalty.not_positive'),
            ]);
        }

        return DB::transaction(function () use ($customerId, $points, $sourceType, $sourceId) {
            $customer = $this->lockCustomer($customerId);

            $spentHere = LoyaltyEntry::query()
                ->where('customer_id', $customerId)
                ->where('kind', LoyaltyKind::REDEEM->value)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereDoesntHave('reversal')
                ->exists();

            if ($spentHere) {
                throw ValidationException::withMessages([
                    'points' => __('promotion::loyalty.already_redeemed'),
                ]);
            }

            $now = Carbon::now();
            $balance = $this->replay($customerId, $now, true)['balance'];

            if (bccomp($points, $balance, self::SCALE) > 0) {
                throw ValidationException::withMessages([
                    'points' => __('promotion::loyalty.not_enough', [
                        'asked' => $points,
                        'balance' => $balance,
                    ]),
                ]);
            }

            return $this->write([
                'company_id' => $customer->company_id,
                'customer_id' => $customerId,
                'kind' => LoyaltyKind::REDEEM,
                'points' => bcmul($points, '-1', self::SCALE),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_at' => $now,
            ]);
        });
    }

    /**
     * ⭐ একটা কাগজের সব পয়েন্ট ফিরিয়ে নেওয়া — বিল বাতিলে (§১৮)।
     *
     * ── ⛔ দুইবার ডাকলে দুইবার ফেরে না ───────────────────────────────
     * ⓘ ইতিমধ্যে ফেরানো সারি ছোঁয়া হয় না, আর ডাটাবেজের
     * `(reverses_entry_id, kind)` দ্বিতীয় ফেরত ফিরিয়ে দেয়। ⚠️ দুইবার
     * ফেরালে খরচের পয়েন্ট দুইবার ক্রেতার ঘরে ঢুকত — ভুয়া পয়েন্ট, যা
     * খরচও হয়ে যেত।
     *
     * ── ⚠️ অর্জনের ফেরত — কতটা ───────────────────────────────────────
     * ⓘ যতটা জমেছিল, তার থেকে যতটা ইতিমধ্যে ফুরিয়েছে বাদ দিয়ে। ⛔ ফুরোনো
     * অংশ আবার কাটলে ক্রেতা একই পয়েন্ট দুইবার হারাতেন।
     * ⚠️ আর ক্রেতা পয়েন্টটা খরচ করে ফেলে থাকলে ব্যালান্স ঋণাত্মক হয় —
     * ইচ্ছাকৃত: বাতিল বিলের পয়েন্টে কেনা ছাড়টা ক্রেতা আগেই পেয়েছেন।
     *
     * ── ⭐ খরচের ফেরত — কোন মেয়াদে ────────────────────────────────────
     * ⓘ যে লটগুলো থেকে খরচ হয়েছিল, তাদের সবচেয়ে দূরের মেয়াদ নিয়ে ফেরে।
     * ⛔ মেয়াদহীন ফেরালে বাতিল বিলের পথে ফুরোতে-বসা পয়েন্ট চিরস্থায়ী
     * হয়ে যেত।
     *
     * @return int কয়টা সারি ফেরানো হলো
     */
    public function reverse(string $sourceType, int $sourceId): int
    {
        return DB::transaction(function () use ($sourceType, $sourceId) {
            $open = fn () => LoyaltyEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereIn('kind', [LoyaltyKind::EARN->value, LoyaltyKind::REDEEM->value])
                ->whereDoesntHave('reversal')
                ->orderBy('id');

            /* ⓘ ক্রমে তালা — দুইটা বাতিল একসাথে চললে একে অন্যকে আটকে না রাখে */
            $customers = $open()->pluck('customer_id')->unique()->sort()->values();

            foreach ($customers as $customerId) {
                $this->lockCustomer((int) $customerId);
            }

            /*
             * ⚠️ তালার পরে আবার পড়া — এর মধ্যে অন্য কেউ ফিরিয়ে থাকতে পারেন।
             *
             * ⛔ `lockForUpdate` এখানে কেবল তালার জন্য নয়: ⓘ InnoDB-তে
             * সাধারণ SELECT লেনদেনের **প্রথম** পড়ার ছবিটা দেখে, আর সেই
             * ছবি উপরের `pluck`-এ তালার আগেই তোলা। ⚠️ তালাবদ্ধ পড়া সবসময়
             * সর্বশেষ লেখা দেখে।
             */
            $targets = $open()->lockForUpdate()->get();
            $now = Carbon::now();

            foreach ($targets as $target) {
                $target->kind === LoyaltyKind::EARN
                    ? $this->reverseEarn($target, $now)
                    : $this->reverseRedeem($target, $now);
            }

            return $targets->count();
        });
    }

    /**
     * ⭐ ক্রেতার ব্যালান্স — ফুরোনো পয়েন্ট বাদে।
     *
     * ⚠️ ঋণাত্মক হতে পারে: খরচ করা পয়েন্টের বিল বাতিল হলে (দেখুন
     * [[reverse()]])। ⓘ তখন নতুন পয়েন্ট আগে ঐ ঘাটতি মেটায়।
     */
    public function balance(int $customerId, ?Carbon $at = null): string
    {
        return $this->replay($customerId, $at ?? Carbon::now())['balance'];
    }

    /**
     * ⭐ যে পয়েন্টের দিন পেরিয়েছে, তার মেয়াদ-শেষের সারি লেখা — রাতের কাজ।
     *
     * ⓘ ব্যালান্স এই সারি ছাড়াও ঠিক থাকে ([[replay()]])। ⚠️ তবু লেখা হয়,
     * কারণ খাতায় *"এই ৫০ পয়েন্ট ১ জানুয়ারি ফুরিয়েছে"* কথাটা থাকা দরকার
     * — প্রতিবেদন আর ক্রেতার প্রশ্ন দুইটার জন্যই।
     *
     * ⛔ দুইবার চললে দুইবার লেখে না: বন্ধ লট আর ছোঁয়া হয় না, আর ডাটাবেজের
     * `(reverses_entry_id, kind)` শেষ দেয়াল।
     *
     * @return int কয়টা মেয়াদ-শেষের সারি লেখা হলো
     */
    public function expireDue(?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $day = $at->toDateString();

        $customers = LoyaltyEntry::query()
            ->where('points', '>', 0)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $day)
            ->where('occurred_at', '<=', $at)
            ->whereDoesntHave('expiry')
            ->distinct()
            ->orderBy('customer_id')
            ->pluck('customer_id');

        $written = 0;

        foreach ($customers as $customerId) {
            $written += DB::transaction(function () use ($customerId, $at, $day) {
                $this->lockCustomer((int) $customerId);

                $count = 0;

                foreach ($this->replay((int) $customerId, $at, true)['lots'] as $lot) {
                    if ($this->isPastDue($lot, $day)) {
                        $this->writeExpiry($lot, $at);
                        $count++;
                    }
                }

                return $count;
            });
        }

        return $written;
    }

    /**
     * ⓘ পয়েন্টের টাকার মূল্য — সেটিং `promotion.loyalty_point_value`, না থাকলে ১ পয়েন্ট = ১ টাকা।
     *
     * ⚠️ ইঞ্জিন আজ পয়েন্টকে মুখমূল্যে ধরে ([[PromotionEngine::worthOf()]]),
     * তাই ডিফল্ট ১ — দুই জায়গা একই কথা বলে।
     */
    public function worthOf(string $points): string
    {
        $rate = (string) $this->settings->get('promotion.loyalty_point_value', '1');

        return bcmul($points, is_numeric($rate) ? $rate : '1', self::SCALE);
    }

    // ── ভিতরের কাজ ──────────────────────────────────────────────────────

    /**
     * ⓘ অর্জনের ফেরত। ⚠️ দিন পেরোনো অথচ না-লেখা মেয়াদ আগে লেখা হয় —
     * নাহলে ফেরতের অঙ্ক থেকে ফুরোনো অংশটা বাদ পড়ত না।
     */
    private function reverseEarn(LoyaltyEntry $earn, Carbon $now): void
    {
        $lot = $this->replay((int) $earn->customer_id, $now, true)['lots'][$earn->id] ?? null;
        $expired = '0';

        if ($lot !== null) {
            if ($this->isPastDue($lot, $now->toDateString())) {
                $this->writeExpiry($lot, $now);
                $lot['expired'] = bcadd($lot['expired'], $lot['remaining'], self::SCALE);
            }

            $expired = $lot['expired'];
        }

        $back = bcsub((string) $earn->points, $expired, self::SCALE);

        if (bccomp($back, '0', self::SCALE) < 0) {
            $back = '0';
        }

        /* ⓘ শূন্য হলেও সারিটা লেখা হয় — *"এই বিলের পয়েন্ট ফেরানো হয়েছিল"* কথাটা খাতায় থাকে */
        $this->write([
            'company_id' => $earn->company_id,
            'customer_id' => $earn->customer_id,
            'kind' => LoyaltyKind::REVERSE,
            'points' => bcmul($back, '-1', self::SCALE),
            'reverses_entry_id' => $earn->id,
            'source_type' => $earn->source_type,
            'source_id' => $earn->source_id,
            'occurred_at' => $now,
        ]);
    }

    /** ⓘ খরচের ফেরত — যে লটগুলো থেকে গিয়েছিল, তাদের সবচেয়ে দূরের মেয়াদে। */
    private function reverseRedeem(LoyaltyEntry $redeem, Carbon $now): void
    {
        $state = $this->replay((int) $redeem->customer_id, $now, true);
        $expiresOn = null;
        $forever = false;

        foreach (array_keys($state['allocations'][$redeem->id] ?? []) as $lotId) {
            $lotExpiry = $state['lots'][$lotId]['expires_on'] ?? null;

            if ($lotExpiry === null) {
                $forever = true;

                break;
            }

            if ($expiresOn === null || $lotExpiry > $expiresOn) {
                $expiresOn = $lotExpiry;
            }
        }

        $this->write([
            'company_id' => $redeem->company_id,
            'customer_id' => $redeem->customer_id,
            'kind' => LoyaltyKind::REVERSE,
            'points' => bcmul((string) $redeem->points, '-1', self::SCALE),
            'expires_on' => $forever ? null : $expiresOn,
            'reverses_entry_id' => $redeem->id,
            'source_type' => $redeem->source_type,
            'source_id' => $redeem->source_id,
            'occurred_at' => $now,
        ]);
    }

    /** @param  array{entry: LoyaltyEntry, remaining: string}  $lot */
    private function writeExpiry(array $lot, Carbon $at): void
    {
        $this->write([
            'company_id' => $lot['entry']->company_id,
            'customer_id' => $lot['entry']->customer_id,
            'kind' => LoyaltyKind::EXPIRE,
            'points' => bcmul($lot['remaining'], '-1', self::SCALE),
            'reverses_entry_id' => $lot['entry']->id,
            'source_type' => self::EXPIRY_SOURCE,
            'source_id' => $lot['entry']->id,
            'occurred_at' => $at,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function write(array $row): LoyaltyEntry
    {
        return LoyaltyEntry::query()->create($row + ['created_by' => auth()->id()]);
    }

    /**
     * ⛔ ক্রেতার সারিতে তালা — আর অন্য প্রতিষ্ঠানের ক্রেতা এখানে খুঁজেই পাওয়া যায় না।
     *
     * ⓘ [[BelongsToCompany]] কোয়েরিটা এই প্রতিষ্ঠানে বাঁধে। ⚠️ তাই অন্য
     * প্রতিষ্ঠানের ক্রেতার নম্বর পাঠালে "ক্রেতা নেই" — তার খাতায় কখনো
     * হাত পড়ে না।
     */
    private function lockCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => __('promotion::loyalty.customer_unknown'),
            ]);
        }

        return $customer;
    }

    /**
     * ⭐ খাতাটা শুরু থেকে পড়া — প্রতিটা পয়েন্ট-আনা সারি একটা লট।
     *
     * ── ⓘ নিয়মগুলো ──────────────────────────────────────────────────
     *   ⓵ ধনাত্মক সারি → নতুন লট; আগে ঘাটতি (`debt`) থাকলে আগে সেটা মেটায়
     *   ⓶ খরচ → আগে-ফুরোবে-যে লট থেকে (মেয়াদহীন সবার শেষে), ঘটনার দিনে
     *     যে লট বেঁচে ছিল কেবল সেগুলো থেকে
     *   ⓷ অর্জনের ফেরত → আগে নিজের লট থেকে, বাকিটা খরচের মতো
     *   ⓸ মেয়াদ-শেষ → নিজের লট বন্ধ
     *   ⓹ কোথাও না মিললে → ঘাটতি; ⭐ ফলে Σলট − ঘাটতি = Σpoints, সবসময়
     *
     * ⚠️ খাতার সারি ঘটনার সময় ধরে সাজানো (`occurred_at`, তারপর `id`) —
     * লেখার সময় ধরে নয়।
     *
     * ⛔ `$forWrite` — লেখার আগে পড়লে তালাবদ্ধ পড়া। ⓘ সাধারণ SELECT
     * লেনদেনের পুরনো ছবি দেখতে পারে, তাই অন্য কাউন্টারের এইমাত্র লেখা
     * খরচটা বাদ পড়ত, আর ব্যালান্স বেশি দেখাত।
     *
     * @return array{
     *     lots: array<int, array{entry: LoyaltyEntry, remaining: string, expires_on: string|null, closed: bool, expired: string}>,
     *     allocations: array<int, array<int, string>>,
     *     debt: string,
     *     balance: string,
     * }
     */
    private function replay(int $customerId, Carbon $at, bool $forWrite = false): array
    {
        $lots = [];
        $allocations = [];
        $debt = '0';

        $entries = LoyaltyEntry::query()
            ->where('customer_id', $customerId)
            ->where('occurred_at', '<=', $at)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->when($forWrite, fn ($q) => $q->lockForUpdate())
            ->get();

        foreach ($entries as $entry) {
            $points = (string) $entry->points;
            $day = $entry->occurred_at->toDateString();

            if (bccomp($points, '0', self::SCALE) > 0) {
                $pay = $this->smaller($points, $debt);
                $debt = bcsub($debt, $pay, self::SCALE);

                $lots[$entry->id] = [
                    'entry' => $entry,
                    'remaining' => bcsub($points, $pay, self::SCALE),
                    'expires_on' => $entry->expires_on?->toDateString(),
                    'closed' => false,
                    'expired' => '0',
                ];

                continue;
            }

            $need = bcmul($points, '-1', self::SCALE);

            if (bccomp($need, '0', self::SCALE) === 0) {
                continue;
            }

            $own = $entry->reverses_entry_id !== null ? ($lots[$entry->reverses_entry_id] ?? null) : null;

            if ($entry->kind === LoyaltyKind::EXPIRE) {
                if ($own !== null) {
                    $take = $this->smaller($need, $lots[$entry->reverses_entry_id]['remaining']);
                    $lots[$entry->reverses_entry_id]['remaining'] = bcsub($own['remaining'], $take, self::SCALE);
                    $lots[$entry->reverses_entry_id]['expired'] = bcadd($own['expired'], $need, self::SCALE);
                    $lots[$entry->reverses_entry_id]['closed'] = true;
                    $need = bcsub($need, $take, self::SCALE);
                }

                $debt = bcadd($debt, $need, self::SCALE);

                continue;
            }

            if ($entry->kind === LoyaltyKind::REVERSE && $own !== null && ! $own['closed']) {
                $take = $this->smaller($need, $own['remaining']);
                $lots[$entry->reverses_entry_id]['remaining'] = bcsub($own['remaining'], $take, self::SCALE);
                $need = bcsub($need, $take, self::SCALE);
            }

            $taken = [];
            $need = $this->consume($lots, $need, $day, $taken);

            if ($entry->kind === LoyaltyKind::REDEEM) {
                $allocations[$entry->id] = $taken;
            }

            $debt = bcadd($debt, $need, self::SCALE);
        }

        $live = '0';
        $atDay = $at->toDateString();

        foreach ($lots as $lot) {
            if ($this->isLive($lot, $atDay)) {
                $live = bcadd($live, $lot['remaining'], self::SCALE);
            }
        }

        return [
            'lots' => $lots,
            'allocations' => $allocations,
            'debt' => $debt,
            'balance' => bcsub($live, $debt, self::SCALE),
        ];
    }

    /**
     * ⓘ বেঁচে থাকা লট থেকে নেওয়া — আগে-ফুরোবে-যে আগে, মেয়াদহীন শেষে।
     *
     * ⭐ ক্রেতার পক্ষে: ⓘ ফুরোতে-বসা পয়েন্ট আগে খরচ হলে ক্রেতা কম হারান।
     *
     * @param  array<int, array{remaining: string, expires_on: string|null, closed: bool}>  $lots
     * @param  array<int, string>  $taken
     * @return string যা মেটানো গেল না
     */
    private function consume(array &$lots, string $need, string $day, array &$taken): string
    {
        $ids = array_keys(array_filter($lots, fn (array $lot) => $this->isLive($lot, $day)));

        usort($ids, function (int $a, int $b) use ($lots) {
            $ea = $lots[$a]['expires_on'];
            $eb = $lots[$b]['expires_on'];

            if ($ea === $eb) {
                return $a <=> $b;
            }

            if ($ea === null) {
                return 1;
            }

            if ($eb === null) {
                return -1;
            }

            return strcmp($ea, $eb);
        });

        foreach ($ids as $id) {
            if (bccomp($need, '0', self::SCALE) <= 0) {
                break;
            }

            $take = $this->smaller($need, $lots[$id]['remaining']);
            $lots[$id]['remaining'] = bcsub($lots[$id]['remaining'], $take, self::SCALE);
            $need = bcsub($need, $take, self::SCALE);
            $taken[$id] = $take;
        }

        return $need;
    }

    /**
     * ⭐ লটটা এই দিনে খরচ করা যায় কি না — মেয়াদের দিনটাও ধরে।
     *
     * @param  array{remaining: string, expires_on: string|null, closed: bool}  $lot
     */
    private function isLive(array $lot, string $day): bool
    {
        return ! $lot['closed']
            && bccomp($lot['remaining'], '0', self::SCALE) > 0
            && ($lot['expires_on'] === null || $lot['expires_on'] >= $day);
    }

    /**
     * ⓘ দিন পেরিয়েছে, অথচ মেয়াদ-শেষের সারি এখনো লেখা হয়নি।
     *
     * @param  array{remaining: string, expires_on: string|null, closed: bool}  $lot
     */
    private function isPastDue(array $lot, string $day): bool
    {
        return ! $lot['closed']
            && bccomp($lot['remaining'], '0', self::SCALE) > 0
            && $lot['expires_on'] !== null
            && $lot['expires_on'] < $day;
    }

    private function smaller(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $a : $b;
    }
}
