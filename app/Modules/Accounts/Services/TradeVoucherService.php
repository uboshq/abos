<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\CreditWall;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Models\TradeVoucherLine;
use App\Modules\Accounts\Support\LineMath;
use App\Modules\Accounts\Support\TradeCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[TradeVoucher]])।
 *
 * ⛔ টাকার ঘর নেই: বিল কেবল পাওনা/দেনা বানায়। টাকা আসে রসিদে (RCV), যায় পরিশোধ ভাউচারে।
 * ⓘ SV বিক্রয়ের বিলের একই বাকির দেয়াল মানে ([[CreditWall]]); PUV-তে উৎসে কর বিলেই কাটা (Cr ২১২১), উৎসে ভ্যাট নয়।
 * ⓘ সুইচ বন্ধ থাকলে পর্দা আর দরজা দুটোই বন্ধ (মেনু-সারির `setting`, [[\App\Http\Middleware\RefuseSwitchedOffScreens]]); এই সেবা
 * নিজেও একবার দেখে — পর্দা এড়িয়ে পাঠালেও।
 */
final class TradeVoucherService
{
    public const SWITCH = 'accounts.stock_free_vouchers';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly PartyRegistry $parties,
        private readonly TradeCatalog $catalog,
        private readonly SettingsService $settings,
    ) {}

    /** ⭐ কোম্পানির সুইচ — "স্টক ছাড়া বিক্রয় ভাউচার ও ক্রয় ভাউচার" (কন্ট্রোল প্যানেল); মজুদ চালু কোম্পানিতে ডিফল্ট বন্ধ */
    public function isOn(): bool
    {
        return (bool) $this->settings->get(self::SWITCH);
    }

    /** ⓘ মজুদ চালু — তখন পর্দায় ছোট সতর্কতা: এখানে মাল নড়ে না (থামায় না; মালিকের নিয়ম "সতর্কতা কাজ থামায় না") */
    public function inventoryIsOn(): bool
    {
        return (bool) $this->settings->get('inventory.enabled', true);
    }

    /** @return 'customer'|'supplier' */
    public static function partyType(string $kind): string
    {
        return $kind === TradeVoucher::SALE ? 'customer' : 'supplier';
    }

    /**
     * খসড়া — খাতায় কিছুই যায় না।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $kind, array $data): TradeVoucher
    {
        if (! array_key_exists($kind, TradeVoucher::DOC_TYPES)) {
            throw ValidationException::withMessages(['kind' => __('accounts::trade.unknown_kind')]);
        }

        if (! $this->isOn()) {
            throw ValidationException::withMessages(['kind' => __('accounts::trade.switched_off')]);
        }

        $partyType = self::partyType($kind);
        $partyId = (int) ($data['party_id'] ?? 0);
        $party = $partyId > 0 ? $this->parties->find($partyType, $partyId) : null;

        if ($party === null) {
            throw ValidationException::withMessages(['party_id' => __('accounts::note.party_not_found')]);
        }

        $narration = trim((string) ($data['narration'] ?? ''));

        if ($narration === '' && (bool) $this->settings->get('accounts.require_narration', true)) {
            throw ValidationException::withMessages(['narration' => __('accounts::trade.narration_required')]);
        }

        $built = $this->build($kind, (array) ($data['lines'] ?? []));
        $tds = $kind === TradeVoucher::PURCHASE ? LineMath::round2(LineMath::money($data['tds_amount'] ?? '0')) : '0.0000';

        if (bccomp($tds, '0', 4) < 0 || bccomp($tds, $built['total'], 4) > 0) {
            throw ValidationException::withMessages(['tds_amount' => __('accounts::trade.tds_over_total')]);
        }

        $date = Carbon::parse($data['trx_date'] ?? Carbon::today())->startOfDay();
        $days = (int) ($party->getAttribute('credit_days') ?? 0);

        return DB::transaction(function () use ($kind, $data, $partyType, $partyId, $built, $tds, $date, $days, $narration) {
            $documentNo = $this->numbers->next(TradeVoucher::DOC_TYPES[$kind]);

            $voucher = TradeVoucher::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $documentNo,
                'kind' => $kind,
                'trx_date' => $date->toDateString(),
                'party_type' => $partyType,
                'party_id' => $partyId,
                // ⓘ পক্ষের শর্ত থেকে, তালাবদ্ধ — হাতে বদলায় না
                'due_on' => $date->copy()->addDays(max(0, $days))->toDateString(),
                'ref_no' => $this->text($data['ref_no'] ?? null, 64),
                'mushak_no' => $kind === TradeVoucher::PURCHASE ? $this->text($data['mushak_no'] ?? null, 64) : null,
                'mushak_date' => $kind === TradeVoucher::PURCHASE && filled($data['mushak_date'] ?? null) ? $data['mushak_date'] : null,
                'amount' => $built['amount'],
                'tax_amount' => $built['tax'],
                'total' => $built['total'],
                'tds_amount' => $tds,
                'party_total' => bcsub($built['total'], $tds, 4),
                'narration' => $narration === '' ? null : mb_substr($narration, 0, 500),
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($built['lines'] as $line) {
                $voucher->lines()->create([...$line, 'company_id' => $voucher->company_id]);
            }

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update(['source_type' => $voucher->sourceType(), 'source_id' => $voucher->id]);

            return $voucher->load('lines');
        });
    }

    /**
     * ⭐ পাকা করা — খাতায় বসে। ⓘ সইয়ের ছক থাকলে আগে সই ([[AccountsSignature]]); শেষ সই পড়লে
     * [[\App\Modules\Accounts\Listeners\FinishTheAccountsPaperOnTheLastSignature]] এটাই ডাকে।
     */
    public function confirm(TradeVoucher $voucher): TradeVoucher
    {
        if (! $voucher->isDraft()) {
            throw ValidationException::withMessages(['status' => __('accounts::note.only_a_draft_can_be_confirmed')]);
        }

        $action = $voucher->isSale() ? AccountsSignature::SALES_VOUCHER : AccountsSignature::PURCHASE_VOUCHER;

        // ⛔ বাকির সীমা — সইয়ের আগেই (সই দিয়ে সীমা পেরোনো যায় না), বিলের একই দেয়াল
        if ($voucher->isSale()) {
            app(CreditWall::class)->assertRoom((int) $voucher->party_id, (string) $voucher->total, $voucher);
        }

        if (app(AccountsSignature::class)->holds($voucher, $action, (string) $voucher->total, $voucher->narration)) {
            return $voucher;
        }

        return DB::transaction(function () use ($voucher) {
            if ($voucher->isSale()) {
                app(CreditWall::class)->assertRoom((int) $voucher->party_id, (string) $voucher->total, $voucher, locked: true);
            }

            $status = (string) TradeVoucher::query()->withoutGlobalScopes()->whereKey($voucher->id)->lockForUpdate()->value('status');

            if ($status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages(['status' => __('accounts::note.only_a_draft_can_be_confirmed')]);
            }

            $this->posting->post(
                sourceType: $voucher->sourceType(),
                sourceId: $voucher->id,
                trxDate: $voucher->trx_date,
                lines: $this->postingLines($voucher),
                documentNo: $voucher->document_no,
                branchId: $voucher->branch_id,
            );

            $voucher->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => Carbon::now(),
            ])->save();

            return $voucher->refresh();
        });
    }

    /** বাতিল — বসে যাওয়া দাখিলা আজকের তারিখে ফেরে; কারণ বাধ্যতামূলক */
    public function cancel(TradeVoucher $voucher, string $reason): TradeVoucher
    {
        if ($voucher->isCancelled()) {
            return $voucher;
        }

        return DB::transaction(function () use ($voucher, $reason) {
            $status = (string) TradeVoucher::query()->withoutGlobalScopes()->whereKey($voucher->id)->lockForUpdate()->value('status');

            if ($status === DocumentStatus::CANCELLED) {
                return $voucher->refresh();
            }

            if ($status === DocumentStatus::CONFIRMED
                && LedgerEntry::query()->where('source_type', $voucher->sourceType())->where('source_id', $voucher->id)->exists()) {
                $this->posting->reverse(
                    sourceType: $voucher->sourceType(),
                    sourceId: $voucher->id,
                    reversalDate: Carbon::today(),
                    reason: $reason,
                );
            }

            $voucher->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => Carbon::now(),
            ])->save();

            return $voucher->refresh();
        });
    }

    /**
     * ⭐ দাখিলা।
     *
     *   SV:  Dr ১১১০ (গ্রাহক) মোট  /  Cr সারির খাত · Cr ২১২০ ভ্যাট
     *   PUV: Dr সারির খাত · Dr ১১৩৯ ক্রয়-ভ্যাট  /  Cr ২১১৮ (সরবরাহকারী) মোট − উৎসে কর · Cr ২১২১ উৎসে কর
     *
     * @return list<array<string, mixed>>
     */
    public function postingLines(TradeVoucher $voucher): array
    {
        $voucher->loadMissing('lines');
        $narration = $voucher->narration;
        $byAccount = [];

        foreach ($voucher->lines as $line) {
            /** @var TradeVoucherLine $line */
            $byAccount[(int) $line->account_id] = bcadd($byAccount[(int) $line->account_id] ?? '0', (string) $line->amount, 4);
        }

        $vat = (string) $voucher->tax_amount;
        $id = fn (string $code) => (int) StandardChart::accountFor($code)->id;

        if ($voucher->isSale()) {
            $rows = [[
                'account_id' => $id(StandardChart::RECEIVABLE),
                'debit' => (string) $voucher->total,
                'party_type' => $voucher->party_type,
                'party_id' => (int) $voucher->party_id,
                'narration' => $narration,
            ]];

            foreach ($byAccount as $accountId => $amount) {
                $rows[] = ['account_id' => $accountId, 'credit' => $amount, 'narration' => $narration];
            }

            if (bccomp($vat, '0', 4) > 0) {
                $rows[] = ['account_id' => $id(StandardChart::VAT_PAYABLE), 'credit' => $vat, 'narration' => $narration];
            }

            return $rows;
        }

        $rows = [];

        foreach ($byAccount as $accountId => $amount) {
            $rows[] = ['account_id' => $accountId, 'debit' => $amount, 'narration' => $narration];
        }

        if (bccomp($vat, '0', 4) > 0) {
            $rows[] = ['account_id' => $id(StandardChart::INPUT_VAT_RECOVERABLE), 'debit' => $vat, 'narration' => $narration];
        }

        $rows[] = [
            'account_id' => $id(StandardChart::VENDOR_PAYABLE),
            'credit' => (string) $voucher->party_total,
            'party_type' => $voucher->party_type,
            'party_id' => (int) $voucher->party_id,
            'narration' => $narration,
        ];

        if (bccomp((string) $voucher->tds_amount, '0', 4) > 0) {
            $rows[] = ['account_id' => $id(StandardChart::TAX_DEDUCTED_AT_SOURCE), 'credit' => (string) $voucher->tds_amount, 'narration' => $narration];
        }

        return $rows;
    }

    /**
     * সারির খাতের তালিকা — SV: আয়ের খাত; PUV: খরচের খাত।
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function accountChoices(string $kind): array
    {
        return $this->catalog->accounts([$kind === TradeVoucher::SALE ? Account::INCOME : Account::EXPENSE]);
    }

    /** পণ্যের সারির খাত — SV: ৪১০০ বিক্রয়; PUV: ৫১০০ (হিসাবে-কেবল পণ্য, মজুদ নড়ে না) */
    public function productAccountId(string $kind): int
    {
        return (int) StandardChart::accountFor($kind === TradeVoucher::SALE ? StandardChart::SALES : StandardChart::COST_OF_GOODS_SOLD)->id;
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @return array{lines: list<array<string, mixed>>, amount: string, tax: string, total: string}
     */
    private function build(string $kind, array $input): array
    {
        $allowed = collect($this->accountChoices($kind))->keyBy('id');
        $lines = [];
        $sums = ['amount' => '0', 'tax' => '0', 'total' => '0'];
        $no = 0;

        foreach (array_values($input) as $i => $row) {
            $type = (string) ($row['type'] ?? $row['line_type'] ?? NoteLine::ACCOUNT);
            $field = 'lines.'.$i;

            if (! in_array($type, NoteLine::TYPES, true)) {
                throw ValidationException::withMessages([$field => __('accounts::note.line_type_unknown')]);
            }

            $line = ['line_type' => $type, 'product_id' => null, 'item_name' => null, 'unit' => null,
                'description' => $this->text($row['description'] ?? null, 255)];

            if ($type === NoteLine::PRODUCT) {
                $product = $this->catalog->product((int) ($row['product_id'] ?? 0));

                if ($product === null) {
                    throw ValidationException::withMessages([$field => __('accounts::note.product_unknown')]);
                }

                $line['product_id'] = $product['id'];
                $line['item_name'] = $product['name'];
                $line['unit'] = $product['unit'];
                $line['account_id'] = $this->productAccountId($kind);
                $qty = (string) ($row['qty'] ?? '0');
                $rate = (string) ($row['rate'] ?? '0');
                $amount = $row['amount'] ?? null;
            } elseif ($type === NoteLine::SERVICE) {
                $line['item_name'] = $this->text($row['item_name'] ?? null, 191);

                if ($line['item_name'] === null) {
                    throw ValidationException::withMessages([$field => __('accounts::note.service_name_needed')]);
                }

                $line['unit'] = $this->text($row['unit'] ?? null, 32);
                $line['account_id'] = $this->allowedAccount($row, $allowed, $field);
                $qty = (string) ($row['qty'] ?? '0');
                $rate = (string) ($row['rate'] ?? '0');
                $amount = $row['amount'] ?? null;
            } else {
                $line['account_id'] = $this->allowedAccount($row, $allowed, $field);
                $qty = '1';
                $rate = (string) ($row['amount'] ?? $row['rate'] ?? '0');
                $amount = null;
            }

            $taxId = (int) ($row['tax_id'] ?? 0);
            $vatRate = $this->catalog->taxRate($taxId);
            $figures = LineMath::figures($qty, $rate, $amount === null ? null : (string) $amount, $vatRate ?? '0');

            if (bccomp($figures['qty'], '0', 4) <= 0) {
                throw ValidationException::withMessages([$field => __('accounts::note.qty_must_be_positive')]);
            }

            if (bccomp($figures['amount'], '0', 4) <= 0) {
                throw ValidationException::withMessages([$field => __('accounts::note.amount_must_be_positive')]);
            }

            $lines[] = [...$line,
                'line_no' => ++$no,
                'qty' => $figures['qty'],
                'rate' => $figures['rate'],
                'amount' => $figures['amount'],
                'tax_id' => $vatRate === null ? null : $taxId,
                'vat_rate' => LineMath::money($vatRate ?? '0'),
                'vat_amount' => $figures['vat'],
                'total' => $figures['total'],
            ];

            $sums['amount'] = bcadd($sums['amount'], $figures['amount'], 4);
            $sums['tax'] = bcadd($sums['tax'], $figures['vat'], 4);
            $sums['total'] = bcadd($sums['total'], $figures['total'], 4);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('accounts::note.no_lines')]);
        }

        return [...$sums, 'lines' => $lines];
    }

    /** @param \Illuminate\Support\Collection<int, array{id: int, code: string, name: string}> $allowed */
    private function allowedAccount(array $row, \Illuminate\Support\Collection $allowed, string $field): int
    {
        $id = (int) ($row['account_id'] ?? 0);

        if (! $allowed->has($id)) {
            throw ValidationException::withMessages([$field => __('accounts::note.account_not_allowed')]);
        }

        return $id;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
