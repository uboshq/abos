<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PartyRegistry;
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
        private readonly NoteAccounts $accounts,
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
        [$kind, $controlId, $otherId] = $this->resolve($direction, $data);

        $amount = $this->money($data['amount'] ?? '0');
        $tax = $this->money($data['tax_amount'] ?? '0');

        // ⓘ ভ্যাট কেবল কেনা-বেচার পক্ষে (গ্রাহক, সরবরাহকারী) — সেবাদাতা আর ব্যক্তির সমন্বয়ে ভ্যাটের খাত নেই
        if (bccomp($tax, '0', 4) > 0 && ! in_array($kind, [Note::KIND_CUSTOMER, Note::KIND_SUPPLIER], true)) {
            throw ValidationException::withMessages(['tax_amount' => __('accounts::note.tax_only_trade')]);
        }

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::note.amount_must_be_positive'),
            ]);
        }

        return DB::transaction(function () use ($data, $direction, $amount, $tax, $kind, $controlId, $otherId) {
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
                'party_type' => Note::KINDS[$kind],
                'party_id' => (int) $data['party_id'],
                'party_kind' => $kind,
                'control_account_id' => $controlId,
                'other_account_id' => $otherId,
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
     * ⭐ পক্ষের ধরন আর দুই খাত — মালিক, ৩ অক্টোবর ২০২৬: *"সব পক্ষেই ডেবিট ক্রেডিট হয়, দুই পক্ষেরই লাগে"*।
     *
     * ⓘ ধরন না দিলে আগের নিয়ম — ক্রেডিট গ্রাহকের, ডেবিট সরবরাহকারীর (পুরনো ফর্ম আর API)। পক্ষটা সত্যিই ঐ ধরনের
     * আর এই কোম্পানির ([[NoteAccounts::kindMatches()]]); খাত না দিলে একমাত্র চলতি খাতটা, একাধিক হলে বাছতে বলে।
     * ⛔ তালিকার বাইরের খাত কখনো নয় — ফর্ম এড়িয়ে পাঠালেও।
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int, 2: int}
     */
    private function resolve(string $direction, array $data): array
    {
        $kind = (string) ($data['party_kind'] ?? '');

        if ($kind === '') {
            $kind = $direction === Note::CREDIT ? Note::KIND_CUSTOMER : Note::KIND_SUPPLIER;
        }

        $partyId = (int) ($data['party_id'] ?? 0);

        if (! array_key_exists($kind, Note::KINDS) || ! $this->accounts->kindMatches($kind, $partyId)
            || (filled($data['party_type'] ?? null) && $data['party_type'] !== Note::KINDS[$kind])) {
            throw ValidationException::withMessages(['party_id' => __('accounts::note.party_not_found')]);
        }

        $controls = $this->accounts->controls($kind, $partyId);

        if ($controls->isEmpty()) {
            throw ValidationException::withMessages(['control_account_id' => __('accounts::note.control_none')]);
        }

        $controlId = (int) ($data['control_account_id'] ?? 0) ?: (int) $this->accounts->defaultControl($kind, $partyId);

        if ($controlId === 0) {
            throw ValidationException::withMessages(['control_account_id' => __('accounts::note.control_choose')]);
        }

        if (! $controls->contains('id', $controlId)) {
            throw ValidationException::withMessages(['control_account_id' => __('accounts::note.account_not_allowed')]);
        }

        $otherId = (int) ($data['other_account_id'] ?? 0)
            ?: (in_array($kind, [Note::KIND_CUSTOMER, Note::KIND_SUPPLIER], true) ? (int) $this->accounts->defaultOther($kind, $direction, $partyId) : 0);

        if ($otherId === 0) {
            throw ValidationException::withMessages(['other_account_id' => __('accounts::note.other_choose')]);
        }

        if (! $this->accounts->others($kind, $direction)->contains('id', $otherId)) {
            throw ValidationException::withMessages(['other_account_id' => __('accounts::note.account_not_allowed')]);
        }

        return [$kind, $controlId, $otherId];
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
                lines: $this->lines($note),
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
     * দাখিলা — এক নিয়ম, সব পক্ষে (সমন্বয়কের ঠিক করা হিসাব, ৩ অক্টোবর ২০২৬; [[NoteAccounts]])।
     *
     *   ক্রেডিট নোট:  Dr অন্য পাশ (টাকা) · Dr ভ্যাট (থাকলে)  /  Cr পক্ষের খাত (মোট, পক্ষসহ)
     *   ডেবিট নোট:    Dr পক্ষের খাত (মোট, পক্ষসহ)  /  Cr অন্য পাশ (টাকা) · Cr ভ্যাট (থাকলে)
     *
     * ⓘ পুরনো দুই পথ (গ্রাহকের ক্রেডিট, সরবরাহকারীর ডেবিট) হুবহু এর ভেতরেই — একই খাত, একই সারি।
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Note $note): array
    {
        ['control' => $control, 'other' => $other] = $this->accounts->of($note);
        $hasTax = bccomp((string) $note->tax_amount, '0', 4) > 0;
        $side = $note->isCredit() ? 'debit' : 'credit';
        $flip = $note->isCredit() ? 'credit' : 'debit';

        $party = [
            'account_id' => $control->id,
            $flip => (string) $note->total,
            'party_type' => $note->party_type,
            'party_id' => $note->party_id,
            'narration' => $note->narration,
        ];

        $lines = [['account_id' => $other->id, $side => (string) $note->amount, 'narration' => $note->narration]];

        if ($hasTax) {
            $lines[] = ['account_id' => $this->account(StandardChart::VAT_PAYABLE)->id, $side => (string) $note->tax_amount, 'narration' => $note->narration];
        }

        return $note->isCredit() ? [...$lines, $party] : [$party, ...$lines];
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
