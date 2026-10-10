<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetEstimateChange;
use App\Modules\Accounts\Models\AssetEvent;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সম্পদের জীবনের ঘটনা — সংযোজন, মেরামত, পুনর্মূল্যায়ন, দাম পড়ে যাওয়া (স্থায়ী সম্পদ ধাপ ৩; IAS 16, IAS 36)।
 *
 * ⓘ প্রতিটা ঘটনার নিজের কাগজ ([[AssetEvent]]), আর টাকা নড়লে নিজের সই ([[AccountsSignature]])। সইয়ের ছক না থাকলে
 * আজকের মতো এখনই খাতায় বসে; ছক থাকলে কাগজটা সইয়ের অপেক্ষায় থাকে, শেষ সইয়ে
 * [[FinishTheAccountsPaperOnTheLastSignature]] [[finish()]] ডাকে।
 *
 * ⚠️ অঙ্কগুলো (লোকসান, উদ্বৃত্ত) শেষ মুহূর্তে হিসাব হয়, সম্পদের তখনকার দামে — মাঝে অবচয়ের দৌড় বসলে পুরনো অঙ্কে
 * বসালে খাতা আর সম্পদের পাতা আলাদা হত। সই যে অঙ্কে চাওয়া হয়, সেটা মালিকের দেওয়া অঙ্ক।
 */
final class AssetEventService
{
    /** ⭐ পুনর্মূল্যায়নের সুইচ — ডিফল্টে বন্ধ, অর্থাৎ খরচের মডেল (IAS 16.30)। মালিকের প্রশ্ন। */
    public const REVALUATION = 'accounts.asset.revaluation';

    private const ACTION = [
        AssetEvent::ADDITION => AccountsSignature::FIXED_ASSET_ADDITION,
        AssetEvent::REPAIR => AccountsSignature::FIXED_ASSET_REPAIR,
        AssetEvent::REVALUATION => AccountsSignature::FIXED_ASSET_REVALUE,
        AssetEvent::IMPAIRMENT => AccountsSignature::FIXED_ASSET_IMPAIR,
    ];

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly NumberSeriesEngine $numbers,
        private readonly FixedAssetService $assets,
    ) {}

    /**
     * ⭐ সংযোজন বা উন্নয়ন — দাম বাড়ে, চাইলে আয়ুও (IAS 16.13)।
     *
     * ⓘ টাকা আসে টাকার খাত থেকে বা বিক্রেতার কাছে দেনায় — নিবন্ধনের মতোই ([[FixedAssetService::fundingFrom()]])।
     *
     * @param  array<string, mixed>  $data
     */
    public function addition(FixedAsset $asset, array $data): AssetEvent
    {
        $amount = $this->positive($data['amount'] ?? null, 'amount');
        $extend = (int) ($data['extend_months'] ?? 0);

        if ($extend > 0 && $asset->method !== FixedAsset::STRAIGHT_LINE) {
            throw ValidationException::withMessages(['extend_months' => __('accounts::asset.extend_only_straight')]);
        }

        $funding = $this->paidFrom($data, allowLogOnly: false);

        return $this->open($asset, AssetEvent::ADDITION, $data, $amount, [
            'amount' => $amount,
            'account_id' => $funding['account_id'],
            'supplier_id' => $this->vendor($data),
            'extend_months' => $extend > 0 ? $extend : null,
        ], ['funding' => $funding]);
    }

    /**
     * ⭐ মেরামত ও রক্ষণাবেক্ষণ — খরচে যায়, সম্পদের দামে নয় (IAS 16.12)।
     *
     * ⓘ টাকা অন্য কাগজে আগেই বসে থাকলে (খরচের ভাউচার, ক্রয় বিল) "কেবল খাতায় লেখা" — দাখিলা নেই, সইও নেই; তবু সম্পদের
     * মেরামতের খরচ তার পাতায় আর প্রতিবেদনে ওঠে।
     *
     * @param  array<string, mixed>  $data
     */
    public function repair(FixedAsset $asset, array $data): AssetEvent
    {
        $amount = $this->positive($data['amount'] ?? null, 'amount');
        $funding = $this->paidFrom($data, allowLogOnly: true);
        $charge = null;

        if ($funding !== null) {
            $charge = Account::query()->postable()->ofType(Account::EXPENSE)->whereKey((int) ($data['charge_account_id'] ?? 0))->first();

            if ($charge === null) {
                throw ValidationException::withMessages(['charge_account_id' => __('accounts::asset.repair_account_wrong')]);
            }
        }

        return $this->open($asset, AssetEvent::REPAIR, $data, $funding === null ? '0' : $amount, [
            'amount' => $amount,
            'account_id' => $funding['account_id'] ?? null,
            'charge_account_id' => $charge?->id,
            'supplier_id' => $this->vendor($data),
        ], ['funding' => $funding]);
    }

    /**
     * ⭐ পুনর্মূল্যায়ন — ন্যায্য দামে (IAS 16.31-42)। ⛔ মালিকের সুইচ চালু না থাকলে নয়: খরচের মডেলই ডিফল্ট।
     *
     * @param  array<string, mixed>  $data
     */
    public function revalue(FixedAsset $asset, array $data): AssetEvent
    {
        if (! (bool) app(SettingsService::class)->get(self::REVALUATION, false)) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.revaluation_off')]);
        }

        $fair = $this->positive($data['amount'] ?? null, 'amount', allowZero: true);
        $surplus = Account::query()->postable()->ofType(Account::EQUITY)->whereKey((int) ($data['account_id'] ?? 0))->first();

        if ($surplus === null) {
            throw ValidationException::withMessages(['account_id' => __('accounts::asset.surplus_account_wrong')]);
        }

        $change = ltrim(bcsub($fair, $asset->bookValue(), 4), '-');

        if (bccomp($change, '0', 4) === 0) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.revaluation_no_change')]);
        }

        return $this->open($asset, AssetEvent::REVALUATION, $data, $change, [
            'amount' => $fair,
            'account_id' => $surplus->id,
        ]);
    }

    /**
     * ⭐ দাম পড়ে যাওয়া — মালিক যে ফেরতযোগ্য দাম দেন, খাতার দাম তার উপরে থাকলে পার্থক্যটা লোকসান (IAS 36.59)।
     *
     * ⓘ কোনো স্বয়ংক্রিয় মূল্যায়ন নেই — পরীক্ষাটা মালিকের। লোকসান শ্রেণির দাম-পড়ার খাতে, আর সঞ্চিত ক্ষয়ের খাতে জমে
     * (জমা দাম-পড়া)। আগের পুনর্মূল্যায়নের উদ্বৃত্ত থাকলে আগে সেটা খায় (IAS 36.61)।
     *
     * @param  array<string, mixed>  $data
     */
    public function impair(FixedAsset $asset, array $data): AssetEvent
    {
        $recoverable = $this->positive($data['amount'] ?? null, 'amount', allowZero: true);
        $loss = bcsub($asset->bookValue(), $recoverable, 4);

        if (bccomp($loss, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.impairment_not_below')]);
        }

        if ($asset->category?->impairment_account_id === null) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.impairment_account_missing')]);
        }

        return $this->open($asset, AssetEvent::IMPAIRMENT, $data, $loss, ['amount' => $recoverable]);
    }

    /**
     * ⭐ ঘটনাটা খাতায় বসানো — সই পেলে বা সইয়ের ছক না থাকলে।
     *
     * ⓘ একবারই: সারিতে তালা দিয়ে অবস্থা দেখা; পাকা হয়ে গেলে কিছুই হয় না।
     *
     * @param  array<string, mixed>  $signed  সই চাওয়ার মুহূর্তের তথ্য
     */
    public function finish(AssetEvent $event, array $signed = []): AssetEvent
    {
        return DB::transaction(function () use ($event, $signed) {
            $locked = AssetEvent::query()->withoutGlobalScope('user-branch')->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isAwaiting()) {
                return $locked;
            }

            $asset = FixedAsset::acrossBranches()->whereKey($locked->fixed_asset_id)->lockForUpdate()->firstOrFail();

            if (! $asset->isInService()) {
                throw ValidationException::withMessages(['status' => __('accounts::asset.not_active')]);
            }

            $funding = $signed['funding'] ?? null;

            [$lines, $changes] = match ($locked->kind) {
                AssetEvent::ADDITION => $this->additionLines($asset, $locked, (array) $funding),
                AssetEvent::REPAIR => $this->repairLines($asset, $locked, $funding === null ? null : (array) $funding),
                AssetEvent::REVALUATION => $this->revaluationLines($asset, $locked),
                AssetEvent::IMPAIRMENT => $this->impairmentLines($asset, $locked),
            };

            if ($lines !== []) {
                $this->posting->post(
                    sourceType: AssetEvent::drillSourceType(),
                    sourceId: $locked->id,
                    trxDate: $locked->happened_on->toDateString(),
                    lines: $lines,
                    documentNo: $locked->document_no,
                    branchId: $asset->branch_id === null ? null : (int) $asset->branch_id,
                );
            }

            $locked->update([...$changes, 'status' => AssetEvent::POSTED]);

            if (bccomp((string) ($changes['cost_change'] ?? '0'), '0', 4) !== 0) {
                $asset->update(['cost' => bcadd((string) $asset->cost, (string) $changes['cost_change'], 4)]);
            }

            if ((int) $locked->extend_months > 0) {
                $this->extendLife($asset, (int) $locked->extend_months, $locked);
            }

            return $locked->refresh();
        });
    }

    // ── খাতার সারি ─────────────────────────────────────────────────────

    /** @return array{0: list<array<string, mixed>>, 1: array<string, string>} */
    private function additionLines(FixedAsset $asset, AssetEvent $event, array $funding): array
    {
        $amount = (string) $event->amount;

        return [[
            ['account_id' => $asset->asset_account_id, 'debit' => $amount, 'narration' => $asset->name],
            ['account_id' => (int) $funding['account_id'], 'credit' => $amount,
                'party_type' => $funding['party_type'] ?? null, 'party_id' => $funding['party_id'] ?? null, 'narration' => $asset->name],
        ], ['cost_change' => $amount]];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, string>} */
    private function repairLines(FixedAsset $asset, AssetEvent $event, ?array $funding): array
    {
        if ($funding === null) {
            return [[], []];
        }

        $amount = (string) $event->amount;

        return [[
            ['account_id' => (int) $event->charge_account_id, 'debit' => $amount, 'narration' => $asset->name],
            ['account_id' => (int) $funding['account_id'], 'credit' => $amount,
                'party_type' => $funding['party_type'] ?? null, 'party_id' => $funding['party_id'] ?? null, 'narration' => $asset->name],
        ], ['effect' => bcmul($amount, '-1', 4)]];
    }

    /**
     * ⓘ সঞ্চিত ক্ষয় মুছে দাম ন্যায্য দামে (IAS 16.35খ)। বাড়লে উদ্বৃত্তে — তবে আগে লাভ-ক্ষতিতে যাওয়া কমতি থাকলে আগে সেটা
     * ফেরত (IAS 16.39); কমলে আগে উদ্বৃত্ত খায়, বাকিটা লোকসান (IAS 16.40)।
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function revaluationLines(FixedAsset $asset, AssetEvent $event): array
    {
        $fair = (string) $event->amount;
        $cost = (string) $asset->cost;
        $accumulated = $asset->accumulated();
        $difference = bcsub($fair, bcsub($cost, $accumulated, 4), 4);

        [$surplusPart, $profitPart] = $this->split($asset, $difference);
        $lines = [];

        if (bccomp($accumulated, '0', 4) !== 0) {
            $lines[] = $this->side($asset->accumulated_account_id, bcmul($accumulated, '-1', 4), $asset->name);
        }

        $costChange = bcsub($fair, $cost, 4);

        if (bccomp($costChange, '0', 4) !== 0) {
            $lines[] = $this->side($asset->asset_account_id, bcmul($costChange, '-1', 4), $asset->name);
        }

        if (bccomp($surplusPart, '0', 4) !== 0) {
            $lines[] = $this->side((int) $event->account_id, $surplusPart, $asset->name);
        }

        if (bccomp($profitPart, '0', 4) !== 0) {
            $lines[] = $this->side($this->profitAccount($asset, $profitPart), $profitPart, $asset->name);
        }

        return [$lines, [
            'cost_change' => $costChange,
            'accumulated_change' => bcmul($accumulated, '-1', 4),
            'surplus_change' => $surplusPart,
            'effect' => $profitPart,
        ]];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, string>} */
    private function impairmentLines(FixedAsset $asset, AssetEvent $event): array
    {
        $loss = bcsub($asset->bookValue(), (string) $event->amount, 4);

        if (bccomp($loss, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.impairment_not_below')]);
        }

        // ⓘ উদ্বৃত্ত থাকলে আগে সেটা (IAS 36.61), বাকিটা শ্রেণির দাম-পড়ার খাতে
        $fromSurplus = $this->surplusLeft($asset);
        $fromSurplus = bccomp($fromSurplus, $loss, 4) < 0 ? $fromSurplus : $loss;
        $toProfit = bcsub($loss, $fromSurplus, 4);
        $surplusAccount = $this->surplusAccountOf($asset);

        $lines = [['account_id' => $asset->accumulated_account_id, 'credit' => $loss, 'narration' => $asset->name]];

        if (bccomp($fromSurplus, '0', 4) > 0 && $surplusAccount !== null) {
            $lines[] = ['account_id' => $surplusAccount, 'debit' => $fromSurplus, 'narration' => $asset->name];
        } else {
            $toProfit = $loss;
            $fromSurplus = '0';
        }

        if (bccomp($toProfit, '0', 4) > 0) {
            $lines[] = ['account_id' => (int) $asset->category->impairment_account_id, 'debit' => $toProfit, 'narration' => $asset->name];
        }

        return [$lines, [
            'accumulated_change' => $loss,
            'surplus_change' => bcmul($fromSurplus, '-1', 4),
            'effect' => bcmul($toProfit, '-1', 4),
        ]];
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * কাগজটা খোলা, সই চাওয়া, ছক না থাকলে এখনই বসানো।
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     */
    private function open(FixedAsset $asset, string $kind, array $data, string $signFor, array $fields, array $payload = []): AssetEvent
    {
        if (! $asset->isInService()) {
            throw ValidationException::withMessages(['status' => __('accounts::asset.not_active')]);
        }

        $on = Carbon::parse($data['happened_on'] ?? now())->startOfDay();

        if ($on->lt($asset->acquired_on)) {
            throw ValidationException::withMessages(['happened_on' => __('accounts::asset.event_before_acquired')]);
        }

        app(OpenPeriod::class)->assertOpen($on, 'happened_on');

        // ⛔ এক সম্পদে একসাথে একটাই ঝুলন্ত ঘটনা — নইলে দুইটা শেষ অঙ্ক একই পুরনো দামের উপর হিসাব হত
        if (AssetEvent::query()->withoutGlobalScope('user-branch')->where('fixed_asset_id', $asset->id)
            ->where('status', AssetEvent::AWAITING)->exists()) {
            throw ValidationException::withMessages(['amount' => __('accounts::asset.event_pending')]);
        }

        return DB::transaction(function () use ($asset, $kind, $data, $on, $signFor, $fields, $payload) {
            $event = AssetEvent::query()->create([
                ...$fields,
                'company_id' => CompanyContext::id(),
                'branch_id' => $asset->branch_id,
                'fixed_asset_id' => $asset->id,
                'kind' => $kind,
                'document_no' => $this->numbers->next(AssetEvent::SERIES, $asset->branch_id === null ? null : (int) $asset->branch_id, $on),
                'happened_on' => $on->toDateString(),
                'reason' => ($data['reason'] ?? '') ?: null,
                'status' => AssetEvent::AWAITING,
                'created_by' => auth()->id(),
            ]);

            // ⓘ টাকা না নড়লে (কেবল খাতায় লেখা মেরামত) সই নয়
            if (bccomp($signFor, '0', 4) > 0
                && app(AccountsSignature::class)->holds($event, self::ACTION[$kind], $signFor, $event->reason, $payload)) {
                return $event->refresh();
            }

            return $this->finish($event, $payload);
        });
    }

    /**
     * টাকা কোথা থেকে — টাকার খাত, বিক্রেতার দেনা, বা (মেরামতে) "অন্য কাগজে আগেই বসেছে"।
     *
     * @param  array<string, mixed>  $data
     * @return array{account_id: int, party_type: ?string, party_id: ?int}|null
     */
    private function paidFrom(array $data, bool $allowLogOnly): ?array
    {
        $how = (string) ($data['funded_by'] ?? '');
        $ways = $allowLogOnly
            ? [FixedAssetService::FUNDED_MONEY, FixedAssetService::FUNDED_CREDIT, FixedAssetService::FUNDED_ALREADY]
            : [FixedAssetService::FUNDED_MONEY, FixedAssetService::FUNDED_CREDIT];

        if (! in_array($how, $ways, true)) {
            throw ValidationException::withMessages(['funded_by' => __('accounts::asset.event_funding_wrong')]);
        }

        return $this->assets->fundingFrom($data);
    }

    /** @param  array<string, mixed>  $data */
    private function vendor(array $data): ?int
    {
        $id = (int) ($data['funding_supplier_id'] ?? $data['supplier_id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        if (! app(PartyRegistry::class)->exists('supplier', $id)) {
            throw ValidationException::withMessages(['supplier_id' => __('accounts::asset.funding_not_found')]);
        }

        return $id;
    }

    private function positive(mixed $value, string $field, bool $allowZero = false): string
    {
        if (! is_numeric($value) || bccomp(Money::of((string) $value), '0', 4) < ($allowZero ? 0 : 1)) {
            throw ValidationException::withMessages([$field => __('accounts::asset.amount_positive')]);
        }

        return Money::of((string) $value);
    }

    /**
     * পার্থক্যটা উদ্বৃত্ত আর লাভ-ক্ষতিতে ভাগ — [উদ্বৃত্তের অংশ, লাভ-ক্ষতির অংশ], দুইটাই চিহ্নসহ (+ বাড়া, − কমা)।
     *
     * @return array{0: string, 1: string}
     */
    private function split(FixedAsset $asset, string $difference): array
    {
        if (bccomp($difference, '0', 4) >= 0) {
            // ⓘ আগে লাভ-ক্ষতিতে যাওয়া কমতি ফেরত (IAS 16.39)
            $charged = bcmul($this->profitSoFar($asset), '-1', 4);
            $back = bccomp($charged, '0', 4) > 0 ? (bccomp($charged, $difference, 4) < 0 ? $charged : $difference) : '0';

            return [bcsub($difference, $back, 4), $back];
        }

        $drop = bcmul($difference, '-1', 4);
        $left = $this->surplusLeft($asset);
        $fromSurplus = bccomp($left, $drop, 4) < 0 ? $left : $drop;

        return [bcmul($fromSurplus, '-1', 4), bcmul(bcsub($drop, $fromSurplus, 4), '-1', 4)];
    }

    private function surplusLeft(FixedAsset $asset): string
    {
        $left = (string) ($asset->postedEvents()->sum('surplus_change') ?: '0');

        return bccomp($left, '0', 4) > 0 ? Money::of($left) : '0.0000';
    }

    private function profitSoFar(FixedAsset $asset): string
    {
        return Money::of((string) ($asset->postedEvents()
            ->whereIn('kind', [AssetEvent::REVALUATION, AssetEvent::IMPAIRMENT])->sum('effect') ?: '0'));
    }

    /** শেষ পুনর্মূল্যায়নের উদ্বৃত্তের খাত */
    private function surplusAccountOf(FixedAsset $asset): ?int
    {
        $id = $asset->postedEvents()->where('kind', AssetEvent::REVALUATION)->whereNotNull('account_id')
            ->orderByDesc('happened_on')->orderByDesc('id')->value('account_id');

        return $id === null ? null : (int) $id;
    }

    /** লাভ হলে শ্রেণির লাভের খাত, লোকসান হলে দাম-পড়ার খাত (না থাকলে লোকসানের খাত) */
    private function profitAccount(FixedAsset $asset, string $amount): int
    {
        $category = $asset->category;

        if (bccomp($amount, '0', 4) > 0) {
            return (int) ($category?->gain_account_id ?? $this->assets->disposalAccount(true));
        }

        return (int) ($category?->impairment_account_id ?? $category?->loss_account_id ?? $this->assets->disposalAccount(false));
    }

    /**
     * চিহ্নসহ অঙ্ক থেকে সারি — ধনাত্মক মানে জমা (ক্রেডিট), ঋণাত্মক মানে খরচ (ডেবিট)।
     *
     * @return array<string, mixed>
     */
    private function side(int $accountId, string $signed, string $narration): array
    {
        return bccomp($signed, '0', 4) > 0
            ? ['account_id' => $accountId, 'credit' => $signed, 'narration' => $narration]
            : ['account_id' => $accountId, 'debit' => bcmul($signed, '-1', 4), 'narration' => $narration];
    }

    /** ⓘ আয়ু বাড়ানো একটা হিসাবের অনুমান বদল — ইতিহাসে থাকে (IAS 8.36) */
    private function extendLife(FixedAsset $asset, int $months, AssetEvent $event): void
    {
        $before = (int) $asset->life_months;

        AssetEstimateChange::query()->create([
            'company_id' => $asset->company_id,
            'fixed_asset_id' => $asset->id,
            'changed_on' => $event->happened_on->toDateString(),
            'before' => ['life_months' => (string) $before],
            'after' => ['life_months' => (string) ($before + $months)],
            'reason' => __('accounts::asset.extended_by_addition', ['no' => $event->document_no]),
            'created_by' => auth()->id(),
        ]);

        $asset->update(['life_months' => $before + $months]);
    }
}
