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
                /*
                 * ⓘ কী দিয়ে এল — খালি এলে কলামের ডিফল্ট `cash`, তাই
                 * পুরনো সারির আচরণ বদলায় না।
                 */
                'in_kind' => ($data['in_kind'] ?? '') ?: CapitalEntry::CASH,

                /*
                 * ⚠️ খাতটা এখানে **প্রস্তাব**, সিদ্ধান্ত নয়।
                 *
                 * ⛔ সারিটা এখনো খসড়া — টাকা নড়েনি। ⓘ ঘরটা কেবল রসিদের
                 * পর্দায় খাতটা আগে থেকে বসিয়ে রাখার জন্য; আসল খাত ঠিক
                 * হয় ভাউচার পোস্ট হওয়ার দিন।
                 */
                'received_into_account_id' => ($data['received_into_account_id'] ?? '') ?: null,

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
     * দাখিলার লাইনগুলো — চার্জ থাকলে তিনটা, নাহলে দুইটা।
     *
     * ── মালিকের সিদ্ধান্ত, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * প্রশ্নটা তিনি নিজেই তুলেছেন: *"ব্যাংক চার্জ আছে, MFS চার্জ আছে —
     * এগুলো কোথায়?"*
     *
     * ⛔ কোথাও ছিল না। খাত দুইটা প্রমিত ছকে **আগে থেকেই বসানো**
     * (`5210` ব্যাংক চার্জ, `5211` মোবাইল ব্যাংকিং চার্জ), আর ছকের
     * মন্তব্যে কারণও লেখা: *"বিকাশ ক্যাশ-আউটে চার্জ কাটে, ব্যাংক কাটে
     * না… ওই চার্জটা আলাদা খাতে না গেলে বছরে কত গেল কেউ জানে না।"*
     * ⚠️ খাত বানানো হয়েছিল, কারণ লেখা হয়েছিল, আর **কোনো পর্দা ওটা
     * কোনোদিন ব্যবহার করেনি** — আজকের চেনা রোগ।
     *
     * ── ⭐ কোনটা মূলধন: যা পাঠানো হলো, যা ঢুকল নয় ────────────────────
     * মালিক ৮,০০০ পাঠালেন, ব্যাংক ২০ কাটল, ৭,৯৮০ ঢুকল:
     *
     *     ব্যাংক খাত     ডেবিট  ৭,৯৮০
     *     চার্জ খাত       ডেবিট     ২০
     *     মূলধন          ক্রেডিট  ৮,০০০
     *
     * ⓘ মালিকের অংশ পুরো ৮,০০০, কারণ তিনি ততটাই দিয়েছেন — আর ২০ টাকা
     * ব্যবসার খরচ, তাঁর অনুদানের ঘাটতি নয়। ⚠️ উল্টোটা করলে অংশীদারি
     * ব্যবসায় কার কত অংশ সেই সংখ্যাটা চার্জের হারের সাথে নড়ত।
     *
     * ── কোন চার্জ খাত ─────────────────────────────────────────────────
     * টাকা যে ধরনের খাতে ঢুকছে সেটাই ঠিক করে — ব্যাংক হলে `5210`,
     * MFS হলে `5211`। ⓘ নগদে চার্জ হয় না, আর ঘরটাও তখন আসে না।
     *
     * @return list<array<string, mixed>>
     */
    private function lines(CapitalEntry $entry, Account $into, Account $capital, ?string $charge): array
    {
        $cut = trim((string) $charge);

        if ($cut === '' || bccomp($cut, '0', 4) <= 0) {
            return [
                ['account_id' => $into->id, 'debit' => $entry->amount, 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => $entry->amount],
            ];
        }

        /*
         * ⛔ চার্জ মোটের চেয়ে বড় বা সমান হতে পারে না।
         *
         * ⓘ সমানও নয়: তাহলে খাতে শূন্য ঢুকত, আর "টাকা এসেছে" বলাটাই
         * মিথ্যা হত। সংখ্যাটা হাতে লেখা, তাই একটা বাড়তি শূন্যই যথেষ্ট।
         */
        if (bccomp($cut, (string) $entry->amount, 4) >= 0) {
            throw ValidationException::withMessages([
                'charge' => __('finance::validation.charge_eats_the_whole_thing'),
            ]);
        }

        $landed = bcsub((string) $entry->amount, $cut, 4);

        $chargeAccount = Account::query()
            ->where('code', $into->isMfs() ? StandardChart::MFS_CHARGES : StandardChart::BANK_CHARGES)
            ->firstOrFail();

        return [
            ['account_id' => $into->id, 'debit' => $landed, 'credit' => '0'],
            ['account_id' => $chargeAccount->id, 'debit' => $cut, 'credit' => '0'],
            ['account_id' => $capital->id, 'debit' => '0', 'credit' => $entry->amount],
        ];
    }

    /**
     * খসড়া সারিটা শুধরানো।
     *
     * ⛔ নথির নম্বরটা বদলায় না, আর কখনো বদলাবেও না — একবার দেওয়া নম্বর
     * ফেরত নেওয়া হয় না ([[NumberSeriesEngine]])। ⓘ কেউ কাগজে CAP-0001
     * লিখে রাখলে সেটা যেন একই জিনিসই বোঝায়।
     *
     * ⚠️ অবস্থার পাহারাটা এখানেও, কন্ট্রোলারে থাকা সত্ত্বেও। কারণ এই
     * সেবাটা পরে ইমপোর্ট বা API থেকেও ডাকা হতে পারে, আর তখন কন্ট্রোলারের
     * পাহারাটা চলবেই না। ⭐ নিয়মটা যেখানে টাকা নড়ে সেখানে থাকতে হয়,
     * যেখানে ফর্ম জমা পড়ে সেখানে নয়।
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(CapitalEntry $entry, array $data): CapitalEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $this->assertStillADraft($entry);
            $this->assertKnown($data);

            $entry->forceFill([
                'person_id' => (int) $data['person_id'],
                'contributor_type' => $data['contributor_type'],
                'entry_type' => $data['entry_type'],
                /*
                 * ⓘ কী দিয়ে এল — খালি এলে কলামের ডিফল্ট `cash`, তাই
                 * পুরনো সারির আচরণ বদলায় না।
                 */
                'in_kind' => ($data['in_kind'] ?? '') ?: CapitalEntry::CASH,

                /*
                 * ⚠️ খাতটা এখানে **প্রস্তাব**, সিদ্ধান্ত নয়।
                 *
                 * ⛔ সারিটা এখনো খসড়া — টাকা নড়েনি। ⓘ ঘরটা কেবল রসিদের
                 * পর্দায় খাতটা আগে থেকে বসিয়ে রাখার জন্য; আসল খাত ঠিক
                 * হয় ভাউচার পোস্ট হওয়ার দিন।
                 */
                'received_into_account_id' => ($data['received_into_account_id'] ?? '') ?: null,

                'trx_date' => $data['trx_date'],
                'amount' => $data['amount'],
                'share_percent' => ($data['share_percent'] ?? '') !== '' ? $data['share_percent'] : null,
                'narration' => ($data['narration'] ?? '') ?: null,
            ])->save();

            return $entry->fresh();
        });
    }

    /**
     * খসড়াটা ফেলে দেওয়া।
     *
     * ⓘ soft delete, কারণ [[IsAudited]] সারিটার ইতিহাস ধরে রাখে আর
     * নম্বরটা দখলেই থাকে — ফিরে এসে দেখা যে কেউ CAP-0002 নিয়ে নিয়েছে,
     * এমন হওয়া উচিত নয়।
     */
    public function discard(CapitalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $this->assertStillADraft($entry);

            $entry->delete();
        });
    }

    /**
     * ⛔ পোস্ট হওয়া সারি ছোঁয়া যায় না — বদলানোও নয়, মোছাও নয়।
     *
     * পোস্ট মানে একটা ভাউচার আর দুইটা দাখিলা খাতায় বসে গেছে। সারিটা
     * পরে বদলালে **খাতা আর তালিকা দুই কথা বলত**, আর মুছলে ট্রায়াল
     * ব্যালেন্সে একটা গর্ত থাকত যার কোনো ব্যাখ্যা নেই।
     *
     * ⭐ ভুল হলে বিপরীত দাখিলা — এই রিপোর নিয়ম ৫, আর ঋণের রুটের
     * মন্তব্যেও একই কথা লেখা আছে।
     */
    private function assertStillADraft(CapitalEntry $entry): void
    {
        if ($entry->status !== CapitalEntry::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.capital_already_posted', [
                    'no' => $entry->document_no,
                ]),
            ]);
        }
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
     *
     * @param  string|null  $charge  ব্যাংক বা MFS যা কেটে রেখেছে।
     */
    public function post(
        CapitalEntry $entry,
        Account $into,
        ?string $reference = null,
        ?string $charge = null,
    ): CapitalEntry {
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

        /*
         * ⛔ `$charge` এই তালিকায় ছিল না — ১৫ সেপ্টেম্বর ২০২৬-এ ধরা।
         *
         * ⚠️ অথচ নিচে `lines(..., $charge)` ডাকা হয়, তাই **এই মেথডটা
         * যে কেউ ডাকলেই `Undefined variable $charge` দিয়ে মরত**।
         *
         * ⓘ ধরা পড়েনি কারণ লাইভে এই পথটা কেউ ডাকে না — মূলধন খাতায়
         * বসে রসিদ ভাউচারের পর্দা থেকে, আর `CapitalController::post()`
         * মৃত কোড। ⭐ ছয়টা টেস্ট এটাকে ডাকে, আর ওগুলো চালানোই হয়নি।
         */
        return DB::transaction(function () use ($entry, $into, $reference, $charge) {
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
                    ...$this->lines($entry, $into, $capital, $charge),
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
    /**
     * কে কোথায় দাঁড়িয়ে — দিয়েছেন, তুলেছেন, বাকি, আর অংশ।
     *
     * @param  string|null  $profit  চলতি বছরের মুনাফা। ⓘ না দিলে লাভের
     *                               অংশের ঘরটা `null` থাকে — কলামটা তখন
     *                               একটা ড্যাশ দেখায়, শূন্য নয়।
     *
     * @return list<array<string, mixed>>
     */
    public function positions(?string $profit = null): array
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

                /*
                 * ⭐ শতাংশটা টাকায় কত — ১৮ সেপ্টেম্বর ২০২৬, মালিকের প্রশ্নে।
                 *
                 * ── ⛔ আগে কেবল "৪০%" লেখা থাকত ──────────────────────
                 * মালিক জিজ্ঞেস করেছেন *"কে কত % লাভ পাবে, তার অংশ কত?"*
                 * ⓘ শতাংশ থেকে টাকা বের করতে হলে চলতি বছরের মুনাফা
                 * জানতে হয়, আর সেটা এই পর্দায় ছিল না।
                 *
                 * ── ⚠️ সংখ্যাটা **আন্দাজ**, চূড়ান্ত নয় ────────────────
                 * ⛔ বছর বন্ধ হওয়ার আগে মুনাফা বদলায় — একটা বড় খরচ বা
                 * একটা অনাদায়ী বিল সবটা ঘুরিয়ে দিতে পারে। ⓘ তাই কলামের
                 * শিরোনামেই "চলতি" কথাটা থাকে, আর লোকসান হলে সংখ্যাটা
                 * ঋণাত্মক দেখায় — শূন্য নয়।
                 *
                 * ⚠️ অংশ % লেখা না থাকলে `null`, শূন্য নয়: *"অংশ ঠিক
                 * হয়নি"* আর *"অংশ নেই"* এক কথা নয়।
                 */
                'profit_share' => ($profit === null || $row->share === null)
                    ? null
                    : bcdiv(bcmul($profit, (string) $row->share, 6), '100', 4),
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
