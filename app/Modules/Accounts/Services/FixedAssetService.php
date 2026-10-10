<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\CapitalisesABillLine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\AssetCostPart;
use App\Modules\Accounts\Models\AssetEstimateChange;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\AssetTransfer;
use App\Modules\Accounts\Models\AssetUsage;
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

    /**
     * ⭐ পাকা ক্রয় বিলের সারি থেকে — মালটা মজুদ থেকে সম্পদে সরে, কেনাটা আবার বসে না (ধাপ ১; [[CapitalisesABillLine]])।
     */
    public const FUNDED_BILL = 'bill';

    /** ⭐ মূলধনীকরণের সীমা — এর নিচের কেনা খরচ, সম্পদ নয় (মালিকের সেটিং; ধাপ ১) */
    public const THRESHOLD = 'accounts.asset.capitalisation_threshold';

    /** @var list<string> */
    public const FUNDING_WAYS = [
        self::FUNDED_CAPITAL,
        self::FUNDED_MONEY,
        self::FUNDED_CREDIT,
        self::FUNDED_OPENING,
        self::FUNDED_ALREADY,
        self::FUNDED_BILL,
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
        $data = $this->withCategory($data);
        $parts = $this->costParts($data);
        $bill = $this->billLine($data);

        if ($parts !== [] && $bill === null) {
            $data['cost'] = array_reduce($parts, fn (string $sum, array $p) => bcadd($sum, $p['amount'], 4), '0');
        }

        $method = $data['method'] ?? FixedAsset::STRAIGHT_LINE;

        if (! in_array($method, FixedAsset::METHODS, true)) {
            throw ValidationException::withMessages(['method' => __('accounts::asset.method_unknown')]);
        }

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

        // ⭐ ব্যবহারের এককে — মোট একক লাগে (ধাপ ২)
        if ($method === FixedAsset::UNITS && bccomp((string) ($data['total_units'] ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'total_units' => __('accounts::asset.units_required'),
            ]);
        }

        // ⓘ শেষ দাম না দিলে শ্রেণির হার ধরে — দামের শতকরা (ধাপ ১)
        if (blank($data['salvage'] ?? null) && isset($data['residual_percent'])) {
            $data['salvage'] = bcdiv(bcmul((string) $data['cost'], (string) $data['residual_percent'], 8), '100', 4);
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

        $this->assertAboveThreshold((string) $data['cost'], $data['category_id'] ?? null);
        $this->assertTheRestIsOurs($data);

        $funding = $bill === null ? $this->fundingFrom($data) : null;

        /* ⓘ এ পর্যন্ত যতটা ক্ষয় ধরা হয়েছে — সিদ্ধান্তের ঘর, কাগজের কলাম নয় */
        $openingDepreciation = (string) ($data['opening_accumulated'] ?? '0');

        /*
         * ⭐ কোন সই — পুরনো খাতার জের তোলার নিজের সই ([[AccountsSignature::FIXED_ASSET_OPENING]]), বাকিগুলো নিবন্ধনের
         * (মালিক, ১০ অক্টোবর ২০২৬: আমদানির নিজের চাবি আর নিজের সই)।
         */
        $action = ($data['funded_by'] ?? null) === self::FUNDED_OPENING
            ? AccountsSignature::FIXED_ASSET_OPENING
            : AccountsSignature::FIXED_ASSET_REGISTER;

        unset(
            $data['funded_by'], $data['funding_person_id'],
            $data['funding_account_id'], $data['funding_supplier_id'],
            $data['opening_accumulated'], $data['cost_parts'], $data['residual_percent'],
        );

        /*
         * ⭐ ভুলবার্তাটা ফরমের ঘরে ফেরানো — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ পোস্টিং ইঞ্জিন অভিযোগ করে `trx_date` নামে ([[OpenPeriod::assertOpen]]),
         * আর সম্পদের ফরমে ওই নামে কোনো ঘর নেই — ঘরটার নাম `acquired_on`।
         * ⚠️ ফলে বার্তাটা পর্দায় কোথাও বসত না: ব্যবহারকারী সেভ চাপতেন,
         * পাতা ফিরে আসত, আর **কেন হলো না সেটা কোথাও লেখা থাকত না**।
         */
        try {
            return DB::transaction(function () use ($data, $method, $funding, $openingDepreciation, $parts, $bill, $action) {
                $documentNo = $this->numbers->next('FA');

                $asset = FixedAsset::create([
                    ...$data,
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                    'document_no' => $documentNo,
                    // ⓘ গায়ের ট্যাগ না দিলে কাগজের নম্বরই ট্যাগ — নম্বরের ক্রম থেকে, কখনো দুইবার নয় (ধাপ ১)
                    'tag_no' => ($data['tag_no'] ?? null) ?: $documentNo,
                    'method' => $method,
                    // ⓘ টাকার উৎস থাকলে আগে সইয়ের অপেক্ষায় — নিচে সই লাগে না দেখলে তখনই চালু
                    'status' => ($funding !== null || $bill !== null) ? FixedAsset::AWAITING : FixedAsset::ACTIVE,
                    'created_by' => auth()->id(),
                ]);

                foreach ($bill === null ? $parts : [] as $part) {
                    $asset->costParts()->create([...$part, 'company_id' => $asset->company_id]);
                }

                /*
                 * ⭐ ক্রয় বিলের সারি থেকে — সইয়ের পরে মালটা মজুদ থেকে সরে ([[finishRegistered()]]); ছক বন্ধে এখনই।
                 */
                if ($bill !== null) {
                    $signed = ['bill' => $bill];

                    if (app(AccountsSignature::class)->holds($asset, AccountsSignature::FIXED_ASSET_REGISTER,
                        (string) $asset->cost, (string) $asset->name, $signed)) {
                        return $asset;
                    }

                    return $this->finishRegistered($asset, $signed);
                }

                /*
                 * ⭐ সই — গ১, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬ ([[AccountsSignature]])।
                 *
                 * ⛔ আগে সম্পদ নিবন্ধনেই টাকার উৎস (নগদ, ব্যাংক, দেনা, ব্যক্তি) সই ছাড়া খাতায় বসত। ⓘ ছক চালু থাকলে সম্পদ
                 * "সইয়ের অপেক্ষায়" থাকে — অবচয় ধরে না, খাতায় নেই; শেষ সইয়ে [[finishRegistered()]] ঠিক এই উৎস দিয়েই
                 * দাখিলা বসায়। ছক বন্ধে (UB) আগের মতো এখনই।
                 */
                if ($funding !== null && app(AccountsSignature::class)->holds($asset, $action,
                    (string) $asset->cost, (string) $asset->name, ['funding' => $funding, 'opening_depreciation' => $openingDepreciation])) {
                    return $asset;
                }

                if ($funding !== null) {
                    $asset->forceFill(['status' => FixedAsset::ACTIVE])->save();

                    $this->posting->post(
                        sourceType: FixedAsset::drillSourceType(),
                        sourceId: $asset->id,
                        trxDate: $this->postableDate($asset->acquired_on),
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

                $this->openingDepreciation($asset, $openingDepreciation);

                return $asset;
            });
        } catch (ValidationException $e) {
            throw $this->onTheDateField($e);
        }
    }

    /**
     * ⭐ শ্রেণির ছাঁচ — যে ঘর খালি, সেখানে শ্রেণির খাত, পদ্ধতি, আয়ু আর হার (ধাপ ১)।
     *
     * ⓘ হাতে দেওয়া ঘর জেতে — শ্রেণি কেবল খালি ঘর ভরে। শেষ দাম না দিলে দামের উপর শ্রেণির হার (শতকরা), [[register()]]-এ।
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCategory(array $data): array
    {
        if (blank($data['category_id'] ?? null)) {
            unset($data['category_id']);

            return $data;
        }

        $category = AssetCategory::query()->active()->whereKey((int) $data['category_id'])->first();

        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => __('accounts::asset.category_not_found')]);
        }

        foreach (['asset_account_id', 'accumulated_account_id', 'expense_account_id', 'method', 'life_months', 'rate'] as $field) {
            if (blank($data[$field] ?? null) && $category->{$field} !== null) {
                $data[$field] = $category->{$field};
            }
        }

        $data['category_id'] = (int) $category->id;
        $data['residual_percent'] = (string) $category->residual_percent;

        return $data;
    }

    /**
     * ⭐ দামের ভাগ — কেনা দাম, আনা, বসানো, শুল্ক (IAS 16.16)। ⓘ শূন্যের ভাগ বাদ; থাকলে যোগফলই দাম।
     *
     * @param  array<string, mixed>  $data
     * @return list<array{kind: string, amount: string, note: ?string}>
     */
    private function costParts(array $data): array
    {
        $parts = [];

        foreach ((array) ($data['cost_parts'] ?? []) as $part) {
            $amount = (string) ($part['amount'] ?? '0');

            if (! is_numeric($amount) || bccomp($amount, '0', 4) === 0) {
                continue;
            }

            if (bccomp($amount, '0', 4) < 0 || ! in_array($part['kind'] ?? null, AssetCostPart::KINDS, true)) {
                throw ValidationException::withMessages(['cost_parts' => __('accounts::asset.cost_part_wrong')]);
            }

            $parts[] = ['kind' => (string) $part['kind'], 'amount' => Money::of($amount), 'note' => ($part['note'] ?? null) ?: null];
        }

        return $parts;
    }

    /**
     * ⭐ ক্রয় বিলের সারি — কোন সারি, কতটা; আগে তোলা অংশ বাদে (ধাপ ১; [[CapitalisesABillLine]])।
     *
     * ⛔ একই সারি দুইবার পুরোটা তোলা যায় না — সইয়ের অপেক্ষায় থাকা অংশও গোনা হয়, নইলে দুইজন একসাথে একই ফ্রিজ তুলতেন।
     * ⓘ দাম আপাতত বিলের দরে; সইয়ের পরে মজুদ যে দামে ছাড়ে সেটাই আসল দাম হয়ে বসে ([[finishRegistered()]])।
     *
     * @param  array<string, mixed>  $data
     * @return array{line_id: int, qty: string}|null
     */
    private function billLine(array &$data): ?array
    {
        if (($data['funded_by'] ?? null) !== self::FUNDED_BILL) {
            return null;
        }

        $line = app(CapitalisesABillLine::class)->line((int) ($data['purchase_bill_line_id'] ?? 0));
        $qty = Money::of($data['capitalised_qty'] ?? '0');

        if ($line === null) {
            throw ValidationException::withMessages(['purchase_bill_line_id' => __('accounts::asset.bill_line_missing')]);
        }

        $taken = (string) FixedAsset::acrossBranches()
            ->where('purchase_bill_line_id', $line['id'])
            ->whereNotIn('status', [FixedAsset::DISPOSED, FixedAsset::WRITTEN_OFF, FixedAsset::LOST])
            ->sum('capitalised_qty');
        $left = bcsub($line['qty'], $taken, 4);

        if (bccomp($qty, '0', 4) <= 0 || bccomp($qty, $left, 4) > 0) {
            throw ValidationException::withMessages(['capitalised_qty' => __('accounts::asset.bill_qty_over', ['left' => Money::quantity($left)])]);
        }

        $data['purchase_bill_id'] = $line['bill_id'];
        $data['purchase_bill_line_id'] = $line['id'];
        $data['capitalised_qty'] = $qty;
        $data['supplier_id'] = ($data['supplier_id'] ?? null) ?: $line['supplier_id'];
        $data['cost'] = bcmul($line['unit_cost'], $qty, 4);
        $data['branch_id'] = ($data['branch_id'] ?? null) ?: $line['branch_id'];

        return ['line_id' => $line['id'], 'qty' => $qty];
    }

    /**
     * ⭐ সীমার নিচের কেনা খরচ — সম্পদ নয় (মালিকের সেটিং; শ্রেণির নিজের সীমা থাকলে সেটা)। ⓘ শূন্য মানে কোনো সীমা নেই।
     */
    private function assertAboveThreshold(string $cost, mixed $categoryId): void
    {
        $own = $categoryId === null ? null : AssetCategory::query()->whereKey((int) $categoryId)->value('capitalisation_threshold');
        $limit = Money::of($own ?? app(SettingsService::class)->get(self::THRESHOLD, 0));

        if (bccomp($limit, '0', 4) > 0 && bccomp($cost, $limit, 4) < 0) {
            throw ValidationException::withMessages([
                'cost' => __('accounts::asset.below_threshold', ['limit' => Money::format($limit)]),
            ]);
        }
    }

    /**
     * ⛔ মূল সম্পদ, দায়িত্বের কর্মী আর বিক্রেতা — এই কোম্পানির, আর মূলটা খাতায় আছে (ধাপ ১)।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertTheRestIsOurs(array $data): void
    {
        if (filled($data['parent_id'] ?? null)) {
            $parent = FixedAsset::acrossBranches()->whereKey((int) $data['parent_id'])->first();

            if ($parent === null || ! ($parent->isInService() || $parent->isAwaiting()) || $parent->parent_id !== null) {
                throw ValidationException::withMessages(['parent_id' => __('accounts::asset.parent_wrong')]);
            }
        }

        foreach (['custodian_id' => 'employee', 'supplier_id' => 'supplier'] as $field => $type) {
            if (filled($data[$field] ?? null) && ! app(PartyRegistry::class)->exists($type, (int) $data[$field])) {
                throw ValidationException::withMessages([$field => __('accounts::asset.funding_not_found')]);
            }
        }
    }

    /**
     * তারিখের অভিযোগ হলে সেটা ফরমের ঘরে বসায়।
     *
     * ⓘ অন্য সব ভুল অবিকল থাকে — কেবল `trx_date` নামটা বদলায়,
     * কারণ এই পর্দায় তারিখের ঘরটার নাম আলাদা।
     */
    /**
     * শেষ সইয়ের পরে — সই চাওয়ার মুহূর্তের উৎস দিয়ে দাখিলা, তারপর চালু ([[FinishTheAccountsPaperOnTheLastSignature]])।
     *
     * ⓘ সারিতে তালা দিয়ে, অপেক্ষায় থাকলেই — একই সই দুইবার ঘটনা পাঠালে দ্বিতীয়বার কিছু হয় না।
     *
     * @param  array{funding?: array{account_id: int, party_type: ?string, party_id: ?int}, opening_depreciation?: string}  $signed
     */
    public function finishRegistered(FixedAsset $asset, array $signed): FixedAsset
    {
        return DB::transaction(function () use ($asset, $signed) {
            $locked = FixedAsset::acrossBranches()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $funding = $signed['funding'] ?? null;

            /*
             * ⭐ ক্রয় বিলের সারি — মালটা মজুদ থেকে সম্পদে, মজুদের নিজের দাখিলায় আর মজুদের দামে; সেটাই সম্পদের দাম
             * (ধাপ ১; [[CapitalisesABillLine]])। ⛔ বিক্রেতার পাওনা নড়ে না — কেনাটা বিলের দিনই খাতায় উঠেছে।
             */
            if ($locked->isAwaiting() && is_array($signed['bill'] ?? null)) {
                $moved = app(CapitalisesABillLine::class)->capitalise(
                    (int) $signed['bill']['line_id'],
                    (string) $signed['bill']['qty'],
                    (int) $locked->asset_account_id,
                    (int) $locked->id,
                    (string) $locked->document_no,
                    Carbon::parse($this->postableDate($locked->acquired_on)),
                    $locked->name.' — '.$locked->document_no,
                );

                $locked->forceFill(['status' => FixedAsset::ACTIVE, 'cost' => $moved])->save();

                return $locked->refresh();
            }

            if (! $locked->isAwaiting() || ! is_array($funding)) {
                return $locked;
            }

            $locked->forceFill(['status' => FixedAsset::ACTIVE])->save();

            $this->posting->post(
                sourceType: FixedAsset::drillSourceType(),
                sourceId: $locked->id,
                trxDate: $this->postableDate($locked->acquired_on),
                lines: [
                    ['account_id' => (int) $locked->asset_account_id, 'debit' => (string) $locked->cost, 'narration' => $locked->name],
                    ['account_id' => (int) $funding['account_id'], 'credit' => (string) $locked->cost,
                        'party_type' => $funding['party_type'] ?? null, 'party_id' => $funding['party_id'] ?? null, 'narration' => $locked->name],
                ],
                documentNo: $locked->document_no,
            );

            $this->openingDepreciation($locked, (string) ($signed['opening_depreciation'] ?? '0'));

            return $locked->refresh();
        });
    }

    private function onTheDateField(ValidationException $e): ValidationException
    {
        $errors = $e->errors();

        if (! isset($errors['trx_date'])) {
            return $e;
        }

        $errors['acquired_on'] = $errors['trx_date'];
        unset($errors['trx_date']);

        return ValidationException::withMessages($errors);
    }

    /**
     * দাখিলার তারিখ — পুরনো হলে চলতি বছরের প্রথম দিনে।
     *
     * ── ⛔ কী ভাঙা ছিল, ২০ সেপ্টেম্বর ২০২৬ ─────────────────
     * তিন বছরের পুরনো একটা ভ্যান তুলতে গেলে কেনার তারিখে দাখিলা
     * বসানোর চেষ্টা হত। ⚠️ ওই তারিখ কোনো চালু অর্থবছরে পড়ত না, তাই
     * পোস্টিং ইঞ্জিন ঠিকই আটকাত — আর লেনদেন ফিরে যাওয়ায়
     * **সম্পদের সারিটাই তৈরি হত না**। ⛔ ফল: ফরম ভরে সেভ চাপেন,
     * আর কিছুই থাকে না।
     *
     * ⓘ নিয়মটা [[OpeningBalanceService::dateFor]]-এর হুবহু এক: খাতা যেদিন
     * শুরু, তার আগের কোনো দিনে দাখিলা বসানোর মানে হয় না। ⭐ আসল
     * কেনার তারিখ হারায় না — সেটা `acquired_on` ঘরেই থাকে।
     */
    private function postableDate(Carbon $date): string
    {
        $year = FinancialYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return $date->toDateString();
        }

        $start = Carbon::parse($year->starts_on);

        return $date->lt($start) ? $start->toDateString() : $date->toDateString();
    }

    /**
     * এ পর্যন্ত যতটা ক্ষয় ধরা হয়ে গেছে — ব্যবস্থায় তোলার আগেই।
     *
     * ── ⭐ কেন লাগল, ২০ সেপ্টেম্বর ২০২৬ ────────────────────
     * তিন বছর চলা একটা ভ্যান নতুন হিসাবে ঢুকত: খাতায় তার দাম পুরো
     * দেখাত, আর অবচয় শুরু হত আজ থেকে — অর্থাৎ তিন বছরের ক্ষয়
     * একবারে মুছে যেত। ⚠️ স্থিতিপত্রে সম্পদটা ফুলে থাকত, আর পরের
     * বছরগুলোয় খরচ বেশি দেখাত।
     *
     * ── ⓘ কেন একটা অবচয়ের সারি, আলাদা কলাম নয় ─────────────
     * ⭐ [[FixedAsset::accumulated]] অবচয়ের সারিগুলোই যোগ করে। সারি হলে
     * খাতার মান, বইয়ের দাম আর মাসের দৌড় — তিনটাই নিজে থেকে ঠিক
     * জায়গা থেকে শুরু করে। ⛔ আলাদা কলাম হলে তিন জায়গায় তিনটা যোগ
     * লিখতে হত, আর একদিন একটায় সংশোধন হত বাকি দুইটায় নয়।
     *
     * ⚠️ খরচের খাতে যায় না — যায় সঞ্চিত মুনাফায়। ওই ক্ষয় আগের
     * বছরগুলোর, এই বছরের খরচ নয় — খরচে ফেললে প্রথম মাসেই তিন
     * বছরের অবচয় লাভ খেয়ে ফেলত।
     */
    private function openingDepreciation(FixedAsset $asset, string $amount): void
    {
        if (bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($equity === null) {
            throw ValidationException::withMessages([
                'opening_accumulated' => __('accounts::asset.opening_needs_the_chart'),
            ]);
        }

        $on = $this->postableDate($asset->acquired_on);

        $entry = DepreciationEntry::create([
            'company_id' => $asset->company_id,
            'fixed_asset_id' => $asset->id,
            'period_end' => $on,
            'amount' => $amount,
            'document_no' => $asset->document_no.'/OPEN',
            'created_by' => auth()->id(),
        ]);

        $this->posting->post(
            sourceType: DepreciationEntry::drillSourceType(),
            sourceId: $entry->id,
            trxDate: $on,
            lines: [
                ['account_id' => (int) $equity->id, 'debit' => $amount],
                ['account_id' => (int) $asset->accumulated_account_id, 'credit' => $amount],
            ],
            documentNo: $entry->document_no,
        );
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
    /** @param  array<string, mixed>  $data */
    private function assertFunderIsOurs(string $how, array $data): void
    {
        [$field, $ours] = match ($how) {
            // ⓘ খাতের কোম্পানি-স্কোপই অন্য কোম্পানির খাত বাদ দেয় ([[BelongsToCompany]])
            self::FUNDED_MONEY => ['funding_account_id', Account::query()->postable()
                ->whereKey((int) ($data['funding_account_id'] ?? 0))
                ->exists()],
            self::FUNDED_CAPITAL => ['funding_person_id',
                app(PartyRegistry::class)->exists('person', (int) ($data['funding_person_id'] ?? 0))],
            self::FUNDED_CREDIT => ['funding_supplier_id',
                app(PartyRegistry::class)->exists('supplier', (int) ($data['funding_supplier_id'] ?? 0))],
            default => [null, true],
        };

        if (! $ours) {
            throw ValidationException::withMessages([
                $field => __('accounts::asset.funding_not_found'),
            ]);
        }
    }

    public function fundingFrom(array $data): ?array
    {
        $how = (string) ($data['funded_by'] ?? self::FUNDED_ALREADY);

        if ($how === self::FUNDED_ALREADY) {
            return null;
        }

        /*
         * ⛔ অর্থদাতা এই কোম্পানির — চূড়ান্ত অডিট ⛔৯, ৩০ সেপ্টেম্বর ২০২৬
         * ([[ANoteNamedAPartyFromAnotherCompanyTest]])। ⓘ আগে id-টা কোথাও না কোথাও থাকলেই চলত,
         * তাই অন্য কোম্পানির বিক্রেতার কাছে দেনা বা অন্য কোম্পানির খাত থেকে টাকা বসত।
         */
        $this->assertFunderIsOurs($how, $data);

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
     * ⭐ পরের মাসে কত বসবে — ইঞ্জিন থেকে ([[DepreciationEngine::amountFor()]]; স্থায়ী সম্পদ ধাপ ২)।
     *
     * ⓘ মাস না দিলে পরের বাকি মাস: শেষ বসা মাসের পরেরটা, নইলে ক্ষয় শুরুর মাস।
     */
    public function monthlyAmount(FixedAsset $asset, ?Carbon $upTo = null): string
    {
        if ($upTo === null) {
            $last = $asset->depreciation()->max('period_end');
            $upTo = $last === null ? $asset->depreciatesFrom() : Carbon::parse($last)->addMonthNoOverflow();
        }

        return app(DepreciationEngine::class)->amountFor($asset, $upTo)['amount'];
    }

    /**
     * এক সম্পদের এক মাসের অবচয় বসানো — নিজের দাখিলায় (মাসের দৌড় শাখায় একটা কাগজে বসায়, [[DepreciationEngine::run()]])।
     *
     * @throws ValidationException
     */
    public function depreciate(FixedAsset $asset, Carbon|string $month): ?DepreciationEntry
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();

        // ⓘ অলস আর মেরামতে থাকা জিনিসও ক্ষয় ধরে — IAS 16.55 (ধাপ ১; [[FixedAsset::IN_SERVICE]])
        if (! $asset->isInService()) {
            throw ValidationException::withMessages([
                // ⓘ সইয়ের অপেক্ষা "আর ব্যবহারে নেই" নয় — আলাদা কথা (গ১)
                'status' => $asset->isAwaiting() ? __('accounts::asset.awaiting_signature') : __('accounts::asset.not_active'),
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

        // ⓘ একই মাস দুইবার — প্রশ্নটা ডাটাবেজের অনন্য সারি তোলে, তাই ইঞ্জিনকে "আগে বসেছে কি না" জিজ্ঞেস করা হয় না
        $amount = app(DepreciationEngine::class)->amountFor($asset, $periodEnd, once: false)['amount'];

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
     * মাস শেষের দৌড় — সব খাতায় থাকা সম্পদে একবারে, শাখায় একটা কাগজ ([[DepreciationEngine::run()]]; ধাপ ২)।
     *
     * ⓘ যেগুলো ইতিমধ্যে বসানো, বা যেগুলোর ক্ষয় শেষ, সেগুলো নীরবে বাদ যায়। দুইবার চালালে দ্বিতীয়বার কিছু বসে না;
     * বন্ধ মাসে থামে।
     *
     * @return array{posted: int, skipped: int, total: string, runs: list<int>}
     */
    public function runFor(Carbon|string $month): array
    {
        return app(DepreciationEngine::class)->run($month);
    }

    /**
     * ⭐ আয়ু, শেষ দাম, পদ্ধতি, হার বা মোট একক বদল — আগামীর দিকে (IAS 16.51, IAS 8.36; স্থায়ী সম্পদ ধাপ ২)।
     *
     * ⓘ আগে বসা অবচয় ছোঁয়া হয় না; পরের মাস থেকে বাকি দাম বাকি আয়ুতে ভাগ হয় ([[DepreciationEngine]])। আগে কী ছিল,
     * কী হলো, কেন — ইতিহাসে থাকে ([[AssetEstimateChange]]), আর সম্পদের নিজের নিরীক্ষাতেও।
     *
     * @param  array{life_months?: int|null, salvage?: string|null, method?: string|null, rate?: string|null, total_units?: string|null}  $data
     */
    public function changeEstimate(FixedAsset $asset, array $data, string $reason): FixedAsset
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('accounts::asset.estimate_reason_required')]);
        }

        return DB::transaction(function () use ($asset, $data, $reason) {
            $locked = FixedAsset::acrossBranches()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isInService()) {
                throw ValidationException::withMessages(['status' => __('accounts::asset.not_active')]);
            }

            $fields = ['life_months', 'salvage', 'method', 'rate', 'total_units'];
            $before = collect($fields)->mapWithKeys(fn (string $f) => [$f => $locked->{$f} === null ? null : (string) $locked->{$f}])->all();
            $after = $before;

            foreach ($fields as $field) {
                if (array_key_exists($field, $data) && filled($data[$field])) {
                    $after[$field] = (string) $data[$field];
                }
            }

            $method = (string) $after['method'];

            if (! in_array($method, FixedAsset::METHODS, true)) {
                throw ValidationException::withMessages(['method' => __('accounts::asset.method_unknown')]);
            }

            if ($method === FixedAsset::STRAIGHT_LINE && (int) $after['life_months'] <= 0) {
                throw ValidationException::withMessages(['life_months' => __('accounts::asset.life_required')]);
            }

            if ($method === FixedAsset::REDUCING && bccomp((string) $after['rate'], '0', 4) <= 0) {
                throw ValidationException::withMessages(['rate' => __('accounts::asset.rate_required')]);
            }

            if ($method === FixedAsset::UNITS && bccomp((string) $after['total_units'], '0', 4) <= 0) {
                throw ValidationException::withMessages(['total_units' => __('accounts::asset.units_required')]);
            }

            if (bccomp((string) $after['salvage'], (string) $locked->cost, 4) > 0) {
                throw ValidationException::withMessages(['salvage' => __('accounts::asset.salvage_over_cost')]);
            }

            if ($after === $before) {
                throw ValidationException::withMessages(['reason' => __('accounts::asset.estimate_nothing_changed')]);
            }

            AssetEstimateChange::query()->create([
                'company_id' => $locked->company_id,
                'fixed_asset_id' => $locked->id,
                'changed_on' => now()->toDateString(),
                'before' => $before,
                'after' => $after,
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            $locked->update([
                'life_months' => $after['life_months'] === null ? null : (int) $after['life_months'],
                'salvage' => $after['salvage'] ?? '0',
                'method' => $method,
                'rate' => $after['rate'],
                'total_units' => $after['total_units'],
            ]);

            return $locked->refresh();
        });
    }

    /**
     * ⭐ এক মাসে কত একক চলল — ব্যবহারের এককে ক্ষয়ের জন্য (স্থায়ী সম্পদ ধাপ ২)।
     *
     * ⛔ যে মাসের অবচয় বসে গেছে, তার একক আর বদলায় না — নইলে খাতার অঙ্ক আর এককের হিসাব আলাদা হত।
     */
    public function recordUsage(FixedAsset $asset, Carbon|string $month, string $units, ?string $note = null): AssetUsage
    {
        $periodEnd = Carbon::parse($month)->endOfMonth()->startOfDay();

        if ($asset->method !== FixedAsset::UNITS) {
            throw ValidationException::withMessages(['units' => __('accounts::asset.units_not_this_method')]);
        }

        if (! is_numeric($units) || bccomp($units, '0', 4) < 0) {
            throw ValidationException::withMessages(['units' => __('accounts::asset.units_wrong')]);
        }

        if ($asset->depreciation()->where('period_end', $periodEnd->toDateString())->exists()) {
            throw ValidationException::withMessages(['month' => __('accounts::asset.units_month_posted')]);
        }

        return AssetUsage::query()->updateOrCreate(
            ['fixed_asset_id' => $asset->id, 'period_end' => $periodEnd->toDateString()],
            ['company_id' => $asset->company_id, 'units' => Money::of($units), 'note' => $note, 'created_by' => auth()->id()],
        );
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
    /**
     * ⭐ সম্পদ এক শাখা থেকে আরেক শাখায় — মানচিত্র §১৫, ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⚠️ কেন খাতায় দাখিলা লাগে, কেবল কলাম বদলানো নয় ─────────────
     * ফ্রিজটা ঢাকা থেকে খুলনায় গেলে **দুইটা শাখার স্থিতিপত্রই** বদলায়:
     * একটা থেকে সম্পদ যায়, অন্যটায় আসে। ⓘ কেবল `branch_id` বদলালে
     * খতিয়ানের পুরনো সারিগুলো ঢাকার নামেই পড়ে থাকত, আর শাখা ধরে
     * স্থিতিপত্র চাইলে দুইটাই ভুল আসত।
     *
     * ⛔ সঞ্চিত অবচয়টাও সাথে যায়, আর সেটা ভুলে যাওয়া সহজ: সম্পদের খাত
     * সরিয়ে ক্ষয়ের খাত রেখে দিলে নতুন শাখায় জিনিসটা **নতুনের দামে**
     * বসত, আর পুরনো শাখায় একটা ক্ষয় ঝুলে থাকত যার কোনো সম্পদ নেই।
     *
     * ⓘ মোট অঙ্ক শূন্য — এটা টাকার চলাচল নয়, জায়গা বদল। ⚠️ তবু দাখিলা
     * দুই দিকেই বসে, কারণ শাখাটা সারির নিজের ঘরে থাকে।
     */
    /**
     * ⭐ ধাপ ৩: কর্মী, জায়গা আর বিভাগের বদলও স্থানান্তর। ⓘ শাখা না বদলালে খাতায় কিছু বসে না — কেবল ইতিহাসের সারি।
     *
     * @param  array{custodian_id?: ?int, location?: ?string, department?: ?string}  $custody  যে ঘর দেওয়া হয়, সেটাই বদলায়
     */
    public function transfer(
        FixedAsset $asset,
        ?int $toBranchId,
        Carbon|string|null $date = null,
        ?string $note = null,
        array $custody = [],
    ): AssetTransfer {
        if (! $asset->isInService()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::asset.not_active'),
            ]);
        }

        $from = $asset->branch_id === null ? null : (int) $asset->branch_id;
        $toBranchId ??= $from;
        $branchMoves = $toBranchId !== null && $toBranchId !== $from;

        $after = [
            'custodian_id' => array_key_exists('custodian_id', $custody) ? ($custody['custodian_id'] ?: null) : $asset->custodian_id,
            'location' => array_key_exists('location', $custody) ? (($custody['location'] ?? '') ?: null) : $asset->location,
            'department' => array_key_exists('department', $custody) ? (($custody['department'] ?? '') ?: null) : $asset->department,
        ];
        $custodyMoves = (int) $after['custodian_id'] !== (int) $asset->custodian_id
            || (string) $after['location'] !== (string) $asset->location
            || (string) $after['department'] !== (string) $asset->department;

        if (! $branchMoves && ! $custodyMoves) {
            throw ValidationException::withMessages([
                'to_branch_id' => __('accounts::asset.already_there'),
            ]);
        }

        // ⓘ শাখাহীন সম্পদের কেবল জায়গা বদলেও একটা শাখা লাগে — ইতিহাসের সারিতে "কোথায়" খালি রাখা যায় না
        if ($toBranchId === null) {
            throw ValidationException::withMessages(['to_branch_id' => __('accounts::asset.branch_needed')]);
        }

        if ($after['custodian_id'] !== null && ! app(PartyRegistry::class)->exists('employee', (int) $after['custodian_id'])) {
            throw ValidationException::withMessages(['custodian_id' => __('accounts::asset.funding_not_found')]);
        }

        $on = Carbon::parse($date ?? now())->startOfDay();
        $accumulated = $asset->accumulated();

        return DB::transaction(function () use ($asset, $toBranchId, $on, $from, $accumulated, $note, $branchMoves, $after) {
            $move = AssetTransfer::query()->create([
                'company_id' => CompanyContext::id(),
                'asset_id' => $asset->id,
                'from_branch_id' => $from,
                'to_branch_id' => $toBranchId,
                'from_custodian_id' => $asset->custodian_id,
                'to_custodian_id' => $after['custodian_id'],
                'from_location' => $asset->location,
                'to_location' => $after['location'],
                'from_department' => $asset->department,
                'to_department' => $after['department'],
                'moved_on' => $on->toDateString(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            $asset->update($after);

            // ⓘ একই শাখার ভেতরে সরানো টাকার ঘটনা নয় — দাখিলা নেই
            if (! $branchMoves) {
                return $move->refresh();
            }

            /*
             * ⓘ কেনা দামটা পুরনো শাখা থেকে নতুন শাখায়।
             * ⚠️ প্রতিটা সারিতে নিজের `branch_id` — এটাই গোটা দাখিলার
             * একমাত্র কারণ ([[PostingEngine]] সারি-প্রতি শাখা মানে)।
             */
            $lines = [
                ['account_id' => $asset->asset_account_id, 'credit' => (string) $asset->cost, 'branch_id' => $from],
                ['account_id' => $asset->asset_account_id, 'debit' => (string) $asset->cost, 'branch_id' => $toBranchId],
            ];

            if (bccomp($accumulated, '0', 4) > 0) {
                /* ⛔ ক্ষয়টাও সাথে যায় — নাহলে নতুন শাখায় জিনিসটা নতুন দেখাত */
                $lines[] = ['account_id' => $asset->accumulated_account_id, 'debit' => $accumulated, 'branch_id' => $from];
                $lines[] = ['account_id' => $asset->accumulated_account_id, 'credit' => $accumulated, 'branch_id' => $toBranchId];
            }

            /*
             * ⚠️ চাবিটা **স্থানান্তরের নিজের সারির** আইডিতে, সম্পদের নয়।
             * ⛔ সম্পদের আইডি দিলে দ্বিতীয় স্থানান্তরটা নীরবে আটকে যেত —
             * ঠিক যে ফাঁদে বিদায় পড়েছিল ([[FixedAsset::disposalSourceType]])।
             */
            $this->posting->post(
                sourceType: AssetTransfer::drillSourceType(),
                sourceId: $move->id,
                trxDate: $on->toDateString(),
                lines: $lines,
                documentNo: $asset->document_no.'/MOVE',
            );

            $asset->update(['branch_id' => $toBranchId]);

            return $move->refresh();
        });
    }

    /**
     * ⭐ অবস্থা বদল — ব্যবহারে, অলস, মেরামতে (ধাপ ১)। ⓘ তিনটাই খাতায় থাকে আর ক্ষয় ধরে (IAS 16.55), তাই টাকা নড়ে না।
     * ⛔ খাতায় নেই এমন (সইয়ের অপেক্ষা, বিদায়, বাতিল, হারানো) সম্পদের অবস্থা এখান দিয়ে বদলায় না — সারিতে তালা দিয়ে দেখা।
     */
    public function changeStatus(FixedAsset $asset, string $status): FixedAsset
    {
        if (! in_array($status, FixedAsset::SWITCHABLE, true)) {
            throw ValidationException::withMessages(['status' => __('accounts::asset.status_not_switchable')]);
        }

        return DB::transaction(function () use ($asset, $status) {
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isInService()) {
                throw ValidationException::withMessages(['status' => __('accounts::asset.not_active')]);
            }

            $locked->update(['status' => $status]);

            return $locked->refresh();
        });
    }

    /**
     * ⭐ খাতা থেকে বিদায় — বিক্রি, বাতিল (ভাঙারি) বা হারানো/চুরি (স্থায়ী সম্পদ ধাপ ৩; IAS 16.67-72)।
     *
     * ⓘ তিনটাই একই দাখিলা: সঞ্চিত ক্ষয় মোছা, কেনা দাম বের করা, পাওয়া টাকা (বিক্রির দাম, ভাঙারির দাম, বিমার দাবি) ঢোকা,
     * পার্থক্য লাভ বা লোকসান। কেবল শেষ অবস্থা আলাদা। লাভ-লোকসান শ্রেণির নিজের খাতে, শ্রেণি না থাকলে ছকের খাতে।
     */
    public function dispose(
        FixedAsset $asset,
        string $amount,
        ?int $intoAccountId,
        Carbon|string|null $date = null,
        string $as = FixedAsset::DISPOSED,
        ?string $reason = null,
    ): FixedAsset {
        if (! $asset->isInService()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::asset.not_active'),
            ]);
        }

        if (! in_array($as, FixedAsset::LEAVING, true)) {
            throw ValidationException::withMessages(['as' => __('accounts::asset.leaving_unknown')]);
        }

        $on = Carbon::parse($date ?? now())->startOfDay();
        $proceeds = Money::of($amount);

        // ⛔ টাকা পাওয়া গেলে তা কোন খাতে ঢুকল বলতেই হবে
        if (bccomp($proceeds, '0', 4) > 0 && ! Account::query()->postable()->whereKey((int) $intoAccountId)->exists()) {
            throw ValidationException::withMessages(['into_account_id' => __('accounts::asset.into_account_required')]);
        }

        // ⛔ ঝুলন্ত ঘটনা থাকলে বিদায় নয় — ঘটনাটা পরে খাতায় বসলে এমন সম্পদে বসত যেটা আর নেই
        if ($asset->events()->where('status', AssetEvent::AWAITING)->exists()) {
            throw ValidationException::withMessages(['status' => __('accounts::asset.event_pending')]);
        }

        /*
         * ⭐ সই — গ১ ([[AccountsSignature]])। ⓘ বিক্রির অঙ্ক, টাকার খাত আর তারিখ সইয়ের সারিতে থাকে; শেষ সইয়ে
         * [[FinishTheAccountsPaperOnTheLastSignature]] ঠিক এগুলো দিয়েই এই মেথড আবার ডাকে। ছক বন্ধে আগের মতো এখনই।
         */
        if (app(AccountsSignature::class)->holds($asset, AccountsSignature::FIXED_ASSET_DISPOSE, $proceeds, null,
            ['amount' => $proceeds, 'into_account_id' => $intoAccountId, 'on' => $on->toDateString(), 'as' => $as, 'reason' => $reason])) {
            return $asset->refresh();
        }

        $book = $asset->bookValue();
        $accumulated = $asset->accumulated();

        return DB::transaction(function () use ($asset, $on, $proceeds, $book, $accumulated, $intoAccountId, $as, $reason) {
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
                 * ⭐ লাভ নিজের আয়ের খাতে, লোকসান নিজের খরচের খাতে — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২,
                 * ২ অক্টোবর ২০২৬ ([[TheAssetSaleGainHidInTheDepreciationTest]])। ⛔ আগে দুইটাই অবচয়ের খাতে বসত —
                 * লাভ হলে অবচয় ঋণাত্মক দেখাত, আর লাভ-ক্ষতিতে এককালীন বিক্রির লাভটা চালু খরচ কমানোর মতো পড়ত।
                 */
                $gain = bccomp($difference, '0', 4) > 0;
                // ⭐ শ্রেণির নিজের লাভ/লোকসানের খাত আগে (ধাপ ৩)
                $asset->loadMissing('category');
                $head = ($gain ? $asset->category?->gain_account_id : $asset->category?->loss_account_id)
                    ?? $this->disposalAccount($gain);

                $lines[] = $gain
                    ? ['account_id' => (int) $head, 'credit' => $difference]
                    : ['account_id' => (int) $head, 'debit' => bcmul($difference, '-1', 4)];
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
                // ⓘ সম্পদের নিজের শাখায় — যিনি চাপলেন তাঁর শাখায় নয় (ধাপ ৩)
                branchId: $asset->branch_id === null ? null : (int) $asset->branch_id,
            );

            $asset->update([
                'status' => $as,
                'disposal_reason' => $reason,
                'disposed_on' => $on->toDateString(),
                'disposal_amount' => $proceeds,
            ]);

            return $asset->refresh();
        });
    }

    /**
     * সম্পদ বিক্রির লাভ বা লোকসানের খাত — ছকে না থাকলে পরিষ্কার কথা।
     *
     * ⓘ খাত দুইটা ২ অক্টোবর ২০২৬-এ ছকে এল; পুরনো কোম্পানিতে `abos:sync-chart` চালালে বসে। ⛔ চুপচাপ অবচয়ের
     * খাতে ফিরে যাওয়া হয় না — তাহলে ভুলটাই নীরবে ফিরে আসত।
     */
    /** ⓘ বিক্রির লাভ বা লোকসানের ছকের খাত — শ্রেণিতে খাত না থাকলে ([[AssetEventService]]ও এটা নেয়) */
    public function disposalAccount(bool $gain): int
    {
        return (int) $this->disposalHead($gain ? StandardChart::ASSET_DISPOSAL_GAIN : StandardChart::ASSET_DISPOSAL_LOSS)->id;
    }

    private function disposalHead(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::asset.disposal_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }
}
