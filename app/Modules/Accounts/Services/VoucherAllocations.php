<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\AcceptsVouchers;
use App\Core\Contracts\PartyOpenBills;
use App\Core\Contracts\PayableOpenBills;
use App\Core\Contracts\SettlementTerms;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ এক টাকা, বহু বিল — রসিদ বা পরিশোধের প্রতিটা বিলের সারি (`bills[i][id]` = বিলের public_id, `bills[i][amount]`; cb-ma-4, ২৫ অক্টোবর
 * ২০২৬; পরিশোধে-মোট-টাকা-চুক্তি §৩)।
 *
 * ⓘ নিয়ম: প্রতিটা সারি > ০ আর ≤ সেই বিলের বকেয়া; যোগফল ≤ মোট (পক্ষের খাতা যতটা কমে: টাকা + VDS + AIT)। যোগফলের বাইরে যা থাকে তা পক্ষের
 * অগ্রিম — খাতায় পক্ষ পুরোটায় কমে, বিলে বসে না।
 *
 * ⭐ অডিট ম১ যা পাহারা দিত, প্রতিটা সারিতে তাই — পোস্টের মুহূর্তে, তালাসহ, লেনদেনের ভিতরে ([[assertFit()]]; [[VoucherService::assertAgainstFits()]]-এর
 * একই পাঁচ প্রশ্ন): কাগজটা আছে, এখনো খোলা, ভাউচারের ধরন মেলে, অঙ্ক বাকির বেশি নয়, পক্ষ মেলে। ⓘ লেখার সময়ও মাপা হয় ([[resolve()]]) —
 * তালিকা আসে পক্ষের নিজের খোলা বিল থেকে (বিলের মালিক মডিউলের চুক্তি [[PartyOpenBills]]), তাই অন্য পক্ষের বা বন্ধ বিলের নাম পাঠিয়ে
 * কিছু হয় না।
 *
 * ⭐ ক্রেডিট নোট (সিদ্ধান্ত, আন্তর্জাতিক নিয়মে — SAP/Oracle-এ ক্রেডিট মেমো বিলের বিপরীতে প্রয়োগ হয়): এই ব্যবস্থায় প্রতিটা ক্রেডিট নোট
 * জারির সময়েই নিজের বিলের নাম বলে ([[\App\Core\Contracts\NoteTarget]]) আর তখনই সেই বিলের বাকি কমায় (ফেরত/নোট [[SalesInvoice::dueAmount()]]-এ)।
 * তাই রসিদে আবার প্রয়োগ নয় — খোলা বিলের "বাকি" ইতিমধ্যে নোট বাদ দিয়ে; দ্বিতীয়বার বসালে বিলের বাকি দুইবার কমত।
 */
final class VoucherAllocations
{
    public function __construct(
        private readonly PartyOpenBills $openBills,
        private readonly PayableOpenBills $payableBills,
    ) {}

    /**
     * ⭐ পক্ষের খোলা বিল — গ্রাহকের বিক্রয় বিল ([[PartyOpenBills]]) আর সরবরাহকারীর ক্রয় বিল ([[PayableOpenBills]]; cb-ma-5)। ⓘ ওয়েবের
     * কার্ড, ফোনের দরজা আর লেখার যাচাই — তিনজনেই এখান থেকে, তাই যা দেখায় তা-ই বাছা যায়।
     *
     * ⓘ `$withNotes` — বিলে না-বাঁধা ক্রেডিট/ডেবিট নোট নিজের সারিতে (a5-এর চুক্তি 56f7e0e15, `kind`); কেবল ওয়েবের কার্ড চায়, দেখানোর জন্য।
     * ⛔ লেখার যাচাই চায় না — নোট এই কাগজে প্রয়োগ হয় না (আলাদা কাজ), তাই নোটের public_id পাঠালে "অজানা বিল"।
     *
     * @return list<array<string, mixed>>
     */
    public function openBillsOf(string $partyType, int $partyId, int $limit = 50, bool $withNotes = false): array
    {
        return [...$this->openBills->openBills($partyType, $partyId, $limit, $withNotes), ...$this->payableBills->openBills($partyType, $partyId, $limit)];
    }

    /**
     * পাঠানো সারি → পক্ষের খোলা বিল; ভুল ঘর ধরে (চুক্তি §৪-এর চাবি)।
     *
     * @param  array<int|string, mixed>  $rows  `bills` — প্রতিটা `['id' => public_id, 'amount' => …]`
     * @return array{rows: list<array{against_type: string, against_id: int, public_id: string, no: string, due: string, amount: string}>, errors: array<string, string>}
     */
    public function resolve(?string $partyType, ?int $partyId, array $rows, ?string $gross = null): array
    {
        $open = filled($partyType) && (int) $partyId > 0
            ? collect($this->openBillsOf((string) $partyType, (int) $partyId, 1000))->keyBy('public_id')
            : collect();

        $resolved = [];
        $errors = [];
        $sum = '0';

        foreach ($rows as $i => $row) {
            $publicId = trim((string) (is_array($row) ? ($row['id'] ?? '') : ''));
            $amount = self::money(is_array($row) ? ($row['amount'] ?? null) : null);
            $bill = $open->get($publicId);

            if ($bill === null) {
                $errors["bills.{$i}.id"] = __('accounts::validation.against_unknown');

                continue;
            }

            // ⓘ একই বিল দুই সারিতে — যোগ করে একবার মাপা (বাকির বেশি দুই ভাগে লুকানো যায় না)
            $already = isset($resolved[$publicId]) ? $resolved[$publicId]['amount'] : '0';
            $together = bcadd($already, $amount, 4);

            if (bccomp($together, (string) $bill['outstanding'], 4) > 0) {
                $errors["bills.{$i}.amount"] = __('accounts::validation.bill_over_due', [
                    'bill' => $bill['no'], 'amount' => Money::format($together), 'due' => Money::format((string) $bill['outstanding']),
                ]);

                continue;
            }

            $resolved[$publicId] = [
                'against_type' => (string) $bill['against_type'],
                'against_id' => (int) $bill['id'],
                'public_id' => $publicId,
                'no' => (string) $bill['no'],
                'due' => bcadd((string) $bill['outstanding'], '0', 4),
                'amount' => $together,
            ];
            $sum = bcadd($sum, $amount, 4);
        }

        if ($gross !== null && bccomp($sum, $gross, 4) > 0) {
            $errors['bills'] = __('accounts::validation.bills_over_gross', ['sum' => Money::format($sum), 'gross' => Money::format($gross)]);
        }

        return ['rows' => array_values($resolved), 'errors' => $errors];
    }

    /**
     * কাগজের সারিগুলো নতুন করে — সম্পাদনায় আগেরগুলো মুছে (খসড়ায় কেবল; পাকা কাগজ সেবা বদলাতেই দেয় না)।
     *
     * @param  list<array{against_type: string, against_id: int, amount: string}>  $rows
     */
    public function write(Voucher $voucher, array $rows): void
    {
        VoucherAllocation::query()->where('voucher_id', $voucher->id)->get()->each->delete();

        foreach ($rows as $row) {
            VoucherAllocation::query()->create([
                'voucher_id' => $voucher->id,
                'against_type' => $row['against_type'],
                'against_id' => $row['against_id'],
                'amount' => $row['amount'],
            ]);
        }
    }

    /**
     * ⭐ পোস্টের মুহূর্তে, তালাসহ — অডিট ম১-এর পাঁচ প্রশ্ন প্রতিটা সারিতে, আর যোগফল ≤ মোট।
     *
     * ⓘ মোট = পক্ষের খাতা যতটা নড়ে: রসিদে ডেবিটের যোগ (টাকা − চার্জ + চার্জ + VDS + AIT), পরিশোধে ডেবিট − চার্জ (চার্জ মোটের বাইরে) —
     * [[VoucherService::assertAgainstFits()]]-এর একই মাপ। ⓘ তালা — একই বিলের দুই রসিদ একসাথে এলে দুজনেই পুরো বাকি দেখত।
     */
    public function assertFit(Voucher $voucher): void
    {
        $rows = VoucherAllocation::query()->where('voucher_id', $voucher->id)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $map = app(DrillResolver::class)->map();
        $sum = '0';

        foreach ($rows as $row) {
            $class = $map[(string) $row->against_type] ?? null;
            $model = $class === null ? null : new $class;
            $document = $model instanceof Model ? $model->newQuery()->lockForUpdate()->find((int) $row->against_id) : null;

            if ($document === null || ! $document instanceof SettlementTerms) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.against_unknown')]);
            }

            if ($document instanceof AcceptsVouchers && ! $document->acceptsVouchers()) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.against_closed')]);
            }

            $terms = $document->settlementTerms();

            if (! $terms['open']) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.against_closed')]);
            }

            if ($terms['voucher_type'] !== $voucher->type) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.against_wrong_type')]);
            }

            $partyType = $terms['party_type'] ?? null;
            if ($partyType !== null && ($voucher->party_type !== $partyType || (int) $voucher->party_id !== (int) ($terms['party_id'] ?? 0))) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.against_wrong_party')]);
            }

            if (bccomp((string) $row->amount, (string) $terms['amount'], 4) > 0) {
                throw ValidationException::withMessages(['bills' => __('accounts::validation.bill_over_due', [
                    'bill' => (string) ($document->getAttribute('document_no') ?? $row->against_id),
                    'amount' => Money::format((string) $row->amount),
                    'due' => Money::format((string) $terms['amount']),
                ])]);
            }

            $sum = bcadd($sum, (string) $row->amount, 4);
        }

        $debit = $voucher->totals()['debit'];
        $gross = $voucher->type === Voucher::PAYMENT ? bcsub($debit, (string) ($voucher->charge_amount ?? '0'), 4) : $debit;

        if (bccomp($sum, $gross, 4) > 0) {
            throw ValidationException::withMessages(['bills' => __('accounts::validation.bills_over_gross', [
                'sum' => Money::format($sum), 'gross' => Money::format($gross),
            ])]);
        }
    }

    private static function money(mixed $value): string
    {
        $value = str_replace(',', '', trim((string) ($value ?? '')));

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? bcadd($value, '0', 4) : '0.0000';
    }
}
