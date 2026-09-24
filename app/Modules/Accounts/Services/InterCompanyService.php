<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * এক কোম্পানির টাকা আরেকজনের খাতায় — দুই দিকেই বসে।
 *
 * ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"গ্রুপের অ্যাকাউন্ট থেকে টাকা নিলে?"*
 *
 * ── ⓘ যা বসে ────────────────────────────────────────────────────────
 * ADI ৫০,০০০ দিল TCL-কে:
 *
 *     ADI : ডেবিট ভাই-কোম্পানির চলতি হিসাব ৫০,০০০ / ক্রেডিট নগদ ৫০,০০০
 *     TCL : ডেবিট নগদ ৫০,০০০ / ক্রেডিট ভাই-কোম্পানির চলতি হিসাব ৫০,০০০
 *
 * ⭐ দুই খাতাই নিজে নিজে মেলে, আর দুইটা মিলিয়ে শূন্য — সেটাই সঠিক
 * আন্তঃকোম্পানি হিসাবের একমাত্র পরীক্ষা।
 *
 * ── ⚠️ যা এটা করে না, আর কেন ─────────────────────────────────────────
 * ⛔ ADI সরাসরি TCL-এর **খরচ** দিলে (যেমন TCL-এর দোকানভাড়া) TCL-এর
 * দিকে ডেবিটটা নগদ নয়, খরচের খাত। ⓘ তখন ফরমে দুই কোম্পানির দুইটা
 * আলাদা খাত বাছতে হত, আর ব্যবহারকারীকে অন্য কোম্পানির ছক চিনতে হত।
 *
 * ⚠️ সেটা আলাদা কাজ, আর এখানে চুপচাপ অনুমান করা হয়নি: মালিকের কথা ছিল
 * *"অ্যাকাউন্ট থেকে টাকা নিলে"* — অর্থাৎ টাকা সরানো। ⓘ সীমাটা পর্দায়ও
 * লেখা থাকে, যাতে কেউ ভেবে না বসে খরচের দিকটাও হয়ে গেছে।
 *
 * ── ⛔ দেয়ালটা এখানেই পাহারা দেওয়া হয় ───────────────────────────────
 * `counter_company_id` কোনো গ্লোবাল স্কোপে পড়ে না, তাই এটাই একমাত্র
 * ঘর যা সীমানা পেরোয়। ⚠️ তাই প্রতিটা লেখার আগে যাচাই হয় ব্যবহারকারী
 * **দুইটা কোম্পানিতেই** আছেন কি না — `company_user` পিভট ধরে।
 * ⓘ মডেলে বসালে কোনো `create()` একদিন যাচাই এড়িয়ে যেতে পারত; সেবা
 * স্তরটাই একমাত্র দরজা।
 */
final class InterCompanyService
{
    private const SCALE = 4;

    public function __construct(
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * টাকা সরানো, আর দুই খাতায় বসানো — একটাই লেনদেনে।
     *
     * @param  array{counter_company_id: mixed, amount: mixed, trx_date: string, purpose: string, from_account_id: mixed, to_account_id: mixed, branch_id?: mixed}  $data
     */
    public function record(User $user, array $data): InterCompanyTransfer
    {
        $own = CompanyContext::id();

        if ($own === null) {
            throw new \RuntimeException('কোম্পানির প্রসঙ্গ ছাড়া আন্তঃকোম্পানি লেনদেন লেখা যায় না।');
        }

        $counter = $this->assertBothAreMine($user, $own, (int) $data['counter_company_id']);
        $amount = $this->assertAmount($data['amount']);

        return DB::transaction(function () use ($user, $own, $counter, $amount, $data) {
            $transfer = new InterCompanyTransfer([
                'counter_company_id' => $counter->id,
                'trx_date' => $data['trx_date'],
                'amount' => $amount,
                'purpose' => $data['purpose'],
            ]);

            $transfer->created_by = $user->id;
            $transfer->status = DocumentStatus::DRAFT;
            $transfer->save();

            /*
             * ⓘ দেওয়ার দিক — চলতি কোম্পানিতেই, তাই কোনো প্রসঙ্গ বদল নেই।
             * ⚠️ টাকার খাতটা ব্যবহারকারীর বাছা, কিন্তু সেটা সত্যিই টাকার
             * খাত কি না আর এই কোম্পানিরই কি না — দুইটাই যাচাই হয়।
             */
            $from = $this->assertMoneyAccount($data['from_account_id'], 'from_account_id');
            $control = $this->assertControl();

            $out = $this->vouchers->create(
                [
                    'type' => Voucher::JOURNAL,
                    'trx_date' => $data['trx_date'],
                    'narration' => $this->narration($counter->name(), $data['purpose']),
                    'branch_id' => $data['branch_id'] ?? null,
                ],
                [
                    ['account_id' => $control->id, 'debit' => $amount, 'credit' => '0',
                        'narration' => $data['purpose']],
                    ['account_id' => $from->id, 'debit' => '0', 'credit' => $amount,
                        'narration' => $data['purpose']],
                ],
            );

            $this->vouchers->post($out);
            $transfer->out_voucher_id = $out->id;

            /*
             * ⭐ পাওয়ার দিক — অন্য কোম্পানির প্রসঙ্গে।
             *
             * ⚠️ [[CompanyContext::forCompany()]], কখনো `::set()` নয়।
             * ⓘ `forCompany()` `finally`-তে আগের প্রসঙ্গ **ফিরিয়ে দেয়**;
             * `set()` দিলে প্রসঙ্গটা ফাঁস হয়ে যেত আর এর পরের প্রতিটা
             * কোয়েরি ভুল কোম্পানিতে চলত — অনুরোধের বাকি অংশ জুড়ে, কোনো
             * ত্রুটিবার্তা ছাড়াই।
             *
             * ⓘ ভেতরে [[StandardChart::find()]] আর নম্বর-সিরিজ দুইটাই
             * নিজে থেকে ঐ কোম্পানির জিনিস খুঁজে নেয়, কারণ তারাও একই
             * প্রসঙ্গ পড়ে।
             */
            /*
             * ⚠️ অন্য কোম্পানির ত্রুটিগুলো নাম ধরে ফেরত দেওয়া হয়।
             *
             * ⛔ মেপে দেখা: পাওয়ার কোম্পানিতে ঐ তারিখের অর্থবছর না থাকলে
             * [[VoucherService::resolveFinancialYear()]] বলে *"এই তারিখের
             * অর্থবছর নেই"* — কিন্তু ব্যবহারকারীর **নিজের** কোম্পানিতে
             * তো আছে। ⓘ ফলে তিনি নিজের ছকে খুঁজতেন আর কিছুই পেতেন না।
             *
             * ⭐ তাই ভেতরের বার্তাটা মুছে ফেলা হয় না — সাথে কোম্পানির
             * নামটা জুড়ে দেওয়া হয়, যাতে কোথায় দেখতে হবে সেটা লেখা থাকে।
             */
            $in = $this->inTheirBooks($counter, fn () => CompanyContext::forCompany((int) $counter->id, function () use ($data, $amount, $own) {
                $to = $this->assertMoneyAccount($data['to_account_id'], 'to_account_id');
                $control = $this->assertControl();

                $payer = Company::query()->withoutGlobalScopes()->findOrFail($own);

                $voucher = $this->vouchers->create(
                    [
                        'type' => Voucher::JOURNAL,
                        'trx_date' => $data['trx_date'],
                        'narration' => $this->narration($payer->name(), $data['purpose']),
                        /*
                         * ⛔ শাখা এখানে বসানো হয় না, আর সেটা ইচ্ছাকৃত।
                         * ⚠️ শাখার আইডি কোম্পানি ধরে বাঁধা — দেওয়ার
                         * কোম্পানির শাখা পাওয়ার কোম্পানিতে বসালে সেটা
                         * অন্য কারো শাখার দিকে তাকাত।
                         */
                        'branch_id' => null,
                    ],
                    [
                        ['account_id' => $to->id, 'debit' => $amount, 'credit' => '0',
                            'narration' => $data['purpose']],
                        ['account_id' => $control->id, 'debit' => '0', 'credit' => $amount,
                            'narration' => $data['purpose']],
                    ],
                );

                return $this->vouchers->post($voucher);
            }));

            $transfer->in_voucher_id = $in->id;
            $transfer->status = DocumentStatus::CONFIRMED;
            $transfer->save();

            return $transfer;
        });
    }

    /**
     * অন্য কোম্পানির ত্রুটিতে সেই কোম্পানির নামটা জুড়ে দেওয়া।
     *
     * ── ⚠️ কেন এটা দরকার ────────────────────────────────────────────
     * ⓘ ভেতরের যাচাইগুলো (অর্থবছর, বন্ধ মাস, ছকের খাত) সবই **চলতি
     * প্রসঙ্গের** কোম্পানি ধরে কথা বলে, আর তাদের বার্তায় কোম্পানির নাম
     * থাকে না — কারণ সাধারণ ক্ষেত্রে দরকারই হয় না।
     *
     * ⛔ কিন্তু এখানে প্রসঙ্গটা **অন্য কোম্পানি**, তাই "এই তারিখের
     * অর্থবছর নেই" পড়ে ব্যবহারকারী নিজের ছকে খুঁজতেন। ⭐ তাই বার্তাটা
     * বদলানো হয় না, কেবল সামনে নামটা বসে।
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function inTheirBooks(Company $counter, \Closure $work): mixed
    {
        try {
            return $work();
        } catch (ValidationException $e) {
            $named = [];

            foreach ($e->errors() as $field => $messages) {
                $named[$field] = array_map(
                    fn (string $message) => __('accounts::validation.inter_company_their_books', [
                        'company' => $counter->name(),
                        'problem' => $message,
                    ]),
                    $messages,
                );
            }

            throw ValidationException::withMessages($named);
        }
    }

    /**
     * ⛔ দুইটা কোম্পানিতেই সদস্যপদ — নাহলে কিছুই লেখা হয় না।
     */
    private function assertBothAreMine(User $user, int $own, int $counterId): Company
    {
        if ($counterId === $own) {
            throw ValidationException::withMessages([
                'counter_company_id' => __('accounts::validation.inter_company_same'),
            ]);
        }

        /*
         * ⓘ পিভট ধরে, দুইটা একসাথে গোনা হয়।
         *
         * ⚠️ কেবল `counter`-টা যাচাই করলে যথেষ্ট হত না: ব্যবহারকারী
         * চলতি কোম্পানিতে আছেন সেটাও ধরে নেওয়া যায় না — সুইচারের
         * বাইরে থেকেও প্রসঙ্গ বসতে পারে।
         */
        $mine = $user->companies()
            ->whereIn('companies.id', [$own, $counterId])
            ->pluck('companies.id')
            ->all();

        if (count($mine) !== 2) {
            throw ValidationException::withMessages([
                'counter_company_id' => __('accounts::validation.inter_company_not_mine'),
            ]);
        }

        return Company::query()->withoutGlobalScopes()->findOrFail($counterId);
    }

    /**
     * ⚠️ টাকার খাত, আর চলতি কোম্পানিরই।
     *
     * ⓘ `Account::query()` [[BelongsToCompany]]-র স্কোপে পড়ে, তাই অন্য
     * কোম্পানির খাতের আইডি দিলে সারিটাই পাওয়া যায় না — এটাই দেয়াল।
     */
    private function assertMoneyAccount(mixed $id, string $field): Account
    {
        $account = Account::query()->find((int) $id);

        if ($account === null || $account->is_group || ! in_array($account->money_kind, Account::MONEY_KINDS, true)) {
            /*
             * ⚠️ ঘরের নামটা প্যারামিটারে, হাতে লেখা নয়।
             *
             * ⛔ প্রথম লেখায় সবসময় `from_account_id` বসানো ছিল, আর
             * তাতে **অন্য কোম্পানির** খাতে ভুল হলেও লাল লেখাটা আমাদের
             * ঘরের নিচে দেখাত। ⓘ ব্যবহারকারী তখন ঠিক ঘরটা বারবার বদলে
             * দেখতেন আর বুঝতেই পারতেন না কোনটা নিয়ে অভিযোগ।
             */
            throw ValidationException::withMessages([
                $field => __('accounts::validation.inter_company_needs_money_account'),
            ]);
        }

        return $account;
    }

    /**
     * ভাই-কোম্পানির চলতি খাত — না থাকলে কাজটা থামে।
     *
     * ⚠️ কোম্পানি প্রমিত ছক বদলে ফেলতে পারে, তাই খাতটা না-ও থাকতে
     * পারে। ⛔ এখানে বিকল্প খাতে বসানো হয় না: আন্তঃকোম্পানির টাকা
     * "বিবিধ"-এ বসালে দুই পাশ আর কোনোদিন মেলানো যেত না।
     */
    private function assertControl(): Account
    {
        $account = StandardChart::find(StandardChart::INTER_COMPANY);

        if ($account === null || $account->is_group) {
            throw ValidationException::withMessages([
                'counter_company_id' => __('accounts::validation.inter_company_no_control', [
                    'code' => StandardChart::INTER_COMPANY,
                ]),
            ]);
        }

        return $account;
    }

    private function assertAmount(mixed $value): string
    {
        $amount = (string) $value;

        if (! is_numeric($amount) || bccomp($amount, '0', self::SCALE) !== 1) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.inter_company_amount'),
            ]);
        }

        return bcadd($amount, '0', self::SCALE);
    }

    /**
     * ⓘ বর্ণনায় অন্য কোম্পানির নামটা বসে, কারণ ছয় মাস পরে খতিয়ানের
     * সারিটা দেখে কেউ বলতে পারবে না কার সাথে লেনদেন — আর চলতি খাতটা
     * সব ভাই-কোম্পানির জন্য একটাই।
     */
    private function narration(string $other, string $purpose): string
    {
        return $other.' — '.$purpose;
    }
}
