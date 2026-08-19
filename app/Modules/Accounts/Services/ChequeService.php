<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * চেকের জীবন — হাতে আসা থেকে পাশ বা ফেরত পর্যন্ত।
 *
 * ── কেন প্রতিটা ধাপে দাখিলা ─────────────────────────────────────────
 * চেক হাতে পাওয়া আর টাকা পাওয়া এক জিনিস নয়, আর ঠিক এই পার্থক্যটাই
 * আগে কোথাও ছিল না।
 *
 *   গৃহীত চেক
 *     হাতে এল   Dr হাতে চেক (১১০৪)      Cr ডিলার
 *     পাশ হলো   Dr ব্যাংক               Cr হাতে চেক
 *     ফেরত এল   Dr ডিলার                Cr হাতে চেক
 *
 *   ইস্যু করা চেক
 *     দেওয়া হলো Dr সরবরাহকারী            Cr দেওয়া চেক (২১১৫)
 *     ভাঙানো হলো Dr দেওয়া চেক            Cr ব্যাংক
 *     ফেরত এল   Dr দেওয়া চেক            Cr সরবরাহকারী
 *
 * ফেরত আসাটা উল্টো দাখিলা নয়, **নতুন একটা ঘটনা** — তাই আলাদা সারি।
 * উল্টে দিলে খাতায় দেখাত চেকটা কোনোদিন আসেইনি, অথচ ওটা এসেছিল, জমা
 * পড়েছিল, আর ফেরত এসেছিল। তিনটাই সত্যি, আর তিনটাই থাকা দরকার।
 */
final class ChequeService
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * একটা চেক খাতায় তোলা।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Cheque
    {
        $direction = (string) ($data['direction'] ?? Cheque::RECEIVED);

        if (! in_array($direction, [Cheque::RECEIVED, Cheque::ISSUED], true)) {
            throw ValidationException::withMessages([
                'direction' => __('accounts::validation.cheque_direction'),
            ]);
        }

        $amount = Money::round($data['amount'] ?? '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.cheque_needs_amount'),
            ]);
        }

        return DB::transaction(function () use ($data, $direction, $amount) {
            $cheque = Cheque::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('CHQ'),
                'direction' => $direction,
                'cheque_date' => $data['cheque_date'],
                'received_on' => $data['received_on'] ?? now()->toDateString(),
                'cheque_no' => trim((string) $data['cheque_no']),
                'bank_name' => $data['bank_name'] ?? null,
                'amount' => $amount,
                'party_type' => $data['party_type'] ?? null,
                'party_id' => $data['party_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'status' => Cheque::PENDING,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->post($cheque, Cheque::STOCK_SOURCE, $cheque->received_on, $direction === Cheque::RECEIVED
                ? [
                    $this->line(StandardChart::CHEQUES_IN_HAND, debit: $amount),
                    $this->partyLine($cheque, credit: $amount),
                ]
                : [
                    $this->partyLine($cheque, debit: $amount),
                    $this->line(StandardChart::CHEQUES_ISSUED, credit: $amount),
                ]);

            return $cheque->fresh();
        });
    }

    /**
     * ব্যাংকে জমা দেওয়া হলো — খাতায় টাকা নড়ে না।
     *
     * ── কেন এখানে কোনো দাখিলা নেই ───────────────────────────────────
     * চেকটা হাতে থাকুক বা ব্যাংকের কাউন্টারে, ওটা এখনো টাকা নয়। জমা
     * দেওয়া একটা **অবস্থার বদল**, হিসাবের ঘটনা নয়। দাখিলা বসালে
     * ব্যাংক ব্যালেন্স আগেই বেড়ে যেত, আর সেটাই তো সারানো হচ্ছে।
     */
    public function deposit(Cheque $cheque, ?int $bankAccountId = null, Carbon|string|null $onDate = null): Cheque
    {
        $this->assertStatus($cheque, [Cheque::PENDING]);

        $cheque->update([
            'status' => Cheque::DEPOSITED,
            'deposited_on' => $onDate ?? now()->toDateString(),
            'bank_account_id' => $bankAccountId ?? $cheque->bank_account_id,
        ]);

        return $cheque->fresh();
    }

    /**
     * পাশ হলো — এখন এটা সত্যিকারের টাকা।
     */
    public function clear(Cheque $cheque, ?int $bankAccountId = null, Carbon|string|null $onDate = null): Cheque
    {
        $this->assertStatus($cheque, [Cheque::PENDING, Cheque::DEPOSITED]);

        $bank = $this->bankFor($cheque, $bankAccountId);
        $date = $onDate ?? now()->toDateString();
        $amount = (string) $cheque->amount;

        return DB::transaction(function () use ($cheque, $bank, $date, $amount) {
            $this->post($cheque, Cheque::STOCK_SOURCE.':cleared', $date,
                $cheque->direction === Cheque::RECEIVED
                    ? [
                        ['account_id' => $bank->id, 'debit' => $amount],
                        $this->line(StandardChart::CHEQUES_IN_HAND, credit: $amount),
                    ]
                    : [
                        $this->line(StandardChart::CHEQUES_ISSUED, debit: $amount),
                        ['account_id' => $bank->id, 'credit' => $amount],
                    ]);

            $cheque->update([
                'status' => Cheque::CLEARED,
                'cleared_on' => $date,
                'bank_account_id' => $bank->id,
            ]);

            return $cheque->fresh();
        });
    }

    /**
     * ফেরত এল।
     *
     * ── কেন কারণ বাধ্যতামূলক ────────────────────────────────────────
     * "তহবিল নেই" আর "সই মেলেনি" দুইটা আলাদা কথা: প্রথমটা ডিলারের
     * সাথে সম্পর্কের প্রশ্ন, দ্বিতীয়টা কেবল একটা ভুল। ছয় মাস পরে
     * কোনটা ঘটেছিল সেটা কেবল এখানেই লেখা থাকে।
     */
    public function bounce(Cheque $cheque, string $reason, Carbon|string|null $onDate = null): Cheque
    {
        $this->assertStatus($cheque, [Cheque::PENDING, Cheque::DEPOSITED]);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'bounce_reason' => __('accounts::validation.bounce_needs_reason'),
            ]);
        }

        $date = $onDate ?? now()->toDateString();
        $amount = (string) $cheque->amount;

        return DB::transaction(function () use ($cheque, $reason, $date, $amount) {
            $this->post($cheque, Cheque::STOCK_SOURCE.':bounced', $date,
                $cheque->direction === Cheque::RECEIVED
                    ? [
                        $this->partyLine($cheque, debit: $amount, narration: $reason),
                        $this->line(StandardChart::CHEQUES_IN_HAND, credit: $amount),
                    ]
                    : [
                        $this->line(StandardChart::CHEQUES_ISSUED, debit: $amount),
                        $this->partyLine($cheque, credit: $amount, narration: $reason),
                    ]);

            $cheque->update([
                'status' => Cheque::BOUNCED,
                'bounce_reason' => $reason,
                'cleared_on' => null,
            ]);

            return $cheque->fresh();
        });
    }

    /**
     * বাতিল — ছেঁড়া হয়েছে বা বদলে দেওয়া হয়েছে।
     *
     * হিসাবের দিক থেকে এটা ফেরত আসার মতোই: দায়টা মুছে যায়, আর পক্ষের
     * হিসাব আগের জায়গায় ফেরে।
     */
    public function cancel(Cheque $cheque, string $reason, Carbon|string|null $onDate = null): Cheque
    {
        $cancelled = $this->bounce($cheque, $reason, $onDate);

        $cancelled->update(['status' => Cheque::CANCELLED]);

        return $cancelled->fresh();
    }

    /**
     * ব্যাংকের খাতটা — বলা থাকলে সেটা, নইলে চেকের নিজেরটা।
     *
     * কোনোটাই না থাকলে থেমে যাওয়া হয়: অনুমান করে প্রধান ব্যাংকে
     * বসালে টাকাটা ভুল হিসাবে ঢুকত, আর ব্যাংক-মিলকরণে ধরা পড়ত মাস
     * শেষে।
     */
    private function bankFor(Cheque $cheque, ?int $bankAccountId): Account
    {
        $id = $bankAccountId ?? $cheque->bank_account_id;

        $bank = $id === null ? null : Account::query()->find($id);

        if ($bank === null) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('accounts::validation.cheque_needs_bank'),
            ]);
        }

        return $bank;
    }

    /** @return array<string, mixed> */
    private function line(string $code, ?string $debit = null, ?string $credit = null): array
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('accounts::validation.chart_not_installed'),
            ]);
        }

        return array_filter([
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
        ], fn ($value) => $value !== null);
    }

    /**
     * পক্ষের লাইন — গৃহীত চেকে প্রাপ্য, ইস্যু করা চেকে প্রদেয়।
     *
     * @return array<string, mixed>
     */
    private function partyLine(Cheque $cheque, ?string $debit = null, ?string $credit = null, ?string $narration = null): array
    {
        $code = $cheque->direction === Cheque::RECEIVED
            ? StandardChart::RECEIVABLE
            : StandardChart::PAYABLE;

        return array_filter([
            ...$this->line($code, $debit, $credit),
            'party_type' => $cheque->party_type,
            'party_id' => $cheque->party_id,
            'narration' => $narration ?? $cheque->narration,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(Cheque $cheque, string $sourceType, Carbon|string $date, array $lines): void
    {
        $this->posting->post(
            sourceType: $sourceType,
            sourceId: $cheque->id,
            trxDate: $date,
            documentNo: $cheque->document_no,
            lines: $lines,
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(Cheque $cheque, array $allowed): void
    {
        if (! in_array($cheque->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cheque_already_decided', [
                    'no' => $cheque->cheque_no,
                ]),
            ]);
        }
    }
}
