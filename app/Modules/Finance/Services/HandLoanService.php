<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * হাতধার — কাগজবিহীন ধার, তবু খাতার ভেতরে।
 *
 * ── কেন এটা ঋণের একটা ধরন নয়, ২৯ আগস্ট ২০২৬ ─────────────────────────
 * মালিকের কথা: *"এটা ঋণের ধরন হইলে হবে না, এটা টোটালি আলাদা একটা
 * জিনিস।"* ঋণ বয়ে বেড়ায় কিস্তির সূচি, সুদের পদ্ধতি, জামানত,
 * পুনর্বিবেচনার তারিখ আর সুবিধার ধরন — হাতধারে এর একটাও সত্যি নয়।
 *
 * জোর করে ঢোকালে সূচিটা কল্পনা হয়, আর যে একটা প্রশ্ন মানুষ সত্যিই করে
 * — **করিম কত ফেরত দিয়েছে?** — সেটা অর্থহীন যন্ত্রপাতির নিচে চাপা পড়ে।
 *
 * HP-র ২৯ আগস্টের রিপোর্টে এর ফলটাই ধরা পড়েছে: ঋণের ফর্মে "Hand loan"
 * বাছলে সেভ হত "Cash credit (CC)" হিসেবে। জিনিসটা ওখানে থাকারই কথা নয়।
 *
 * ── তবু এটা খাতায় যায় ───────────────────────────────────────────────
 * টাকাটা ব্যবসার: টিল বা ব্যাংক থেকে বেরোয়, ওখানেই ফেরে। খাতায় না
 * বসালে ক্যাশ বই ঠিক ওই পরিমাণ কম দেখাত — আর **ঘাটতি দেখতে চুরির
 * মতো**, ধার দেওয়ার মতো নয়। এই ফিচারটা ঠিক ওই অভিযোগটা ঠেকাতেই।
 *
 * ── ব্যালেন্সের চিহ্নই সব ─────────────────────────────────────────────
 * ধনাত্মক মানে টাকা বাইরে (তিনি দেবেন), ঋণাত্মক মানে ডিপো ধার নিয়েছে।
 * একটাই খাত (১১৭০), দুই দিকেই — দুইটা খাত রাখলে বছরশেষে দুইটা যোগ-বিয়োগ
 * করে নিট বের করতে হত, অথচ প্রশ্নটা সবসময় নিট নিয়েই।
 */
final class HandLoanService
{
    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * একজন মানুষ — নাম আর একটা নম্বর, ব্যস।
     *
     * ── কেন পক্ষের তালিকা থেকে নয় ───────────────────────────────────
     * এদের বেশিরভাগ গ্রাহকও নন, সরবরাহকারীও নন, আর কোনোদিন হবেনও না।
     * চাচাতো ভাইকে পাঁচ হাজার ধার দিতে আগে একটা গ্রাহক রেকর্ড বানাতে
     * হলে ফিচারটা অব্যবহৃত থেকে যেত।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): HandLoanAccount
    {
        $name = trim((string) ($data['person_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'person_name' => __('finance::validation.hand_loan_needs_a_name'),
            ]);
        }

        return HandLoanAccount::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'person_name' => $name,
            'mobile' => ($data['mobile'] ?? '') ?: null,
            'partner_id' => $data['partner_id'] ?? null,
            'partner_type' => ($data['partner_id'] ?? null) !== null
                ? ($data['partner_type'] ?? null) : null,
            'note' => ($data['note'] ?? '') ?: null,
            'status' => HandLoanAccount::ACTIVE,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * টাকা গেল বা এল — আর খাতায় বসল।
     *
     * ── অঙ্কটা ──────────────────────────────────────────────────────
     *   বাইরে (`out`) → Dr ১১৭০ হাতধার · Cr নগদ/ব্যাংক
     *   ভেতরে (`in`)  → Dr নগদ/ব্যাংক · Cr ১১৭০ হাতধার
     *
     * একই দুইটা খাত দুই দিকেই, কারণ ধার দেওয়া আর ফেরত পাওয়া একটাই
     * সম্পর্কের দুই দিক। কোনটা ঘটল সেটা ব্যালেন্সের কাজ, পঞ্চম একটা
     * খাতের নয়।
     *
     * @param  array<string, mixed>  $data
     */
    public function move(HandLoanAccount $account, array $data): HandLoanMovement
    {
        $this->assertOpen($account);

        $direction = $data['direction'] ?? '';
        $amount = (string) ($data['amount'] ?? '0');

        if (! in_array($direction, HandLoanMovement::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => __('finance::validation.hand_loan_which_way'),
            ]);
        }

        /*
         * ঋণাত্মক অঙ্ক মানে উল্টো দিকটা কঠিন করে লেখা।
         *
         * মেনে নিলে ব্যালেন্সটা নির্ভর করত কেউ কোন দুইভাবে একই কথা
         * বলল তার উপর, আর কোনো পর্দায় পার্থক্যটা দেখা যেত না।
         */
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.hand_loan_amount_positive'),
            ]);
        }

        $money = $this->money($data['money_account_id'] ?? null);
        $head = $this->head();
        $on = (string) ($data['moved_on'] ?? now()->toDateString());
        $out = $direction === HandLoanMovement::OUT;

        return DB::transaction(function () use ($account, $direction, $amount, $on, $money, $head, $out, $data) {
            $voucher = $this->vouchers->create(
                [
                    'type' => $out ? Voucher::PAYMENT : Voucher::RECEIPT,
                    'trx_date' => $on,
                    'narration' => ($data['note'] ?? '') ?: __('finance::message.hand_loan_narration', [
                        'who' => $account->person_name,
                    ]),
                ],
                [
                    [
                        'account_id' => $out ? $head->id : $money->id,
                        'debit' => $amount, 'credit' => '0',
                    ],
                    [
                        'account_id' => $out ? $money->id : $head->id,
                        'debit' => '0', 'credit' => $amount,
                    ],
                ],
            );

            $this->vouchers->post($voucher);

            return HandLoanMovement::query()->create([
                'company_id' => CompanyContext::id(),
                'account_id' => $account->id,
                'direction' => $direction,
                'amount' => $amount,
                'moved_on' => $on,
                'money_account_id' => $money->id,
                'voucher_id' => $voucher->id,
                'note' => ($data['note'] ?? '') ?: null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * চুকে গেছে — কিন্তু কেবল ব্যালেন্স শূন্য হলে।
     *
     * ── কেন সেবাই আটকায় ────────────────────────────────────────────
     * বাকি থাকা অবস্থায় "চুকে গেছে" চিহ্নিত করলে টাকাটা তালিকা থেকে
     * হারাত অথচ খাতায় থেকে যেত — আর ঠিক ওই টাকাটা ভুলে যাওয়ার জন্যই
     * ফিচারটা বানানো।
     */
    public function settle(HandLoanAccount $account): HandLoanAccount
    {
        $balance = $this->balanceOf($account);

        if (bccomp($balance, '0', 4) !== 0) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.hand_loan_not_clear', [
                    'amount' => $balance,
                ]),
            ]);
        }

        $account->forceFill(['status' => HandLoanAccount::SETTLED])->save();

        return $account->fresh();
    }

    /** এই মানুষটার কাছে কত — ধনাত্মক মানে তিনি দেবেন। */
    public function balanceOf(HandLoanAccount $account): string
    {
        $row = HandLoanMovement::query()
            ->where('account_id', $account->id)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as gone,
                COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as came
            ', [HandLoanMovement::OUT, HandLoanMovement::IN])
            ->first();

        return bcsub((string) $row->gone, (string) $row->came, 4);
    }

    /**
     * সবার অবস্থান — কে পায়, কাকে দিতে হবে।
     *
     * ── কেন এক তালিকা, দুইটা রিপোর্ট নয় ─────────────────────────────
     * "কে আমার কাছে পায়" আর "আমি কার কাছে পাই" দুইটা আলাদা রিপোর্ট
     * হলে কেউ ওদের মিলিয়ে দেখত না, আর একই মানুষ দুই তালিকায় থাকতে
     * পারত। চিহ্নটাই ভাগ করে দেয়।
     *
     * ── ⚠️ জানা সীমা, আর কেন আজ ছোঁয়া হয়নি (১২ সেপ্টেম্বর ২০২৬) ─────
     * পাতা-ভাগের কাজটার সময় এই পদ্ধতিটা মাপা হয়েছে। দুইটা খরচ আছে:
     *
     *   ১। প্রতিটা খাতের জন্য একটা করে [[balanceOf]] — অর্থাৎ চল্লিশ
     *      জন মানুষ মানে চল্লিশটা কোয়েরি, সবগুলোই SUM।
     *   ২। `owed_to_us` ও `we_owe` পুরো তালিকাটা হেঁটে গোনা হয়।
     *
     * **তবু আজ বদলানো হয়নি, আর কারণটা ভয় নয় — অনুপাত।** হাতের ধার
     * গোনা হয় দশে, হাজারে নয়; একটা ডিপোর খোলা খাত চল্লিশ ছাড়ায় না।
     * অর্থাৎ আজকের দাম প্রায় শূন্য, আর ঝুঁকিটা শূন্য নয়: কোন খাত
     * "খোলা", ব্যালেন্স ধনাত্মক না ঋণাত্মক — সিদ্ধান্তগুলো আজ PHP-তে
     * সারি ধরে নেওয়া হয়। SQL-এ নামাতে গিয়ে ভুল ঢোকে ওই নিয়মগুলোতে,
     * যোগে নয় (DECIMAL-এর SUM নির্ভুল, float আসে না)।
     *
     * ⛔ আর পাতা ভাগ এর উত্তর **নয়** — উল্টো ক্ষতি: পাতা ভাগ করলে
     * উপরের দুইটা যোগফল আর "কতজন" সংখ্যাটা এই পাতার হয়ে যেত, অথচ
     * লেখা থাকত "মোট"।
     *
     * ── কী শর্তে পরে ────────────────────────────────────────────────
     * যেদিন কোনো কোম্পানির খোলা খাত কয়েকশোয় পৌঁছাবে। তখন ক্রমটা:
     * **আগে একটা টেস্ট যা পুরনো ও নতুন দুই পথের উত্তর চার দশমিক পর্যন্ত
     * মেলায়, তারপর বদল** — উল্টোটা নয়। বদলটা হবে `balanceOf`-কে
     * খাত ধরে group-by করা একটা কোয়েরিতে নামানো, আর যোগফল দুইটা
     * ওই একই কোয়েরি থেকে নেওয়া।
     *
     * @return array{rows: list<array<string, mixed>>, owed_to_us: string, we_owe: string}
     */
    public function standing(): array
    {
        $rows = [];
        $toUs = '0';
        $byUs = '0';

        /*
         * ⛔ `postable()` নয়, `open()` — ৬ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * `scopePostable()` আছে **[[Account]]** মডেলে, `HandLoanAccount`-এ
         * নয়। ⚠️ ফলে পর্দাটা খুললেই `BadMethodCallException` — **৫০০**,
         * প্রতিবার। ⓘ চারটা টেস্ট এখানেই আটকে ছিল, আর মেনুর সারিটাও
         * "দেখা যায়, ক্লিক করলে ভাঙে" তালিকায় উঠে এসেছিল।
         *
         * ⓘ ভুলটা নকলের: `Account::query()->postable()` ছাঁচটা এই রিপোতে
         * বহু জায়গায় আছে, আর ওখানে ওটার মানে *"গ্রুপ নয়, সরাসরি এন্ট্রি
         * বসানো যায়"*। ⚠️ হাতের ঋণের খাতায় গ্রুপ বলে কিছু নেই — সেখানে
         * প্রশ্নটা **চুকে গেছে কি না**।
         *
         * ⭐ তাই `open()` — চলতি খাতাগুলো। ⓘ চুকে যাওয়া ঋণের কোনো
         * "বকেয়া অবস্থান" থাকে না, তাই এই তালিকায় তার জায়গাও নেই।
         */
        $accounts = HandLoanAccount::query()->open()->withCount('movements')
            ->orderBy('person_name')->get();

        foreach ($accounts as $account) {
            $balance = $this->balanceOf($account);

            $rows[] = [
                'account' => $account,
                'balance' => $balance,
                'movements' => $account->movements_count,
            ];

            if (bccomp($balance, '0', 4) > 0) {
                $toUs = bcadd($toUs, $balance, 4);
            } else {
                $byUs = bcadd($byUs, bcmul($balance, '-1', 4), 4);
            }
        }

        return ['rows' => $rows, 'owed_to_us' => $toUs, 'we_owe' => $byUs];
    }

    /**
     * হাতধারের খাত — একটাই, দুই দিকেই।
     *
     * সম্পদের ঘরে, কারণ সাধারণ ঘটনাটা টাকা বাইরে যাওয়া। ডিপো কারও
     * কাছে ধার নিলে ব্যালেন্সটা ঋণাত্মক হয়ে ওই খাতের ভেতরেই বসে —
     * ঠিক যেভাবে অগ্রিম দেওয়া গ্রাহক প্রাপ্য হিসাবে ঋণাত্মক বসেন।
     */
    private function head(): Account
    {
        $head = StandardChart::find(StandardChart::HAND_LOAN);

        if ($head === null) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::validation.chart_head_missing', [
                    'code' => StandardChart::HAND_LOAN,
                ]),
            ]);
        }

        return $head;
    }

    /**
     * কোন টিল বা ব্যাংক থেকে — আর এটা বাধ্যতামূলক।
     *
     * ── কেন ঐচ্ছিক নয় ──────────────────────────────────────────────
     * DMS-এ ঘরটা ঐচ্ছিক, আর সেখানে যুক্তিটা ছিল পুরনো ব্যালেন্স তোলা।
     * ABOS-এ ওই কাজটার নিজের পথ আছে ([[OpeningBalanceService]]), তাই
     * এখানে ঐচ্ছিক রাখলে সেটা কেবল একটা ফাঁক হত: যে চলাচল কোনো টাকার
     * খাত নাড়ায় না, সেটা পর্দায় একটা সংখ্যা যা ক্যাশ বই দেখতেই পায় না।
     */
    private function money(mixed $id): Account
    {
        $account = Account::query()->find($id);

        if ($account === null || $account->is_group) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.not_a_postable_account'),
            ]);
        }

        return $account;
    }

    private function assertOpen(HandLoanAccount $account): void
    {
        if ($account->isSettled()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.hand_loan_already_settled', [
                    'who' => $account->person_name,
                ]),
            ]);
        }
    }
}
