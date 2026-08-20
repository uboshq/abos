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
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * খাতায় একটা সম্পদ তোলা।
     *
     * ── কেনার দাখিলা এখানে বসে না ───────────────────────────────────
     * জিনিসটা কেনা হয়েছে একটা ক্রয় বা পেমেন্ট ভাউচার দিয়ে, আর ওখানেই
     * টাকাটা সম্পদের খাতে ডেবিট হয়েছে। এখানে আবার বসালে একই কেনা
     * দুইবার খাতায় উঠত।
     *
     * এই খাতাটার কাজ আলাদা: জিনিসটা কী, কত আয়ু, কোন পদ্ধতিতে ক্ষয়
     * ধরা হবে — অর্থাৎ অবচয় চালানোর জন্য যা যা জানা দরকার।
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

        return FixedAsset::create([
            ...$data,
            'company_id' => CompanyContext::id(),
            'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
            'document_no' => $this->numbers->next('FA'),
            'method' => $method,
            'status' => FixedAsset::ACTIVE,
            'created_by' => auth()->id(),
        ]);
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

            $this->posting->post(
                sourceType: FixedAsset::drillSourceType(),
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
