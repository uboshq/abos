<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মূলধন ও বিনিয়োগ — ব্যবসার প্রথম কাজ।
 *
 * ── কেন এটা লাগল ─────────────────────────────────────────────────────
 * মালিক ব্যবসার পথটা ক্রমে বললেন: প্রথমে মূলধন, তারপর বিনিয়োগ, তারপর
 * গুদাম… এগারোটা ধাপের দশটা ABOS-এ ছিল। **প্রথমটা ছিল না।**
 *
 * খাত ছিল, ভাউচার দিয়ে টাকাটা ঢোকানোও যেত। কিন্তু পর্দা না থাকায়
 * ব্যবসার সবচেয়ে প্রথম কাজটা হত একটা হাতে লেখা জাবেদা, বিবরণে
 * "ওপেনিং" লিখে — আর কে কত দিয়েছেন, কার অংশ কত, কিছুই লেখা থাকত না।
 */
final class CapitalService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * কথা হয়েছে — লিখে রাখা।
     *
     * ── কেন খাতায় এখনই বসে না ───────────────────────────────────────
     * "মালিক পাঁচ লাখ দেবেন" কথাটা যেদিন হয়, টাকাটা আসে অন্যদিন —
     * কখনো অন্য মাসে। এখনই খাতায় বসালে ব্যবসার নগদ পাঁচ লাখ বেশি
     * দেখাত, আর ওই টাকায় মাল কেনার সিদ্ধান্ত নেওয়া হত।
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): CapitalEntry
    {
        return DB::transaction(function () use ($data) {
            $this->assertKnown($data);

            return CapitalEntry::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('CAP'),
                'person_id' => (int) $data['person_id'],
                'contributor_type' => $data['contributor_type'],
                'entry_type' => $data['entry_type'],
                'trx_date' => $data['trx_date'],
                'amount' => $data['amount'],
                'share_percent' => ($data['share_percent'] ?? '') !== '' ? $data['share_percent'] : null,
                'narration' => ($data['narration'] ?? '') ?: null,
                'status' => CapitalEntry::DRAFT,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * টাকাটা এসেছে — খাতায় বসানো।
     *
     * ── কেন কোন খাতে জিজ্ঞেস করা হয় ────────────────────────────────
     * "মালিক পাঁচ লাখ এনেছেন" কথাটা এন্ট্রি নয়, যতক্ষণ না কেউ বলে
     * টাকাটা সিন্দুকে গেল না ব্যাংকে। ধরে নেওয়া যেত "নগদ", আর তাতে
     * ব্যাংকে আসা টাকা সিন্দুকে দেখাত — মাস শেষে মিলত না।
     *
     * ── অঙ্কটা ────────────────────────────────────────────────────
     * টাকা যেখানে এল সেটা ডেবিট (সম্পদ বাড়ল), আর মালিকের মূলধন
     * ক্রেডিট (ব্যবসা মালিকের কাছে দায়বদ্ধ হলো)।
     */
    /**
     * ── `$reference` — ব্যাংক বা MFS হলে যে নম্বরটা লাগে ──────────────
     *
     * চেক নম্বর, ট্রানজেকশন আইডি, বিকাশের TrxID। ⛔ ঘরটা **ঐচ্ছিক**, আর
     * সেটা ইচ্ছাকৃত: নগদে কোনো নম্বর হয় না, আর কখন নম্বর **লাগবে** সেটা
     * ইতিমধ্যেই এক জায়গায় জানা — [[VoucherService::assertBankReferenceIsFree]]
     * টাকার খাতটা ব্যাংক বা MFS হলে নিজেই আটকায়।
     *
     * ⚠️ এখানে `required` করলে নিয়মটা **দুই জায়গায়** থাকত, আর একদিন
     * দুইটা আলাদা কথা বলত — নগদেও নম্বর চাওয়া, বা ব্যাংকে না চাওয়া।
     *
     * ── কেন ঘরটা আজ পর্যন্ত ছিলই না (১৩ সেপ্টেম্বর ২০২৬) ────────────
     * `VoucherService` নম্বরটা চিরকাল নিয়েছে, আর ব্যাংক খাতে বাধ্যতামূলকও
     * করেছে — কিন্তু অর্থ মডিউলের **একটাও সেবা সেটা পাঠাত না**। ফল:
     * মালিক ব্যাংকে মূলধন ঢোকাতে গিয়ে এমন একটা নম্বর চাওয়ার বার্তা
     * পেতেন যেটা পাঠানোর কোনো পথ পর্দায় ছিল না — **টাকাটা ঢোকানোই
     * যেত না**। মালিক নিজে ধরেছেন।
     */
    public function post(CapitalEntry $entry, Account $into, ?string $reference = null): CapitalEntry
    {
        if ($entry->status === CapitalEntry::POSTED) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.capital_already_posted', ['no' => $entry->document_no]),
            ]);
        }

        if ($into->is_group) {
            throw ValidationException::withMessages([
                'received_into_account_id' => __('finance::validation.not_a_postable_account'),
            ]);
        }

        return DB::transaction(function () use ($entry, $into, $reference) {
            $capital = Account::query()
                ->where('code', StandardChart::OWNER_CAPITAL)
                ->firstOrFail();

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::RECEIPT,
                    'trx_date' => $entry->trx_date->toDateString(),
                    'narration' => $entry->narration
                        ?? __('finance::message.capital_narration', [
                            'who' => $entry->person?->name() ?? '',
                            'no' => $entry->document_no,
                        ]),
                    'instrument_no' => $reference,
                ],
                [
                    ['account_id' => $into->id, 'debit' => $entry->amount, 'credit' => '0'],
                    ['account_id' => $capital->id, 'debit' => '0', 'credit' => $entry->amount],
                ],
            );

            $this->vouchers->post($voucher);

            $entry->forceFill([
                'status' => CapitalEntry::POSTED,
                'voucher_id' => $voucher->id,
                'received_into_account_id' => $into->id,
                'posted_at' => now(),
            ])->save();

            return $entry->fresh();
        });
    }

    /**
     * কে কোথায় দাঁড়িয়ে — দিয়েছেন কত, তুলেছেন কত, বাকি কত।
     *
     * ── কেন কেবল পোস্ট করাগুলো গোনা হয় ─────────────────────────────
     * খসড়া মানে টাকা আসেনি। ওটা গুনলে কারও অংশ বেশি দেখাত, আর
     * অংশীদারি ব্যবসায় ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
     *
     * ── উত্তোলন কোথা থেকে ───────────────────────────────────────────
     * উত্তোলনের খাত (`3200`) থেকে, নাম মিলিয়ে। আলাদা টেবিলে রাখলে
     * খাতা আর তালিকা দুই কথা বলত — আর তখন কোনটা সত্যি তা বলা যেত না।
     *
     * @return list<array{name: string, type: string, contributed: string, withdrawn: string, net: string, share: ?string}>
     */
    public function positions(): array
    {
        /*
         * ⛔ দল বাঁধা হয় `person_id` ধরে, নাম ধরে নয় (১৩ সেপ্টেম্বর ২০২৬)।
         *
         * আগে ছিল `groupBy('contributor_name', ...)`, আর তাতে একই মালিক
         * তিন বানানে **তিনটা সারি** হয়ে যেতেন — তিনজনের আলাদা নিট, আর
         * তিনজনের আলাদা **অংশ %**। মালিক নিজে প্রশ্নটা করেছেন, আর ওই
         * শতাংশটাই মুনাফা ভাগের হিসাব।
         */
        $given = CapitalEntry::query()
            ->posted()
            ->selectRaw('person_id, contributor_type, MAX(share_percent) as share, SUM(amount) as total')
            ->groupBy('person_id', 'contributor_type')
            ->get();

        // নামগুলো একবারেই, প্রতি সারিতে একটা কোয়েরি নয়
        $people = Person::query()->whereKey($given->pluck('person_id'))->get()->keyBy('id');

        $out = [];

        foreach ($given as $row) {
            $person = $people->get((int) $row->person_id);

            if ($person === null) {
                continue;
            }

            $taken = $this->withdrawnBy((int) $row->person_id);

            $out[] = [
                'person_id' => (int) $row->person_id,
                'name' => $person->name(),
                'type' => (string) $row->contributor_type,
                'contributed' => (string) $row->total,
                'withdrawn' => $taken,
                'net' => bcsub((string) $row->total, $taken, 4),
                'share' => $row->share !== null ? (string) $row->share : null,
            ];
        }

        return $out;
    }

    /**
     * ইনি কত তুলে নিয়েছেন।
     *
     * ── ⛔ আগে এটা খতিয়ানের বিবরণে নাম খুঁজত, আর সেটাই ছিল সবচেয়ে গভীর
     *      ফাঁকটা (১৩ সেপ্টেম্বর ২০২৬) ──────────────────────────────────
     * পুরনো কোড:
     *
     *     ->where('narration', 'like', '%'.$name.'%')
     *
     * তিনটা ফল, আর তিনটাই নীরব:
     *
     *   ১। **substring** — "রহিম" নামের অংশীদারের হিসাবে "আব্দুর রহিম"-এর
     *      উত্তোলনও যোগ হয়ে যেত। এক মালিকের টাকা আরেকজনের নিটে, আর
     *      **দুইজনেরই অংশ % ভুল**।
     *   ২। **বানান** — বিবরণে "Al-Amin" থাকলে "Al Amin"-এর সাথে মিলত না,
     *      তাই তাঁর উত্তোলন শূন্য দেখাত আর নিট মূলধন **বেশি** দেখাত।
     *   ৩। নামে `%` বা `_` থাকলে ওগুলো wildcard হয়ে যেত।
     *
     * ⚠️ আর সবচেয়ে বলার মতো কথা: **সীমা আর অংশ % দুই আলাদা উৎস থেকে
     * গোনা হত** — সীমা `fin_withdrawals.amount` ধরে
     * ([[WithdrawalService::assertWithinCap]]), আর নিট এই বিবরণ ধরে।
     * অর্থাৎ দুইটা সংখ্যা একমত ছিল না, আর কেউ কোনোদিন মিলিয়ে দেখেনি।
     * এখন দুইটাই একই উৎস, তাই অমিলটার অস্তিত্বই নেই।
     *
     * ── ⚠️ জানা সীমা, আর এটা ইচ্ছাকৃত ───────────────────────────────
     * গোনাটা এখন **উত্তোলনের সারি** ধরে। তাই কেউ উত্তোলনের পর্দা দিয়ে না
     * গিয়ে সরাসরি জাবেদায় ৩২০০ খাতে টাকা বসালে সেটা **কারো নিটে যোগ
     * হবে না** — খতিয়ানে থাকবে, কিন্তু কোনো মানুষের নামে বসবে না।
     *
     * ⭐ এটা পিছিয়ে যাওয়া নয়, আর কারণটা সহজ: আগে ওই সারিটা যোগ হত
     * ঠিকই, কিন্তু **substring মিলিয়ে** — অর্থাৎ প্রায়ই ভুল মানুষের নিটে।
     * **ভুল দায় দেওয়ার চেয়ে দায় না দেওয়া ভালো**, কারণ একটা ভুল সংখ্যা
     * শূন্যের চেয়ে বিপজ্জনক — মানুষ ওটা দেখে মুনাফা ভাগ করেন।
     *
     * ⓘ সীমাটা টেস্টে বাঁধা আছে, যাতে ছয় মাস পরে কেউ এটাকে "বাগ" ভেবে
     * আবার বিবরণ-খোঁজা ফিরিয়ে না আনেন:
     * [[Tests\Feature\Modules\Finance\TheCapWasSetOnANameAndTheNameChangedTest]]।
     */
    private function withdrawnBy(int $personId): string
    {
        $sum = Withdrawal::query()
            ->where('person_id', $personId)
            ->posted()
            ->sum('amount');

        return (string) ($sum ?: '0.0000');
    }

    /** @param  array<string, mixed>  $data */
    private function assertKnown(array $data): void
    {
        /*
         * কে দিলেন, সেটা না জানলে সারিটা বসে না।
         *
         * ⚠️ শর্তটা কন্ট্রোলারের যাচাইতেও আছে (`required_without`), তবু
         * এখানেও — কারণ `(int) null` হয় **শূন্য**, আর শূন্য একটা বৈধ
         * দেখতে আইডি। সেবাটা সরাসরি ডাকা হলে (টেস্ট, ইমপোর্ট, কমান্ড)
         * ওই শূন্যটা FK-এ গিয়ে ভাঙত, আর বার্তাটা হত ডাটাবেজের ভাষায় —
         * ব্যবহারকারীর নয়।
         */
        if ((int) ($data['person_id'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'person_id' => __('finance::validation.capital_needs_a_name'),
            ]);
        }

        if (! in_array($data['contributor_type'] ?? '', CapitalEntry::WHO, true)) {
            throw ValidationException::withMessages([
                'contributor_type' => __('finance::validation.unknown_contributor_type'),
            ]);
        }

        if (! in_array($data['entry_type'] ?? '', CapitalEntry::KINDS, true)) {
            throw ValidationException::withMessages([
                'entry_type' => __('finance::validation.unknown_capital_kind'),
            ]);
        }

        if (bccomp((string) ($data['amount'] ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.capital_must_be_positive'),
            ]);
        }
    }
}
