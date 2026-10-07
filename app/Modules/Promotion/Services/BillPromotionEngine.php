<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * গোটা বিলের অফার — কম্বো আর বান্ডল (স্পেক §৭-ছ, §৭-জ)।
 *
 * ── ⚠️ কেন [[PromotionEngine]] একা পারে না ──────────────────────────
 * ⓘ সে একবারে **একটা সারি** দেখে: *"এই সারিতে কী খাটে"*। ⛔ কিন্তু
 * *"ক আর খ একসাথে নিলে ১০%"* প্রশ্নটা কোনো একটা সারির নয় — ক-এর সারি
 * জানে না খ বিলে আছে কি না। তাই কম্বোর উত্তর কেবল গোটা বিল দেখেই দেওয়া যায়।
 *
 * ── ⭐ সারির অফার এখানে **নতুন করে হিসাব হয় না** ──────────────────────
 * ⓘ প্রতিটা সারি আগের মতোই [[PromotionEngine::offersFor()]]-এ যায়, আর
 * তার উত্তর হুবহু ফেরে। ⛔ নিয়মটা এখানে আবার লিখলে দুই কপি একদিন আলাদা
 * হত, আর একই সারি বিক্রয় বিলে এক ছাড়, সরাসরি বিক্রয়ে আরেক ছাড় পেত।
 *
 * ── ⛔ কম্বো কখনো সারির ইঞ্জিন দিয়ে ঢোকে না ─────────────────────────
 * ⚠️ [[PromotionEngine]] অফারের ধরন দেখে না। ⓘ একটা কম্বোর শর্তহীন
 * সুবিধা আর পণ্যের সুযোগ-সারি না থাকলে সে কম্বোটাকে **প্রতিটা সারিতে**
 * খাটাত — *"ক + খ একসাথে"* অফারটা ক একা কিনলেও খুলত, আর প্রতি সারিতে
 * একবার করে। ⭐ তাই সারির উত্তর থেকে কম্বো আর বান্ডল এখানে ছেঁকে ফেলা
 * হয়; ওদের একমাত্র পথ নিচের `combosFor()`।
 *
 * ── ⓘ এই ইঞ্জিনও কিছু বসায় না ───────────────────────────────────────
 * ⛔ সে কেবল বলে কী খাটে আর সুবিধাটা কোন সারিতে কতটা ছড়াবে। ⚠️ বসানো
 * বিক্রয়ের কাজ, আর মানুষ চাপেন (§১০)।
 */
final class BillPromotionEngine
{
    /**
     * ⭐ যে ধরনগুলো গোটা বিল দেখে খোলে — এক জায়গায় লেখা।
     *
     * ⚠️ তালিকাটা দুই জায়গায় পড়া হয়: সারির উত্তর ছাঁকতে, আর কম্বো খুঁজতে।
     * ⛔ দুই জায়গায় আলাদা লিখলে একদিন একটা নতুন ধরন একটায় যোগ হত আর
     * অন্যটায় নয় — তখন অফারটা হয় কোথাও খুলত না, নয় দুইবার খুলত।
     *
     * @var list<PromotionType>
     */
    public const BILL_TYPES = [PromotionType::COMBO, PromotionType::BUNDLE];

    /** ⓘ [[PromotionEngine]]-এর `worth`-এর মতোই চার ঘর — দুই ইঞ্জিনের অঙ্ক এক মাপে */
    public const SCALE = 4;

    /**
     * ⓘ বিলের মাথার দিক — ক্রেতা, শাখা, গুদাম; সারি-প্রতি বদলায় না।
     *
     * ⛔ পণ্যের দিক (পণ্য, শ্রেণি, ব্র্যান্ড) এখানে নেই, আর সেটা ইচ্ছাকৃত —
     * কম্বোর পণ্য বলে [[ComboItem]], সুযোগের সারি নয়।
     *
     * @var array<string, string>
     */
    private const HEADER_KEYS = [
        'branch' => 'branch_id',
        'warehouse' => 'warehouse_id',
        'channel' => 'channel_id',
        'customer' => 'customer_id',
        'party_type' => 'party_type_id',
        'location' => 'location_id',
    ];

    public function __construct(private readonly PromotionEngine $engine) {}

    public static function isBillType(?PromotionType $type): bool
    {
        return $type !== null && in_array($type, self::BILL_TYPES, true);
    }

    /**
     * ⭐ এই বিলে কোন অফারগুলো খাটে — সারির আর গোটা বিলের, একসাথে।
     *
     * ⓘ প্রতিটা সারি [[PromotionEngine::offersFor()]]-এর সারির আকারেই,
     * সাথে `line_id`: `['line_id' => 7, 'product_id' => .., 'qty' => '2',
     * 'value' => '500', 'customer_id' => ..]`। `line_id` না দিলে তালিকার ক্রমিক।
     *
     * ⓘ প্রতিটা ফেরত সারি:
     *   `source`   — `line` (এক সারির অফার) বা `bill` (কম্বো/বান্ডল)
     *   `promotion`, `benefit`, `worth` — সারির ইঞ্জিনের মতোই
     *   `fits`     — কম্বোটা কয় সেট মিলেছে (ছাদের পরে); সারির অফারে `null`
     *   `gift_qty` — উপহার হলে কয়টা; নাহলে `null`
     *   `line_ids` — সুবিধাটা কোন সারিগুলোর উপর
     *   `spread`   — `line_id => অঙ্ক`, যোগফল **ঠিক** `worth`
     *
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, array{source: string, promotion: Promotion, benefit: PromotionBenefit, worth: string, fits: int|null, gift_qty: string|null, line_ids: list<int|string>, spread: array<int|string, string>}>
     */
    public function offersForBill(array $lines, ?Carbon $at = null): Collection
    {
        $at ??= Carbon::now();
        $lines = $this->keyed($lines);

        if ($lines === []) {
            return collect();
        }

        $candidates = collect();

        foreach ($lines as $id => $line) {
            foreach ($this->engine->offersFor($line, $at) as $row) {
                /* ⛔ কম্বো সারির পথে ঢুকলে প্রতি সারিতে একবার খুলত — উপরের নোট */
                if (self::isBillType($row['promotion']->type)) {
                    continue;
                }

                $candidates->push([
                    'source' => 'line',
                    'promotion' => $row['promotion'],
                    'benefit' => $row['benefit'],
                    'worth' => $row['worth'],
                    'fits' => null,
                    'gift_qty' => $row['benefit']->kind->movesStock() ? (string) $row['benefit']->amount : null,
                    'line_ids' => [$id],
                    'spread' => [$id => $row['worth']],
                ]);
            }
        }

        foreach ($this->combosFor($lines, $at) as $row) {
            $candidates->push($row);
        }

        return $this->settle($candidates);
    }

    /**
     * ⭐ একটা অঙ্ক কয়েকটা সারিতে ভাগ — মূল্যের অনুপাতে, যোগফল হুবহু।
     *
     * ── ⚠️ কেন শেষ সারি বাকিটা পায় ─────────────────────────────────
     * ⓘ ১০০ টাকা তিন সমান সারিতে: ৩৩.৩৩৩৩ × ৩ = ৯৯.৯৯৯৯। ⛔ প্রতিটা ভাগ
     * আলাদা করে কাটলে এক পয়সার ভগ্নাংশ **হারিয়ে যায়** — বিলের ছাড় বলত
     * ১০০, সারিগুলোর যোগ বলত ৯৯.৯৯৯৯, আর খাতা মিলত না। ⭐ তাই শেষ সারি
     * পায় *"মোট − বাকিদের যোগ"*, আর যোগফল সবসময় ঠিক।
     *
     * ⓘ `public`, কারণ বিক্রয় একদিন পয়সায় (`scale 2`) আবার ভাগ করবে —
     * তখন একই নিয়ম, নতুন কপি নয়।
     *
     * @param  array<int|string, string>  $weights  `line_id => ওজন` — বিলের ক্রমে
     * @return array<int|string, string>
     */
    public function spreadOver(string $total, array $weights, int $scale = self::SCALE): array
    {
        if ($weights === []) {
            return [];
        }

        $sum = '0';
        foreach ($weights as $w) {
            $sum = bcadd($sum, $w, 8);
        }

        /*
         * ⚠️ সব ওজন শূন্য — যেমন সব সারি বিনামূল্যের।
         *
         * ⛔ অনুপাতে ভাগ করতে গেলে শূন্য দিয়ে ভাগ হত। ⓘ তখন সমান ভাগ —
         * অঙ্কটা হারায় না, আর কোনো সারি একা সবটা পায় না।
         */
        if (bccomp($sum, '0', 8) <= 0) {
            $weights = array_map(fn () => '1', $weights);
            $sum = (string) count($weights);
        }

        $ids = array_keys($weights);
        $last = array_pop($ids);
        $spread = [];
        $given = '0';

        foreach ($ids as $id) {
            /* ⓘ bcmath ছেঁটে ফেলে, গোল করে না — তাই বাকিটা কখনো ঋণাত্মক হয় না */
            $share = bcdiv(bcmul($total, $weights[$id], 12), $sum, $scale);
            $spread[$id] = $share;
            $given = bcadd($given, $share, $scale);
        }

        $spread[$last] = bcsub($total, $given, $scale);

        return $spread;
    }

    /**
     * ⓘ সারিগুলোকে `line_id` ধরে সাজানো।
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<int|string, array<string, mixed>>
     */
    private function keyed(array $lines): array
    {
        $keyed = [];

        foreach (array_values($lines) as $index => $line) {
            $id = $line['line_id'] ?? $index;

            /*
             * ⛔ একই `line_id` দুইবার।
             *
             * ⚠️ চুপচাপ মেনে নিলে দ্বিতীয় সারিটা প্রথমটাকে মুছে দিত — বিলের
             * একটা পণ্য অফারের হিসাব থেকে উধাও, আর কম্বোটা অকারণে বন্ধ।
             */
            if (array_key_exists($id, $keyed)) {
                throw new InvalidArgumentException("Bill line id [{$id}] appears twice.");
            }

            $keyed[$id] = $line;
        }

        return $keyed;
    }

    /**
     * ⭐ গোটা বিল দেখে কোন কম্বো আর বান্ডল খোলে।
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function combosFor(array $lines, Carbon $at): array
    {
        $combos = Promotion::query()
            ->liveOn($at)
            ->whereIn('type', array_map(fn (PromotionType $t) => $t->value, self::BILL_TYPES))
            ->with(['scopes', 'conditions.benefits.giftProduct', 'benefits.giftProduct'])
            ->orderByDesc('priority')
            ->get();

        if ($combos->isEmpty()) {
            return [];
        }

        $items = ComboItem::query()
            ->whereIn('promotion_id', $combos->modelKeys())
            ->get()
            ->groupBy('promotion_id');

        $byProduct = $this->byProduct($lines);
        $header = $this->headerOf($lines);
        $rows = [];

        foreach ($combos as $combo) {
            /** @var Collection<int, ComboItem> $parts */
            $parts = $items->get($combo->id, collect());

            /*
             * ⛔ উপাদানহীন কম্বো কখনো খোলে না।
             *
             * ⚠️ *"সবগুলো উপাদান আছে কি না"* প্রশ্নটা খালি তালিকায় **সবসময়
             * হ্যাঁ** — কোনো উপাদানই তো অনুপস্থিত নয়। ⓘ এই লাইনটা না থাকলে
             * যে কম্বোর পণ্য এখনো বাছা হয়নি, সে প্রতিটা বিলে খুলত।
             */
            if ($parts->isEmpty()) {
                continue;
            }

            if (! $this->scopeFits($combo, $header)) {
                continue;
            }

            $fits = $this->fitsOf($parts, $byProduct);

            if ($fits < 1) {
                continue;
            }

            $row = $this->bestBenefitOf($combo, $parts, $byProduct, $lines, $fits);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * ⓘ পণ্য ধরে বিলের যোগফল — একই পণ্য দুই সারিতে থাকলে দুইটাই গোনা।
     *
     * ⚠️ শূন্য বা ঋণাত্মক পরিমাণের সারি বাদ: ⛔ একটা ফেরতের সারি (−২) কম্বোর
     * পরিমাণ কমিয়ে দিত, আর তার ঋণাত্মক মূল্য ছাড় ভাগের ওজন উল্টে দিত।
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int, array{qty: string, line_ids: list<int|string>}>
     */
    private function byProduct(array $lines): array
    {
        $agg = [];

        foreach ($lines as $id => $line) {
            $qty = (string) ($line['qty'] ?? '0');

            if (! isset($line['product_id']) || bccomp($qty, '0', self::SCALE) <= 0) {
                continue;
            }

            $pid = (int) $line['product_id'];
            $agg[$pid] ??= ['qty' => '0', 'line_ids' => []];
            $agg[$pid]['qty'] = bcadd($agg[$pid]['qty'], $qty, self::SCALE);
            $agg[$pid]['line_ids'][] = $id;
        }

        return $agg;
    }

    /**
     * ⓘ বিলের মাথা — ক্রেতা, শাখা, গুদাম।
     *
     * ⚠️ সারিগুলো দুই রকম ক্রেতা বললে সেই দিকটা *"অজানা"*। ⛔ প্রথম সারির
     * কথা মেনে নিলে দ্বিতীয় ক্রেতার বিলে প্রথম ক্রেতার জন্য বানানো কম্বো
     * খুলত। ⓘ অজানা দিক নিচের `scopeFits()`-এ সৎভাবে *"না"* বলে।
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<string, int|null>
     */
    private function headerOf(array $lines): array
    {
        $header = [];

        foreach (self::HEADER_KEYS as $key) {
            $seen = null;
            $clash = false;

            foreach ($lines as $line) {
                $value = $line[$key] ?? null;

                if ($value === null) {
                    continue;
                }

                if ($seen !== null && (int) $value !== $seen) {
                    $clash = true;
                    break;
                }

                $seen = (int) $value;
            }

            $header[$key] = $clash ? null : $seen;
        }

        return $header;
    }

    /**
     * ⓶ · এই কম্বোটা এই বিলের ক্রেতা ও শাখার জন্য কি না।
     *
     * ── ⚠️ নিয়মটা [[PromotionEngine::scopeFits()]]-এর — দিক-প্রতি ─────
     * ⓘ দিকে সারি না থাকলে *"সব"*; থাকলে অন্তত একটা মিলতে হবে; বিলে ঐ দিক
     * জানা না থাকলে *"না"*। ⛔ ইঞ্জিনের পদ্ধতিটা `private`, তাই আজ এখানে
     * মাথার দিকগুলোর জন্য একই নিয়ম — ⭐ ওটা `public` হলে এই পদ্ধতি মুছে
     * সরাসরি ডাকতে হবে (জোড়ার প্রস্তাব দেখুন)।
     *
     * ⛔ পণ্যের দিকের সুযোগ-সারি কম্বোতে থাকলে অফারটা খোলে না। ⚠️ কম্বোর
     * পণ্য বলে [[ComboItem]]; একটা *"শ্রেণি: সাবান"* সারি কী বোঝায় — সব
     * উপাদান, না যেকোনো একটা — সেটা কেউ বলেননি। ⓘ না-জানা শর্ত মেনে
     * নেওয়ার চেয়ে না খোলা সৎ, আর [[ComboRules]] ঐ সারি বসাতেই দেয় না।
     *
     * @param  array<string, int|null>  $header
     */
    private function scopeFits(Promotion $combo, array $header): bool
    {
        foreach ($combo->scopes->groupBy(fn ($s) => $s->kind->value) as $kind => $rows) {
            $kind = ScopeKind::from((string) $kind);

            if ($kind->isAboutTheGoods()) {
                return false;
            }

            $wanted = $header[self::HEADER_KEYS[$kind->value]] ?? null;

            if ($wanted === null) {
                return false;
            }

            if (! $rows->contains(fn ($s) => (int) $s->target_id === $wanted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ⭐ কম্বোটা কয় সেট মেলে — `floor(প্রতিটা উপাদানের পরিমাণ ÷ ন্যূনতম)`-এর সবচেয়ে ছোটটা।
     *
     * ⓘ ক×২ + খ×১, দুইটারই ন্যূনতম ১: ক দুই সেট দিতে পারে, খ একটা। ⛔ সেট
     * মানে **সবগুলো** একসাথে, তাই উত্তর ১ — বড়টা নিলে দ্বিতীয় সেটে খ ছাড়াই
     * ছাড় যেত।
     *
     * @param  Collection<int, ComboItem>  $parts
     * @param  array<int, array{qty: string, line_ids: list<int|string>}>  $byProduct
     */
    private function fitsOf(Collection $parts, array $byProduct): int
    {
        $fits = null;

        foreach ($parts as $part) {
            $min = (string) $part->min_qty;

            /*
             * ⛔ শূন্য ন্যূনতম — শূন্য দিয়ে ভাগ, আর *"এই পণ্য না কিনলেও চলে"*।
             * ⓘ [[ComboRules]] এটা বসাতে দেয় না; পুরনো বা হাতে বসানো সারি এখানে থামে।
             */
            if (bccomp($min, '0', self::SCALE) <= 0) {
                return 0;
            }

            $have = $byProduct[(int) $part->product_id]['qty'] ?? null;

            if ($have === null) {
                return 0;
            }

            /* ⓘ `scale 0`-এ bcmath ছেঁটে ফেলে — ধনাত্মক সংখ্যায় ঠিক floor */
            $n = (int) bcdiv($have, $min, 0);
            $fits = $fits === null ? $n : min($fits, $n);
        }

        return $fits ?? 0;
    }

    /**
     * ⭐ কম্বোর সেরা সুবিধাটা — [[PromotionEngine::bestBenefitOf()]]-এর মতোই বড়টা জেতে।
     *
     * ── ⓘ কম্বোর শর্ত কী মাপে ──────────────────────────────────────
     * `quantity` = কয় সেট মিলেছে, `value` = সেটের ভিতরের মালের দাম। ⭐ তাই
     * *"২ সেটে ৫%, ৩ সেটে ৮%"* স্ল্যাব কম্বোতেও চলে, আর শর্তহীন সুবিধা
     * কম্বো মিললেই খোলে।
     *
     * @param  Collection<int, ComboItem>  $parts
     * @param  array<int, array{qty: string, line_ids: list<int|string>}>  $byProduct
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function bestBenefitOf(Promotion $combo, Collection $parts, array $byProduct, array $lines, int $fits): ?array
    {
        $candidates = [];

        foreach ($combo->conditions as $condition) {
            $measure = match ($condition->kind->value) {
                'quantity' => (string) $fits,
                'value' => $this->sum($this->weightsFor($parts, $byProduct, $lines, $fits)),
                default => null,
            };

            if ($measure === null || ! $condition->covers($measure)) {
                continue;
            }

            foreach ($condition->benefits as $benefit) {
                $candidates[] = $benefit;
            }
        }

        foreach ($combo->benefits->whereNull('promotion_condition_id') as $benefit) {
            $candidates[] = $benefit;
        }

        $best = null;

        foreach ($candidates as $benefit) {
            /* ⛔ দেওয়াই যায় না এমন সুবিধা — [[PromotionBenefit::isDeliverable()]] */
            if (! $benefit->isDeliverable()) {
                continue;
            }

            $row = $this->rowFor($combo, $benefit, $parts, $byProduct, $lines, $fits);

            if ($row !== null && ($best === null || bccomp($row['worth'], $best['worth'], self::SCALE) > 0)) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * ⓘ একটা সুবিধা এই কম্বোতে কত — ছাদ মেনে, আর কোন সারিতে কতটা।
     *
     * @param  Collection<int, ComboItem>  $parts
     * @param  array<int, array{qty: string, line_ids: list<int|string>}>  $byProduct
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function rowFor(Promotion $combo, PromotionBenefit $benefit, Collection $parts, array $byProduct, array $lines, int $fits): ?array
    {
        /*
         * ⛔ প্রতি বিলে সর্বোচ্চ কয় সেট — `cap_per_bill`।
         *
         * ⚠️ ছাদটা **সেট** গোনে, টাকা নয়: ⓘ *"প্রতি বিলে সর্বোচ্চ ২ সেট"*।
         * ⛔ ছাদ না মানলে একটা বড় পাইকারি বিল পঞ্চাশ সেটের ছাড় বা উপহার
         * নিয়ে যেত, আর পরের ক্রেতারা কিছুই পেতেন না।
         */
        if ($benefit->cap_per_bill !== null) {
            $fits = min($fits, (int) bcdiv((string) $benefit->cap_per_bill, '1', 0));
        }

        if ($fits < 1) {
            return null;
        }

        $weights = $this->weightsFor($parts, $byProduct, $lines, $fits);
        $amount = (string) $benefit->amount;
        $giftQty = null;

        $worth = match ($benefit->kind) {
            /* ⓘ সেটের ভিতরের মালের দামের উপর — সেটের বাইরের বাড়তি মাল ছাড় পায় না */
            BenefitKind::PERCENT => bcdiv(bcmul($this->sum($weights), $amount, 8), '100', self::SCALE),

            /* ⓘ প্রতি সেটে একবার — বান্ডলের গুণিতক */
            BenefitKind::AMOUNT, BenefitKind::CREDIT, BenefitKind::POINTS => bcmul($amount, (string) $fits, self::SCALE),

            /* ⚠️ উপহারের দাম বিক্রয়মূল্যে — [[PromotionEngine::worthOf()]]-এর যুক্তি */
            BenefitKind::GOODS => bcmul(
                $giftQty = bcmul($amount, (string) $fits, self::SCALE),
                (string) ($benefit->giftProduct?->sale_price ?? '0'),
                self::SCALE),
        };

        return [
            'source' => 'bill',
            'promotion' => $combo,
            'benefit' => $benefit,
            'worth' => $worth,
            'fits' => $fits,
            'gift_qty' => $giftQty,
            'line_ids' => array_keys($weights),
            'spread' => $this->spreadOver($worth, $weights),
        ];
    }

    /**
     * ⭐ প্রতিটা উপাদান-সারির কতটা মূল্য সেটের ভিতরে পড়ে — বিলের ক্রমে।
     *
     * ⓘ ক×৩ বিলে, সেট একটা, ন্যূনতম ১: ক-এর সারির এক-তৃতীয়াংশ দাম সেটে।
     * ⛔ পুরো সারির দাম ধরলে ১০% ছাড়টা সেটের বাইরের দুইটা ক-তেও বসত।
     *
     * ⚠️ ক্রমটা বিলের, উপাদানের নয় — ⓘ *"বাকিটা শেষ সারিতে"* মানে বিলের
     * শেষ সারি, যাতে একই বিল সবসময় একই ভাগ পায়।
     *
     * @param  Collection<int, ComboItem>  $parts
     * @param  array<int, array{qty: string, line_ids: list<int|string>}>  $byProduct
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int|string, string>
     */
    private function weightsFor(Collection $parts, array $byProduct, array $lines, int $fits): array
    {
        $found = [];

        foreach ($parts as $part) {
            $agg = $byProduct[(int) $part->product_id];
            $used = bcmul((string) $fits, (string) $part->min_qty, self::SCALE);
            $whole = bccomp($used, $agg['qty'], self::SCALE) >= 0;

            foreach ($agg['line_ids'] as $id) {
                $value = (string) ($lines[$id]['value'] ?? '0');
                $found[$id] = $whole ? $value : bcdiv(bcmul($value, $used, 8), $agg['qty'], self::SCALE);
            }
        }

        $ordered = [];
        foreach (array_keys($lines) as $id) {
            if (array_key_exists($id, $found)) {
                $ordered[$id] = $found[$id];
            }
        }

        return $ordered;
    }

    /** @param  array<int|string, string>  $values */
    private function sum(array $values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v, self::SCALE);
        }

        return $sum;
    }

    /**
     * ⓸ · একাধিক অফার একই সারি ছুঁলে কে জেতে — [[PromotionCombines]]-এর নিয়মে।
     *
     * ── ⚠️ ক্রম আর নিয়ম [[PromotionEngine::settleConflicts()]]-এর ────
     * ⓘ অগ্রাধিকার (বড় আগে) → সুবিধার অঙ্ক → `id`; বিজয়ীর পর বাকিরা কেবল
     * তখনই যোগ হয় যখন **দুইজনেই** রাজি (`sitsWith`)। ⛔ ইঞ্জিনের পদ্ধতিটা
     * `private` আর এক সারির জন্য লেখা, তাই এখানে বিলের মাপে — ⭐ একটা
     * শেয়ার্ড র‍্যাংকার বানানোর প্রস্তাব জোড়ার নোটে।
     *
     * ── ⭐ সংঘাত কেবল **একই সারিতে** ────────────────────────────────
     * ⓘ ক+খ কম্বো আর গ-এর সারির ছাড় কোনো সারি ভাগ করে না, তাই দুইটাই
     * খাটে — `BEST` হলেও। ⛔ গোটা বিলে একটাই জিতলে একটা ছোট কম্বো গ-এর
     * আলাদা, সম্পর্কহীন ছাড়টা কেড়ে নিত।
     *
     * ⓘ কম্বো না থাকলে ফল সারির ইঞ্জিনের উত্তরই: প্রতিটা সারির রাখা
     * অফারগুলো আগেই একে অপরের সাথে বসতে রাজি।
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function settle(Collection $rows): Collection
    {
        $ranked = $rows->sortBy([
            fn ($a, $b) => $b['promotion']->priority <=> $a['promotion']->priority,
            fn ($a, $b) => bccomp($b['worth'], $a['worth'], self::SCALE),
            fn ($a, $b) => $a['promotion']->id <=> $b['promotion']->id,
        ])->values();

        $kept = [];

        foreach ($ranked as $row) {
            $fits = true;

            foreach ($kept as $k) {
                $shared = array_intersect(
                    array_map('strval', $k['line_ids']),
                    array_map('strval', $row['line_ids']));

                if ($shared === []) {
                    continue;
                }

                if (! $k['promotion']->combines->sitsWith($row['promotion']->combines)) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                $kept[] = $row;
            }
        }

        return collect($kept);
    }
}
