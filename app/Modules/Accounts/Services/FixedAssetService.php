<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\DepreciationEntry;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সম্পদের খাতা আর মাসের অবচয়।
 *
 * ── অবচয় না বসলে যা হয় ──────────────────────────────────────────────
 * ডেলিভারি ভ্যানটা কেনার দিনের দামেই খাতায় বসে থাকে যতদিন না বিক্রি
 * হয়, আর বছরের মুনাফা ঠিক ওই ক্ষয়ের পরিমাণ বেশি দেখায়।
 *
 * দ্বিতীয়টা বেশি ক্ষতিকর: বেশি মুনাফা দেখে বেশি টাকা তোলা হয়, আর
 * ভ্যানটা বদলানোর দিন টাকাটা থাকে না।
 */
final class FixedAssetService
{
    /** মালিক বা বিনিয়োগকারী দিয়েছেন — মূলধনে ক্রেডিট, তাঁর নামে। */
    public const FUNDED_CAPITAL = 'capital';

    /** ব্যাংক বা নগদ থেকে দেওয়া হয়েছে — ঐ খাতে ক্রেডিট। */
    public const FUNDED_MONEY = 'money';

    /** বাকিতে কেনা — বিক্রেতার পাওনায় ক্রেডিট। */
    public const FUNDED_CREDIT = 'credit';

    /** পুরনো খাতার জের — ব্যবসার আগে থেকেই ছিল। */
    public const FUNDED_OPENING = 'opening';

    /** আগেই ভাউচার কাটা হয়েছে — এখানে কিছু বসবে না। */
    public const FUNDED_ALREADY = 'already';

    /** @var list<string> */
    public const FUNDING_WAYS = [
        self::FUNDED_CAPITAL,
        self::FUNDED_MONEY,
        self::FUNDED_CREDIT,
        self::FUNDED_OPENING,
        self::FUNDED_ALREADY,
    ];

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * খাতায় একটা সম্পদ তোলা — আর টাকাটা কোথা থেকে এল, সেটাও।
     *
     * ── কেন এখন দাখিলাও এখানে বসে, ২০ সেপ্টেম্বর ২০২৬ ───────────────
     * আগে এখানে কোনো দাখিলা বসত না, আর ধরে নেওয়া হত জিনিসটা একটা ক্রয়
     * বা পেমেন্ট ভাউচার দিয়ে কেনা হয়েছে। ⛔ মালিক মেপে দেখালেন সেটা
     * হয় না: অফিসের পাঁচ লাখ টাকার কম্পিউটার বসিয়ে দেখা গেল খাতায়
     * একটা সারিও ওঠেনি — অথচ অবচয় ঠিকই বসতে থাকে (খরচ ডেবিট / সঞ্চিত
     * অবচয় ক্রেডিট)। ⚠️ ফল: স্থিতিপত্রে সম্পদের দাম **ঋণাত্মক**, আর
     * লাভ-ক্ষতিতে এমন জিনিসের খরচ যেটা খাতা অনুযায়ী নেই-ই।
     *
     * ⭐ তাই ফর্ম এখন জিজ্ঞেস করে "টাকাটা কোথা থেকে এল", আর উত্তর ধরে
     * দাখিলাটা এখানেই বসে: সম্পদের খাত ডেবিট / উৎস ক্রেডিট।
     *
     * ⓘ `already` বিকল্পটা ইচ্ছাকৃত — যিনি আগেই ভাউচার কেটেছেন তিনি
     * ওটা বেছে নেন, আর তখন কিছুই বসে না। ওটা না রাখলে পুরনো অভ্যাসে
     * কাজ করা মানুষের কেনা **দুইবার** খাতায় উঠত।
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): FixedAsset
    {
        $method = $data['method'] ?? FixedAsset::STRAIGHT_LINE;

        if ($method === FixedAsset::STRAIGHT_LINE && (int) ($data['life_months'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'life_months' => __('accounts::asset.life_required'),
            ]);
        }

        if ($method === FixedAsset::REDUCING && bccomp((string) ($data['rate'] ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'rate' => __('accounts::asset.rate_required'),
            ]);
        }

        /*
         * বাতিল মূল্য কেনার দামের চেয়ে বেশি হতে পারে না।
         *
         * হলে ক্ষয় ঋণাত্মক হত, অর্থাৎ প্রতি মাসে জিনিসটার দাম বাড়ত —
         * আর খরচের খাতে ঋণাত্মক অঙ্ক বসে মুনাফা বাড়িয়ে দিত।
         */
        if (bccomp((string) ($data['salvage'] ?? '0'), (string) $data['cost'], 4) > 0) {
            throw ValidationException::withMessages([
                'salvage' => __('accounts::asset.salvage_over_cost'),
            ]);
        }

        $funding = $this->fundingFrom($data);

        unset($data['funded_by'], $data['funding_person_id'], $data['funding_account_id'], $data['funding_supplier_id']);

        return DB::transaction(function () use ($data, $method, $funding) {
            $asset = FixedAsset::create([
                ...$data,
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('FA'),
                'method' => $method,
                'status' => FixedAsset::ACTIVE,
                'created_by' => auth()->id(),
            ]);

            if ($funding !== null) {
                $this->posting->post(
                    sourceType: FixedAsset::drillSourceType(),
                    sourceId: $asset->id,
                    trxDate: $asset->acquired_on->toDateString(),
                    lines: [
                        [
                            'account_id' => (int) $asset->asset_account_id,
                            'debit' => (string) $asset->cost,
                            'narration' => $asset->name,
                        ],
                        [
                            'account_id' => $funding['account_id'],
                            'credit' => (string) $asset->cost,
                            'party_type' => $funding['party_type'],
                            'party_id' => $funding['party_id'],
                            'narration' => $asset->name,
                        ],
                    ],
                    documentNo: $asset->document_no,
                );
            }

            return $asset;
        });
    }

    /**
     * টাকাটা কোথা থেকে এল — উত্তরটা খাতার একটা খাতে অনুবাদ করা।
     *
     * ⚠️ খাতগুলো ছকে না থাকলে থামা হয়, নীরবে অন্য খাতে বসানো হয় না:
     * ভুল খাতে বসা পাঁচ লাখ খুঁজে বের করার চেয়ে একটা পরিষ্কার ভুল-বার্তা
     * ঢের ভালো।
     *
     * @param  array<string, mixed>  $data
     * @return array{account_id: int, party_type: ?string, party_id: ?int}|null
     */
    private function fundingFrom(array $data): ?array
    {
        $how = (string) ($data['funded_by'] ?? self::FUNDED_ALREADY);

        if ($how === self::FUNDED_ALREADY) {
            return null;
        }

        if ($how === self::FUNDED_MONEY) {
            return [
                'account_id' => (int) $data['funding_account_id'],
                'party_type' => null,
                'party_id' => null,
            ];
        }

        $code = match ($how) {
            self::FUNDED_CAPITAL => StandardChart::OWNER_CAPITAL,
            self::FUNDED_CREDIT => StandardChart::VENDOR_PAYABLE,
            default => StandardChart::RETAINED_EARNINGS,
        };

        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages([
                'funded_by' => __('accounts::asset.funding_account_missing', ['code' => $code]),
            ]);
        }

        return [
            'account_id' => (int) $account->id,
            'party_type' => match ($how) {
                self::FUNDED_CAPITAL => 'person',
                self::FUNDED_CREDIT => 'supplier',
                default => null,
            },
            'party_id' => match ($how) {
                self::FUNDED_CAPITAL => (int) $data['funding_person_id'],
                self::FUNDED_CREDIT => (int) $data['funding_supplier_id'],
                default => null,
            },
        ];
    }

    /**
     * একটা মাসের ক্ষয় কত।
     *
     * সরলরৈখিক: (দাম − বাতিল মূল্য) ÷ আয়ুর মাস। প্রতি মাসে একই অঙ্ক।
     *
     * ক্রমহ্রাসমান: খাতায় এখনকার দামের উপর বাৎসরিক হার ÷ ১২। প্রথম
     * বছরগুলোয় বেশি, পরে কম — যা যানবাহনের বাস্তবতার কাছাকাছি।
     *
     * দুইটাতেই শেষে একটা ছাঁকনি: বাকি থাকা ক্ষয়ের চেয়ে বেশি বসে না।
     * নাহলে ক্রমহ্রাসমানে দাম কোনোদিন বাতিল মূল্যে থামত না, আর
     * সরলরৈখিকে শেষ মাসে এক-দুই পয়সা বেশি বসে যেত।
     */
    public function monthlyAmount(FixedAsset $asset, ?Carbon $upTo = null): string
    {
        $left = $asset->depreciableLeft($upTo);

        if (bccomp($left, '0', 4) <= 0) {
            return '0.0000';
        }

        $amount = $asset->method === FixedAsset::REDUCING
            ? bcdiv(bcmul($asset->bookValue($upTo), bcdiv((string) $asset->rate, '100', 8), 8), '12', 4)
            : bcdiv(bcsub((string) $asset->cost, (string) $asset->salvage, 4), (string) $asset->life_months, 4);

        return bccomp($amount, $left, 4) > 0 ? $left : $amount;
    }

    /**
     * এক সম্পদের এক মাসের অবচয় বসানো।
     *
     * @throws ValidationException
     */
    public function depreciate(FixedAsset $asset, Carbon|string $month): ?DepreciationEntry
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();

        if (! $asset->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::asset.not_active'),
            ]);
        }

        /*
         * কেনার আগের মাসে ক্ষয় হয় না।
         *
         * না আটকালে কেউ একবার পুরনো মাস ধরে চালালে ভ্যানটা কেনার আগেই
         * ক্ষয়ে যাওয়া শুরু করত — আর সংখ্যাটা দেখতে বৈধই লাগত।
         */
        if ($periodEnd->lessThan($asset->acquired_on->copy()->endOfMonth()->startOfDay())) {
            throw ValidationException::withMessages([
                'period_end' => __('accounts::asset.before_acquisition'),
            ]);
        }

        $amount = Money::of($this->monthlyAmount($asset, $periodEnd));

        if (bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($asset, $periodEnd, $amount) {
            /*
             * একই মাস দুইবার আটকায় ডাটাবেসের unique — কোডের if নয়।
             *
             * কোডে দেখলে দুইজন একসাথে চালালে দুইটাই "নেই" দেখে দুইটাই
             * বসিয়ে দিত। মাস শেষে সবাই একসাথে কাজ করেন, তাই এটা
             * তাত্ত্বিক ঝুঁকি নয়।
             */
            $entry = DepreciationEntry::create([
                'company_id' => $asset->company_id,
                'fixed_asset_id' => $asset->id,
                'period_end' => $periodEnd->toDateString(),
                'amount' => $amount,
                'document_no' => $asset->document_no.'/'.$periodEnd->format('Y-m'),
                'created_by' => auth()->id(),
            ]);

            /* খরচ বাড়ল, আর সঞ্চিত ক্ষয় বাড়ল — সম্পদের খাত ছোঁয়া হয় না। */
            $this->posting->post(
                sourceType: DepreciationEntry::drillSourceType(),
                sourceId: $entry->id,
                trxDate: $periodEnd->toDateString(),
                lines: [
                    ['account_id' => $asset->expense_account_id, 'debit' => $amount],
                    ['account_id' => $asset->accumulated_account_id, 'credit' => $amount],
                ],
                documentNo: $entry->document_no,
            );

            return $entry;
        });
    }

    /**
     * মাস শেষের দৌড় — সব সচল সম্পদে একবারে।
     *
     * যেগুলো ইতিমধ্যে বসানো, বা যেগুলোর ক্ষয় শেষ, সেগুলো নীরবে বাদ
     * যায়। দৌড়টা পুরো ব্যর্থ হয় না — একটা সম্পদের সমস্যায় বাকি
     * চল্লিশটা আটকে গেলে কেউ আর মাস শেষে দৌড়ায় না।
     *
     * @return array{posted: int, skipped: int, total: string}
     */
    public function runFor(Carbon|string $month): array
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();
        $posted = 0;
        $skipped = 0;
        $total = '0';

        foreach (FixedAsset::query()->active()->get() as $asset) {
            $already = DepreciationEntry::query()
                ->where('fixed_asset_id', $asset->id)
                ->whereDate('period_end', $periodEnd->toDateString())
                ->exists();

            if ($already) {
                $skipped++;

                continue;
            }

            try {
                $entry = $this->depreciate($asset, $periodEnd);
            } catch (ValidationException) {
                $skipped++;

                continue;
            }

            if ($entry === null) {
                $skipped++;

                continue;
            }

            $posted++;
            $total = bcadd($total, (string) $entry->amount, 4);
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'total' => $total];
    }

    /**
     * সম্পদ বিদায় — বিক্রি, বা ফেলে দেওয়া।
     *
     * ── কেন এখানে লাভ-লোকসান বেরোয় ─────────────────────────────────
     * জিনিসটা খাতায় যত দামে বসে আছে (দাম − সঞ্চিত ক্ষয়), আর যত টাকায়
     * গেল — এই দুইটার তফাতই লাভ বা লোকসান। তফাতটা না বসালে কেনার দাম
     * আর ক্ষয় দুইটাই খাতায় ঝুলে থাকত, আর ব্যালেন্স শিটে এমন একটা
     * ভ্যান দেখাত যা ছয় মাস আগে বিক্রি হয়ে গেছে।
     */
    public function dispose(
        FixedAsset $asset,
        string $amount,
        int $intoAccountId,
        Carbon|string|null $date = null,
    ): FixedAsset {
        if (! $asset->isActive()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::asset.not_active'),
            ]);
        }

        $on = Carbon::parse($date ?? now())->startOfDay();
        $proceeds = Money::of($amount);
        $book = $asset->bookValue();
        $accumulated = $asset->accumulated();

        return DB::transaction(function () use ($asset, $on, $proceeds, $book, $accumulated, $intoAccountId) {
            $lines = [];

            if (bccomp($proceeds, '0', 4) > 0) {
                $lines[] = ['account_id' => $intoAccountId, 'debit' => $proceeds];
            }

            /* সঞ্চিত ক্ষয়টা ডেবিট করে মুছে ফেলা হয় — ওটা ক্রেডিট প্রকৃতির। */
            if (bccomp($accumulated, '0', 4) > 0) {
                $lines[] = ['account_id' => $asset->accumulated_account_id, 'debit' => $accumulated];
            }

            /* সম্পদের খাত থেকে পুরো কেনা দামটা বেরিয়ে যায়। */
            $lines[] = ['account_id' => $asset->asset_account_id, 'credit' => (string) $asset->cost];

            $difference = bcsub($proceeds, $book, 4);

            if (bccomp($difference, '0', 4) !== 0) {
                /*
                 * লাভ হলে ক্রেডিট, লোকসান হলে ডেবিট — দুইটাই খরচের
                 * খাতে, কারণ অবচয়ের খাতেই এই তফাতটা মানানসই: দুইটাই
                 * বলে "ক্ষয়ের হিসাবটা কতটা ঠিক ছিল"।
                 */
                $lines[] = bccomp($difference, '0', 4) > 0
                    ? ['account_id' => $asset->expense_account_id, 'credit' => $difference]
                    : ['account_id' => $asset->expense_account_id, 'debit' => bcmul($difference, '-1', 4)];
            }

            /*
             * ⚠️ নিবন্ধনের চাবি নয়, বিদায়ের নিজস্ব চাবি
             * ([[FixedAsset::disposalSourceType]])। ⛔ একই চাবি দিলে যে সম্পদের
             * টাকার উৎস লেখা আছে তার বিদায় পোস্টিং ইঞ্জিন আটকে দেয়,
             * আর গোটা লেনদেন ফিরে যায় — বিক্রির টাকাও খাতায় বসে না।
             */
            $this->posting->post(
                sourceType: FixedAsset::disposalSourceType(),
                sourceId: $asset->id,
                trxDate: $on->toDateString(),
                lines: $lines,
                documentNo: $asset->document_no.'/OUT',
            );

            $asset->update([
                'status' => FixedAsset::DISPOSED,
                'disposed_on' => $on->toDateString(),
                'disposal_amount' => $proceeds,
            ]);

            return $asset->refresh();
        });
    }
}
