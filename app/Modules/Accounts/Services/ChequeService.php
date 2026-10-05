<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Events\ChequeCleared;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
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
 *   গৃহীত চেক — ⭐ মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬: *"ক্লিয়ারিং এর পরে
 *   একাউন্টে জমা হলে তার পর"*
 *     হাতে এল          কেবল রেজিস্টারে — খাতায় কিছু নয়
 *     পাশ হলো          Dr ব্যাংক               Cr ডিলার
 *     পাশের আগে ফেরত   কেবল অবস্থা — উল্টানোর কিছু নেই
 *     পাশের পরে ফেরত   Dr ডিলার                Cr ব্যাংক
 *
 *   ⚠️ আগের নিয়মে তোলা চেক ([[receivedIntoTheBooks()]]) হাতে আসার দিনেই
 *   Dr ১১০৪ / Cr ডিলার পেয়েছে, আর নিজের পুরনো পথেই শেষ হয় — পাশ হলে
 *   Dr ব্যাংক / Cr ১১০৪, ফেরত এলে Dr ডিলার / Cr ১১০৪। ⛔ নতুন পথে
 *   পাঠালে পাশের দিন ডিলারের বকেয়া দ্বিতীয়বার কমত।
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
        private readonly VoucherService $vouchers,
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

        /*
         * ⛔ গৃহীত চেক কার, তা না জানলে পাশের দিনের Cr কারও নামে বসত না —
         * টাকা এসেছে, অথচ কারও বকেয়া কমেনি।
         */
        if ($direction === Cheque::RECEIVED && (blank($data['party_type'] ?? null) || blank($data['party_id'] ?? null))) {
            throw ValidationException::withMessages([
                'party' => __('accounts::validation.cheque_needs_party'),
            ]);
        }

        /*
         * ⛔ পক্ষটা সত্যিই আছে, এই কোম্পানিতে, আর চেনা ধরনের — Accounts-Finance অডিট ম১২, ৪ অক্টোবর ২০২৬।
         * ⚠️ আগে যা আসত তাই বসত: অন্য কোম্পানির গ্রাহকের id বা না-থাকা কারো নামে চেক লেখা যেত, আর পাশের দিন টাকা
         * সেই নামে বসত — নিজের গ্রাহকের বকেয়া কমত না।
         */
        if (filled($data['party_type'] ?? null) || filled($data['party_id'] ?? null)) {
            $parties = app(PartyRegistry::class);

            if (! $parties->knows((string) ($data['party_type'] ?? '')) || ! $parties->exists((string) $data['party_type'], (int) ($data['party_id'] ?? 0))) {
                throw ValidationException::withMessages([
                    'party' => __('accounts::validation.party_unknown'),
                ]);
            }
        }

        return DB::transaction(function () use ($data, $direction, $amount) {
            $this->assertNotAlreadyRegistered($direction, $data);

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

            /*
             * ⭐ গৃহীত চেক হাতে আসার দিন কেবল রেজিস্টারে — মালিকের নিয়ম,
             * ২৬ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ আগে এখানে Dr ১১০৪ / Cr ডিলার বসত, আর ডিলারের বকেয়া ও
             * বাকির সীমা সেদিনই খুলে যেত — ফেরত আসতে পারে এমন কাগজে।
             * ⓘ দেওয়া চেকের দায় আগের মতোই দেওয়ার দিন।
             */
            if ($direction === Cheque::ISSUED) {
                $this->post($cheque, Cheque::STOCK_SOURCE, $cheque->received_on, [
                    $this->partyLine($cheque, debit: $amount),
                    $this->line(StandardChart::CHEQUES_ISSUED, credit: $amount),
                ]);
            }

            return $cheque->fresh();
        });
    }

    /**
     * চেক কেবল রেজিস্টারে তোলা — কোনো দাখিলা নয়।
     *
     * ── কেন পোস্ট করে না ────────────────────────────────────────────────
     * কাউন্টারে গ্রাহকের চেক নিলে টাকার দাখিলাটা **আদায়ের কাগজ** করে
     * (Dr ১১০৪ / Cr গ্রাহক + CollectionLine, যা থেকে বিলের বকেয়া গোনা হয়)।
     * তাই এখানে আবার পোস্ট করলে টাকা দ্বিগুণ বসত। এই সারিটা শুধু চেকের
     * **জীবন** রাখে — পাশ, ফেরত, PDC রিপোর্ট, আর একই চেক দুইবার নয়।
     *
     * `collection_id` ভরা থাকে বলেই [[Cheque::postedByCollection()]] সত্য,
     * আর [[bounce()]] তখন নিজের পোস্টিং এড়িয়ে যায়।
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): Cheque
    {
        $amount = Money::round($data['amount'] ?? '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.cheque_needs_amount'),
            ]);
        }

        $chequeNo = trim((string) ($data['cheque_no'] ?? ''));

        if ($chequeNo === '' || ($data['cheque_date'] ?? null) === null) {
            throw ValidationException::withMessages([
                'cheque_no' => __('accounts::validation.cheque_needs_no'),
            ]);
        }

        $bankName = $data['bank_name'] ?? null;

        /*
         * ⭐ দিকটা এখন বলে দেওয়া যায় — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ কাউন্টারে গ্রাহকের চেক **নেওয়া** হয়, আর ক্রয়ের কাউন্টারে
         * সরবরাহকারীকে চেক **দেওয়া** হয় — দুইটাই রেজিস্টারে ওঠে, টাকা
         * পোস্ট করে ভাউচার। ⚠️ ডিফল্ট আগের মতোই "নেওয়া", তাই পুরনো
         * প্রতিটা ডাক অবিকল আগের মতো চলে।
         */
        $direction = ($data['direction'] ?? null) === Cheque::ISSUED
            ? Cheque::ISSUED
            : Cheque::RECEIVED;

        // একই চেক দুইবার নয় — DB-র unique পাহারার আগে বোধগম্য বার্তা
        $exists = Cheque::query()
            ->where('direction', $direction)
            ->where('bank_name', $bankName)
            ->where('cheque_no', $chequeNo)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'cheque_no' => __('accounts::validation.cheque_duplicate', ['no' => $chequeNo]),
            ]);
        }

        return DB::transaction(fn (): Cheque => Cheque::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_no' => $this->numbers->next('CHQ'),
            'direction' => $direction,
            'cheque_date' => $data['cheque_date'],
            'received_on' => $data['received_on'] ?? now()->toDateString(),
            'cheque_no' => $chequeNo,
            'bank_name' => $bankName,
            'amount' => $amount,
            'party_type' => $data['party_type'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'collection_id' => $data['collection_id'] ?? null,
            // ⓘ কাউন্টারের চেক, ১৯ সেপ্টেম্বর থেকে — টাকা পোস্ট করেছে এই রসিদ ভাউচার
            'voucher_id' => $data['voucher_id'] ?? null,
            'status' => Cheque::PENDING,
            'narration' => $data['narration'] ?? null,
            'created_by' => auth()->id(),
        ]))->fresh();
    }

    /**
     * ফেরত এসেছে বলে চিহ্ন — কিন্তু কোনো দাখিলা নয়।
     *
     * ── কেন এটা আলাদা, [[bounce()]] নয় ─────────────────────────────────
     * আদায়ের কাগজে পোস্ট হওয়া চেকের টাকা ফেরে **আদায় বাতিলে** (Sales),
     * এখানে নয়। তাই এই পদ্ধতিটা কেবল অবস্থাটা বসায় — টাকা যে পক্ষ পোস্ট
     * করেছে সে-ই ফেরাবে। ক্রয়ের চেকে (collection_id খালি) `bounce()`
     * ব্যবহার হয়, যা নিজেই পোস্ট করে।
     */
    public function markBounced(Cheque $cheque, string $reason): Cheque
    {
        /*
         * ⛔ কেবল আদায়ের কাগজের চেক। নিচের পাশ-ফেরত ধরে নেয় টাকাটা ১১০৪
         * হয়ে এসেছিল — নতুন নিয়মের বা দেওয়া চেকে ডাকলে ১১০৪ ভুল হয়ে যেত।
         */
        if (! $cheque->postedByCollection()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cheque_not_from_collection', ['no' => $cheque->cheque_no]),
            ]);
        }

        $this->assertStatus($cheque, [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED]);

        return DB::transaction(function () use ($cheque, $reason) {
            $this->lockedFresh($cheque, [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED]);

            /*
             * ⚠️ পাশ হয়ে থাকলে ব্যাংকের টাকা আগে ১১০৪-এ ফেরে; আদায় বাতিল
             * তারপর ১১০৪ থেকে গ্রাহকে ফেরায়। নাহলে ব্যাংকে এমন টাকা থেকে
             * যেত যা ব্যাংক ফিরিয়ে নিয়েছে।
             */
            if ($cheque->status === Cheque::CLEARED) {
                $this->returnToHand($cheque, now()->toDateString(), $reason);
            }

            return $this->markAs($cheque, Cheque::BOUNCED, $reason);
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

        $bankAccountId ??= $cheque->bank_account_id;
        $date = $onDate ?? now()->toDateString();

        /*
         * ⭐ মালিকের হিসাবের নিয়ম, ২৭ সেপ্টেম্বর ২০২৬ — চেক জমা পড়ে কেবল
         * ব্যাংকে, আর নিজের তারিখের আগে নয়।
         *
         * ⛔ আগে যেকোনো খাত নেওয়া হত — নগদ বাক্স বা বিকাশে "জমা" লেখা
         * থাকলে পাশের দিন টাকাটা সেখানেই বসত। ⓘ খাত না বলা আগের মতোই
         * চলে; পাশের দিন [[bankFor()]] তখন ব্যাংক চায়।
         */
        if ($bankAccountId !== null) {
            $this->mustBeABank(Account::query()->find($bankAccountId));
        }

        $this->mustBeDue($cheque, $date, 'deposited_on');

        $cheque->update([
            'status' => Cheque::DEPOSITED,
            'deposited_on' => $date,
            'bank_account_id' => $bankAccountId,
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

        /*
         * ⭐ মালিকের হিসাবের নিয়ম, ২৭ সেপ্টেম্বর ২০২৬ — গৃহীত ও দেওয়া
         * দুই চেকই পাশ হয় কেবল ব্যাংকে, আর নিজের তারিখের আগে নয়।
         *
         * ⛔ আগে নগদ বাক্স বা বিকাশেও "পাশ" বসত — বাক্সে এমন টাকা দেখাত যা
         * কোনো ক্যাশিয়ার গোনেনি। আর আগাম তারিখের চেক আগেই পাশ বসালে
         * ডিলারের বকেয়া আগেভাগে কমত।
         *
         * ⚠️ পাহারা এখানে, [[bankFor()]]-এ নয় — ফেরতের পথও ওটা ডাকে, আর
         * আগে ভুল খাতে পাশ হওয়া পুরনো চেক যেন ফেরত আটকে না যায়।
         */
        $this->mustBeABank($bank);
        $this->mustBeDue($cheque, $date, 'cleared_on');

        $amount = (string) $cheque->amount;

        /*
         * ⭐ সই — গ১, Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬ ([[AccountsSignature]])।
         *
         * ⛔ আগে চেক পাশ সই ছাড়াই ব্যাংকে টাকা বসাত। ⓘ থামলে চেকটা যেমন ছিল তেমনই থাকে; শেষ সই পড়লে
         * [[FinishTheAccountsPaperOnTheLastSignature]] এই মেথডটাই আবার ডাকে।
         *
         * ⚠️ বাছা ব্যাংক সই চাওয়ার **আগে** চেকে বসে — সইয়ের ছাপ কাগজের তখনকার চেহারার; পরে বদলালে ছাপ মিলত না আর
         * শেষ সইয়ের পরে আবার সই চাওয়া হত।
         */
        if ((int) $cheque->bank_account_id !== (int) $bank->id) {
            $cheque->update(['bank_account_id' => $bank->id]);
        }

        if (app(AccountsSignature::class)->holds($cheque, AccountsSignature::CHEQUE_CLEAR, $amount)) {
            return $cheque->fresh();
        }

        return DB::transaction(function () use ($cheque, $bank, $date, $amount) {
            // ⛔ তালা দিয়ে অবস্থা আবার — একই মুহূর্তে "ফেরত" এলে দুটোই "জমা" দেখত (অডিট ম১০)
            $this->lockedFresh($cheque, [Cheque::PENDING, Cheque::DEPOSITED]);

            /*
             * ⭐ নতুন চেকে পাশের দিনই ডিলারের বকেয়া কমে — এর আগে খাতায়
             * কিছুই ছিল না। ⚠️ পুরনো চেক আগেই Cr ডিলার পেয়েছে, তাই সে
             * কেবল ১১০৪ খালি করে; নাহলে বকেয়া দুইবার কমত।
             */
            $this->post($cheque, Cheque::STOCK_SOURCE.':cleared', $date,
                $cheque->direction === Cheque::RECEIVED
                    ? [
                        ['account_id' => $bank->id, 'debit' => $amount],
                        $this->receivedIntoTheBooks($cheque)
                            ? $this->line(StandardChart::CHEQUES_IN_HAND, credit: $amount)
                            : $this->partyLine($cheque, credit: $amount),
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

            // ⓘ অন্য মডিউলকে জানানো — লেনদেন পাকা হওয়ার পরে; টাকার জন্য আটকে থাকা DO আবার যাচাই হয় ([[ChequeCleared]])
            DB::afterCommit(fn () => event(ChequeCleared::from($cheque->fresh())));

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
        /*
         * ⚠️ আদায়ের কাগজ যে চেকের টাকা পোস্ট করেছে, তার ফেরত এখানে নয়।
         *
         * ওই টাকা ফেরে আদায় বাতিলে (Sales) — Dr গ্রাহক / Cr ১১০৪ + বিলের
         * বকেয়া ফেরে। এখানে আবার পোস্ট করলে টাকা **দ্বিগুণ** কাটত। তাই
         * চিহ্নটা (collection_id) দেখে সরাসরি থামানো — শর্তটা ডেটায়, মনে
         * রাখার উপর নয়। status বসানো হয় [[markBounced()]] দিয়ে, Sales থেকে।
         */
        if ($cheque->postedByCollection()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cheque_bounce_via_receipt', ['no' => $cheque->document_no]),
            ]);
        }

        /*
         * ⓘ গৃহীত চেক পাশের পরেও ফেরত আসতে পারে — ব্যাংক টাকা দিয়ে পরে
         * ফিরিয়ে নেয়। দেওয়া চেকে "পাশের পরে ফেরত" বলে কিছু নেই।
         */
        $this->assertStatus($cheque, $cheque->direction === Cheque::RECEIVED
            ? [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED]
            : [Cheque::PENDING, Cheque::DEPOSITED]);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'bounce_reason' => __('accounts::validation.bounce_needs_reason'),
            ]);
        }

        $date = $onDate ?? now()->toDateString();
        $amount = (string) $cheque->amount;

        /*
         * ⭐ সই — গ১ ([[AccountsSignature]])। ⓘ ফেরতের কারণটাই সইয়ের অনুরোধের কারণ; শেষ সই পড়লে
         * [[FinishTheAccountsPaperOnTheLastSignature]] ঐ কারণ নিয়েই এই মেথড আবার ডাকে।
         */
        if (app(AccountsSignature::class)->holds($cheque, AccountsSignature::CHEQUE_BOUNCE, $amount, $reason)) {
            return $cheque->fresh();
        }

        if ($cheque->direction === Cheque::RECEIVED && ! $this->receivedIntoTheBooks($cheque)) {
            return DB::transaction(function () use ($cheque, $reason, $date, $amount) {
                // ⛔ তালা দিয়ে অবস্থা আবার — একই মুহূর্তে "পাশ" হলে এখানে তা দেখা যায়, আর পাশের টাকাও ফেরে (অডিট ম১০)
                $this->lockedFresh($cheque, [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED]);

                /*
                 * ⭐ নতুন নিয়মের চেক: পাশের আগে খাতায় কিছুই ছিল না, তাই
                 * উল্টানোরও কিছু নেই — কেবল অবস্থা।
                 *
                 * ⓘ পাশের পরে ফেরত এলে ব্যাংক থেকে টাকা যায় আর ডিলারের
                 * বকেয়া ফেরে — **নতুন ঘটনা**, পাশের দাখিলার বাতিল নয়।
                 */
                if ($cheque->status === Cheque::CLEARED) {
                    $this->post($cheque, Cheque::STOCK_SOURCE.':bounced', $date, [
                        $this->partyLine($cheque, debit: $amount, narration: $reason),
                        ['account_id' => $this->bankFor($cheque, null)->id, 'credit' => $amount],
                    ]);
                }

                return $this->markAs($cheque, Cheque::BOUNCED, $reason);
            });
        }

        /*
         * ⚠️ পুরনো চেক পাশ হয়ে থাকলে আগে ব্যাংকের টাকা ১১০৪-এ ফেরে, তারপর
         * নিচের পুরনো পথ ১১০৪ থেকে ডিলারে ফেরায়। নিট ফল নতুন চেকের মতোই:
         * Dr ডিলার / Cr ব্যাংক।
         */
        return DB::transaction(function () use ($cheque, $reason, $date, $amount) {
            $this->lockedFresh($cheque, $cheque->direction === Cheque::RECEIVED
                ? [Cheque::PENDING, Cheque::DEPOSITED, Cheque::CLEARED]
                : [Cheque::PENDING, Cheque::DEPOSITED]);

            if ($cheque->direction === Cheque::RECEIVED && $cheque->status === Cheque::CLEARED) {
                $this->returnToHand($cheque, $date, $reason);
            }

            /*
             * ⭐ রসিদ ভাউচার যে চেকের টাকা তুলেছে — ভাউচারটাই বাতিল (১৯ সেপ্টেম্বর ২০২৬)।
             *
             * ⓘ কাউন্টারের ডিপোজিট এখন রসিদ ভাউচার (Dr ১১০৪ / Cr গ্রাহক)। বাতিলের
             * উল্টো দাখিলা ঠিক সেটাই ফেরায়: ১১০৪ খালি, গ্রাহকের খাতায় টাকাটা আবার
             * পাওনা। ⛔ এখানে নিজের দাখিলাও বসালে টাকা **দ্বিগুণ** কাটত — ঠিক
             * আদায়ের কাগজের চেকের মতো ফাঁদ।
             */
            if ($cheque->postedByVoucher()) {
                $voucher = Voucher::query()->findOrFail($cheque->voucher_id);

                if (! $voucher->isCancelled()) {
                    $this->vouchers->cancel($voucher, $reason,
                        $date instanceof Carbon ? $date->toDateString() : (string) $date);
                }

                return $this->markAs($cheque, Cheque::BOUNCED, $reason);
            }

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

            return $this->markAs($cheque, Cheque::BOUNCED, $reason);
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
        /*
         * ⓘ পাশ হওয়া চেক ছেঁড়া যায় না — টাকা এসে গেছে। ব্যাংক ফিরিয়ে
         * নিলে সেটা ফেরত ([[bounce()]]), বাতিল নয়।
         */
        if ($cheque->status === Cheque::CLEARED) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cleared_cheque_not_cancelled', ['no' => $cheque->cheque_no]),
            ]);
        }

        $cancelled = $this->bounce($cheque, $reason, $onDate);

        $cancelled->update(['status' => Cheque::CANCELLED]);

        return $cancelled->fresh();
    }

    /**
     * এই গৃহীত চেক কি আগের নিয়মে হাতে আসার দিনেই খাতায় উঠেছিল?
     *
     * ⓘ কোনো কলাম নয়, ডেটাই উত্তর দেয় — তিনটা পথের যেকোনোটা: চেকের নিজের
     * `cheque` দাখিলা (আগের [[create()]]), কাউন্টারের রসিদ ভাউচার, বা
     * আদায়ের কাগজ। ⭐ তাই লাইভে আগে থেকে খোলা চেকের জন্য মাইগ্রেশন
     * লাগে না; সেগুলো নিজের পুরনো পথেই শেষ হয়।
     */
    private function receivedIntoTheBooks(Cheque $cheque): bool
    {
        if ($cheque->postedByVoucher() || $cheque->postedByCollection()) {
            return true;
        }

        return LedgerEntry::query()
            ->where('source_type', Cheque::STOCK_SOURCE)
            ->where('source_id', $cheque->id)
            ->exists();
    }

    /**
     * পাশ হওয়া পুরনো চেক ফেরত — ব্যাংকের টাকা ১১০৪-এ ফেরে।
     *
     * ⓘ পুরনো চেকের পাশ ছিল Dr ব্যাংক / Cr ১১০৪; এটা তার বিপরীত ঘটনা।
     * এরপর পুরনো ফেরতের পথ (নিজের দাখিলা, ভাউচার বাতিল বা আদায় বাতিল)
     * ১১০৪ থেকে গ্রাহকের কাছে ফেরায়।
     */
    private function returnToHand(Cheque $cheque, Carbon|string $date, string $reason): void
    {
        $amount = (string) $cheque->amount;

        $this->post($cheque, Cheque::STOCK_SOURCE.':returned', $date, [
            [...$this->line(StandardChart::CHEQUES_IN_HAND, debit: $amount), 'narration' => $reason],
            ['account_id' => $this->bankFor($cheque, null)->id, 'credit' => $amount, 'narration' => $reason],
        ]);
    }

    private function markAs(Cheque $cheque, string $status, string $reason): Cheque
    {
        $cheque->update([
            'status' => $status,
            'bounce_reason' => $reason,
            'cleared_on' => null,
        ]);

        return $cheque->fresh();
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

    /**
     * চেকের টাকা নামে কেবল ব্যাংক হিসাবে।
     *
     * ⓘ খাতটা না পেলে আগের বার্তা ("কোন ব্যাংক"); পেলে, কিন্তু ব্যাংক
     * না হলে, নতুন বার্তা — নগদ বাক্স আর বিকাশে চেক জমা পড়ে না।
     */
    private function mustBeABank(?Account $account): void
    {
        if ($account === null) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('accounts::validation.cheque_needs_bank'),
            ]);
        }

        if (! $account->isBank()) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('accounts::validation.cheque_needs_a_bank_account', [
                    'account' => $account->label(),
                ]),
            ]);
        }
    }

    /**
     * আগাম তারিখের চেক নিজের তারিখের আগে টাকা নয়।
     *
     * ⓘ কেবল দিন মেলানো হয়, সময় নয় — চেকের তারিখের দিনটাতেই চলে।
     */
    private function mustBeDue(Cheque $cheque, Carbon|string $onDate, string $field): void
    {
        if ($cheque->cheque_date === null) {
            return;
        }

        $on = ($onDate instanceof Carbon ? $onDate : Carbon::parse($onDate))->toDateString();

        if ($on < $cheque->cheque_date->toDateString()) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.cheque_not_due_yet', [
                    'no' => $cheque->cheque_no,
                    'date' => DateFormat::format($cheque->cheque_date),
                ]),
            ]);
        }
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
     * ⛔ চেকের সারি তালা দিয়ে আবার পড়া, তারপর অবস্থা মাপা — Accounts-Finance অডিট ম১০, ৪ অক্টোবর ২০২৬।
     *
     * ⚠️ আগে পাশ আর ফেরত হাতে ধরা কপির অবস্থা দেখত; দুজন একসাথে চাপলে দুজনেই "জমা" দেখতেন — খাতায় পাশের টাকা ব্যাংকে
     * বসত, অথচ কাগজ শেষে "ফেরত"। ⓘ এখন দ্বিতীয়জন অপেক্ষা করেন, তারপর আসল অবস্থা দেখেন: ফেরতের পথ তখন পাশের টাকাও
     * ফেরায়, আর পাশের পথ থামে।
     *
     * @param  list<string>  $allowed
     */
    private function lockedFresh(Cheque $cheque, array $allowed): void
    {
        $fresh = Cheque::query()->withoutGlobalScopes()->whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();
        $cheque->setRawAttributes($fresh->getAttributes(), true);

        $this->assertStatus($cheque, $allowed);
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

    /**
     * ⛔ একই চেক দুইবার খাতায় নয় — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬
     * ([[OneChequeWasRegisteredTwiceTest]])।
     *
     * ⓘ আগে কোনো যাচাই ছিল না: একই কাগজ দুইবার তুললে পাশের দিন দুইবার জমা হত (গৃহীত), বা দায় দুইবার বসত
     * (দেওয়া)। চেক চেনা যায় দিক + নম্বর + ব্যাংক দিয়ে — গৃহীতের ব্যাংক লেখা নামে, দেওয়ার ব্যাংক নিজের খাতে।
     * ⓘ বাতিলও গোনা হয় — টেবিলের `acc_cheques_unique_no` তাই করে; এখানে ছাড় দিলে মানুষ পেতেন ৫০০-এর পাতা।
     * ⚠️ সূচকটা ব্যাংকের নাম খালি থাকলে কিছুই ধরে না (NULL ≠ NULL) — দেওয়া চেকে ঠিক তাই হয়; এই যাচাই সেটাও ধরে।
     * লেনদেনের ভিতরে, তালাসহ পড়া।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNotAlreadyRegistered(string $direction, array $data): void
    {
        $number = trim((string) ($data['cheque_no'] ?? ''));

        if ($number === '') {
            return;
        }

        $twin = Cheque::query()
            ->where('direction', $direction)
            ->where('cheque_no', $number)
            ->when($direction === Cheque::ISSUED,
                fn ($q) => $q->where('bank_account_id', $data['bank_account_id'] ?? null),
                fn ($q) => $q->whereRaw("LOWER(TRIM(COALESCE(bank_name, ''))) = ?", [mb_strtolower(trim((string) ($data['bank_name'] ?? '')))]))
            ->lockForUpdate()
            ->first();

        if ($twin !== null) {
            throw ValidationException::withMessages([
                'cheque_no' => __('accounts::validation.cheque_already_registered', ['no' => $number, 'doc' => $twin->document_no]),
            ]);
        }
    }
}
