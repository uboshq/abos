<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ডেবিট ও ক্রেডিট নোট — মানচিত্র §৭।
 *
 * ── ⚠️ হিসাবের দিকটা ফেরতের কাগজের হুবহু, কেবল মাল ছাড়া ────────────
 * ইচ্ছাকৃতভাবে: বিক্রয় ফেরত আর ক্রেডিট নোট বইয়ে একই জিনিস বলে — গ্রাহকের
 * কাছে আমাদের পাওনা কমল। ⓘ পার্থক্য কেবল গুদামে, আর নোটে গুদাম ছোঁয়া
 * হয় না। ⛔ দুইটা আলাদা হিসাবের ছক বানালে একই ঘটনা দুই খাতে বসত, আর
 * বছর শেষে "বিক্রয় ফেরত" সংখ্যাটা অর্ধেক সত্য বলত।
 */
final class NoteService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * খসড়া নোট — বইয়ে কিছুই যায় না।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Note
    {
        $direction = (string) $data['direction'];

        $this->assertDirection($direction);

        $amount = $this->money($data['amount'] ?? '0');
        $tax = $this->money($data['tax_amount'] ?? '0');

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::note.amount_must_be_positive'),
            ]);
        }

        return DB::transaction(function () use ($data, $direction, $amount, $tax) {
            /*
             * ⚠️ নম্বরটা লেনদেনের **ভিতরে** নেওয়া হয় — বাইরে নিলে সেভ
             * ভেঙে গেলে নম্বরটা পুড়ে যেত, আর ক্রমে একটা ফাঁক থাকত যার
             * কোনো ব্যাখ্যা নেই ([[NumberSeriesEngine::next()]])।
             */
            $documentNo = $this->numbers->next($direction === Note::CREDIT ? 'CN' : 'DN');

            $note = Note::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $documentNo,
                'trx_date' => $data['trx_date'],
                'direction' => $direction,
                'party_type' => $data['party_type'],
                'party_id' => (int) $data['party_id'],
                'against_type' => ($data['against_type'] ?? '') ?: null,
                'against_id' => ($data['against_id'] ?? null) ?: null,
                'against_no' => ($data['against_no'] ?? '') ?: null,
                'amount' => $amount,
                'tax_amount' => $tax,
                'total' => bcadd($amount, $tax, 4),
                'reason' => $data['reason'],
                'narration' => ($data['narration'] ?? '') ?: null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update(['source_type' => $note->sourceType(), 'source_id' => $note->id]);

            return $note;
        });
    }

    /**
     * ⭐ নোটটা বইয়ে বসে।
     *
     * ── দুই দিকের দুই দাখিলা ────────────────────────────────────────
     * **ক্রেডিট নোট** (গ্রাহককে):
     *   Dr বিক্রয় ফেরত ৪১১০ · Dr ভ্যাট ২১২০ / Cr প্রাপ্য ১১১০ (গ্রাহক)
     *
     * **ডেবিট নোট** (সরবরাহকারীকে):
     *   Dr প্রদেয় ২১১১ (সরবরাহকারী) / Cr ক্রয়ের দামের ফারাক ৫১৫০ · Cr ভ্যাট ২১২০
     *
     * ⓘ দুইটাই ঐ মডিউলের ফেরতের কাগজ যা করে তার হুবহু — কেবল মালের
     * সারিগুলো নেই, কারণ মাল নড়েনি।
     */
    public function confirm(Note $note): Note
    {
        if (! $note->isDraft()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::note.only_a_draft_can_be_confirmed'),
            ]);
        }

        return DB::transaction(function () use ($note) {
            $this->posting->post(
                sourceType: $note->sourceType(),
                sourceId: $note->id,
                trxDate: $note->trx_date,
                lines: $note->isCredit() ? $this->creditLines($note) : $this->debitLines($note),
                documentNo: $note->document_no,
                branchId: $note->branch_id,
            );

            $note->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => Carbon::now(),
            ])->save();

            return $note->refresh();
        });
    }

    /**
     * বাতিল — বসে যাওয়া দাখিলা ফিরিয়ে নেওয়া হয়।
     *
     * ⚠️ কারণ বাধ্যতামূলক: কারণ ছাড়া বাতিল করা কাগজ পরে কেউ ব্যাখ্যা
     * করতে পারে না, আর নোট এমনিতেই একটা ব্যাখ্যার কাগজ।
     */
    public function cancel(Note $note, string $reason): Note
    {
        if ($note->isCancelled()) {
            return $note;
        }

        return DB::transaction(function () use ($note, $reason) {
            /*
             * ⓘ ফেরানোর তারিখ **আজ**, কাগজের তারিখ নয় — বন্ধ হয়ে যাওয়া
             * মাসে ফিরিয়ে নিলে ঐ মাসের বন্ধ করা হিসাব বদলে যেত।
             */
            if ($note->isConfirmed()) {
                $this->posting->reverse(
                    sourceType: $note->sourceType(),
                    sourceId: $note->id,
                    reversalDate: Carbon::today(),
                    reason: $reason,
                );
            }

            $note->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => Carbon::now(),
            ])->save();

            return $note->refresh();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function creditLines(Note $note): array
    {
        $lines = [[
            'account_id' => $this->account(StandardChart::SALES_RETURN)->id,
            'debit' => (string) $note->amount,
            'narration' => $note->narration,
        ]];

        if (bccomp((string) $note->tax_amount, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'debit' => (string) $note->tax_amount,
                'narration' => $note->narration,
            ];
        }

        $lines[] = [
            'account_id' => $this->account(StandardChart::RECEIVABLE)->id,
            'credit' => (string) $note->total,
            'party_type' => $note->party_type,
            'party_id' => $note->party_id,
            'narration' => $note->narration,
        ];

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function debitLines(Note $note): array
    {
        $lines = [[
            'account_id' => $this->account(StandardChart::PAYABLE)->id,
            'debit' => (string) $note->total,
            'party_type' => $note->party_type,
            'party_id' => $note->party_id,
            'narration' => $note->narration,
        ]];

        $lines[] = [
            'account_id' => $this->account(StandardChart::PURCHASE_PRICE_VARIANCE)->id,
            'credit' => (string) $note->amount,
            'narration' => $note->narration,
        ];

        if (bccomp((string) $note->tax_amount, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->account(StandardChart::VAT_PAYABLE)->id,
                'credit' => (string) $note->tax_amount,
                'narration' => $note->narration,
            ];
        }

        return $lines;
    }

    /**
     * ⚠️ খাতটা কোড ধরে খোঁজা হয়, id হাতে লেখা হয় না — প্রতিটা কোম্পানির
     * ছকে একই খাতের id আলাদা।
     */
    private function account(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account' => __('accounts::note.missing_account', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function assertDirection(string $direction): void
    {
        if (! in_array($direction, Note::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => __('accounts::note.unknown_direction'),
            ]);
        }
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value === '' ? '0' : $value), '0', 4);
    }
}
