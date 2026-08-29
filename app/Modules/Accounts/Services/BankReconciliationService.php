<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ব্যাংকের কাগজ আর খাতা মেলানো।
 *
 * ── যে অঙ্কটা মিলতেই হবে ─────────────────────────────────────────────
 *     ব্যাংকের কাগজের জের
 *       −  যে জমাগুলো ব্যাংক এখনো দেখেনি   (খাতায় ডেবিট, টিক নেই)
 *       +  যে চেকগুলো এখনো ভাঙানো হয়নি     (খাতায় ক্রেডিট, টিক নেই)
 *       =  খাতার জের, ওই তারিখে
 *
 * confirm করার আগে এই সমতাটা যাচাই হয়। না মিললে confirm হয় না — কারণ
 * না-মেলা মিলকরণ কোনো মিলকরণ নয়, ওটা কেবল একটা মিথ্যা আশ্বাস যে কেউ
 * দেখেছে।
 *
 * ── এই ক্লাস কোনো ভাউচার বসায় না ─────────────────────────────────────
 * ইচ্ছাকৃতভাবে PostingEngine এখানে নেই। মিলকরণ কেবল চিহ্ন দেয়। ব্যাংক
 * চার্জ খাতায় না থাকলে সেটা একটা সাধারণ ভাউচার হয়ে বসবে, নিজের
 * তারিখে, নিজের অনুমোদন নিয়ে — কারণ মিলকরণকে খাতা বদলাতে দিলে তফাতটাই
 * হারিয়ে যায়, অথচ তফাতই একমাত্র জিনিস যেটা ভুলের দিকে আঙুল তোলে।
 */
final class BankReconciliationService
{
    /**
     * একটা মিলকরণ খোলা।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): BankReconciliation
    {
        $account = Account::query()->findOrFail((int) $data['bank_account_id']);

        if (! $account->is_bank) {
            throw ValidationException::withMessages([
                'bank_account_id' => __('accounts::recon.not_a_bank_account'),
            ]);
        }

        $date = Carbon::parse((string) $data['statement_date'])->toDateString();

        return BankReconciliation::create([
            'company_id' => CompanyContext::id(),
            'branch_id' => $data['branch_id'] ?? null,
            'bank_account_id' => $account->id,
            'statement_date' => $date,
            'statement_balance' => Money::of((string) $data['statement_balance']),
            'status' => BankReconciliation::DRAFT,
            'narration' => $data['narration'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * ওই হিসাবের যে লাইনগুলো এই কাগজে থাকতে পারে।
     *
     * তিনটা ছাঁকনি, তিনটাই আলাদা কারণে:
     *
     * তারিখের পরের কোনো লাইন নয় — ব্যাংকের কাগজে ওটা থাকতেই পারে না।
     *
     * কেবল POSTED ভাউচার (confirmed বা closed)। খসড়া ভাউচার খাতাতেই
     * বসেনি, তাই ব্যাংকের কাগজেও থাকার কথা নয়; ওগুলো তালিকায় এলে
     * মিলকরণ এমন জিনিসে টিক দিত যা এখনো ঘটেইনি। বাতিলগুলোও একই কারণে
     * বাদ।
     *
     * আর অন্য কোনো মিলকরণে টিক পড়া লাইন নয় — নাহলে একই সারি দুইবার
     * গোনা হত, আর দুই মাসের অঙ্কই মিথ্যা হয়ে যেত।
     *
     * @return Collection<int, VoucherLine>
     */
    public function candidates(BankReconciliation $recon): Collection
    {
        return VoucherLine::query()
            ->with('voucher')
            ->where('account_id', $recon->bank_account_id)
            ->whereHas('voucher', fn ($q) => $q
                ->where('company_id', $recon->company_id)
                ->whereIn('status', DocumentStatus::POSTED)
                ->whereDate('trx_date', '<=', $recon->statement_date->toDateString()))
            ->where(fn ($q) => $q
                ->whereNull('reconciliation_id')
                ->orWhere('reconciliation_id', $recon->id))
            ->get();
    }

    /**
     * টিক বসানো ও তোলা — একবারে, পুরো তালিকাটা ধরে।
     *
     * @param  array<int, int|string>  $lineIds  যেগুলোতে টিক থাকবে
     */
    public function mark(BankReconciliation $recon, array $lineIds): BankReconciliation
    {
        $this->assertDraft($recon);

        $allowed = $this->candidates($recon)->pluck('id')->all();
        $wanted = array_values(array_intersect(array_map('intval', $lineIds), $allowed));

        return DB::transaction(function () use ($recon, $allowed, $wanted) {
            /*
             * আগে সব টিক তোলা, তারপর চাওয়াগুলো বসানো।
             *
             * শুধু "যোগ" করলে তোলা কখনো ঘটত না, আর একবার ভুল করে বসানো
             * টিক চিরকাল থেকে যেত — অথচ টিক তোলাই মিলকরণের অর্ধেক কাজ।
             */
            VoucherLine::query()->whereIn('id', $allowed)
                ->where('reconciliation_id', $recon->id)
                ->update(['reconciliation_id' => null]);

            if ($wanted !== []) {
                VoucherLine::query()->whereIn('id', $wanted)
                    ->update(['reconciliation_id' => $recon->id]);
            }

            return $recon->refresh();
        });
    }

    /**
     * তফাতের হিসাব — পর্দায় যা দেখানো হয়, আর confirm যা যাচাই করে।
     *
     * @return array{ledger: string, statement: string, deposits: string, cheques: string, expected: string, difference: string, agrees: bool}
     */
    public function summary(BankReconciliation $recon): array
    {
        $lines = $this->candidates($recon);

        $ledger = '0';
        $deposits = '0';   // খাতায় ডেবিট, টিক নেই — ব্যাংক এখনো দেখেনি
        $cheques = '0';    // খাতায় ক্রেডিট, টিক নেই — এখনো ভাঙানো হয়নি

        foreach ($lines as $line) {
            $ledger = bcsub(bcadd($ledger, (string) $line->debit, 4), (string) $line->credit, 4);

            if ($line->reconciliation_id !== null) {
                continue;
            }

            $deposits = bcadd($deposits, (string) $line->debit, 4);
            $cheques = bcadd($cheques, (string) $line->credit, 4);
        }

        /*
         * হিসাবের খোলা জেরটাও ধরতে হয় — নাহলে যে কোম্পানি খোলা জের নিয়ে
         * শুরু করেছে, তার প্রথম মিলকরণ কোনোদিন মিলত না, আর কারণটা কেউ
         * খুঁজে পেত না।
         */
        /*
         * ── খোলার জের এখানে আর যোগ হয় না, ২৯ আগস্ট ২০২৬ ─────────────
         * জেরটা এখন সত্যিকারের দাখিলা হয়ে খতিয়ানেই বসে
         * ([[OpeningBalanceService]]), তাই উপরের যোগফলে ওটা ইতিমধ্যেই
         * আছে — আবার যোগ করলে "আমাদের খাতা বলে" সংখ্যাটা ঠিক জেরের
         * পরিমাণ বেশি দেখাত।
         *
         * HP-র রিপোর্টে এই পর্দার দুইটা সংখ্যা "ব্যাখ্যাহীন" বলা
         * হয়েছিল, আর সম্ভাব্য কারণ হিসেবে জেরের বাগটাকেই সন্দেহ করা
         * হয়েছিল — সন্দেহটা ঠিক ছিল।
         */

        $statement = (string) $recon->statement_balance;
        $expected = bcadd(bcsub($statement, $deposits, 4), $cheques, 4);
        $difference = bcsub($ledger, $expected, 4);

        return [
            'ledger' => $ledger,
            'statement' => $statement,
            'deposits' => $deposits,
            'cheques' => $cheques,
            'expected' => $expected,
            'difference' => $difference,
            'agrees' => bccomp($difference, '0', 4) === 0,
        ];
    }

    /**
     * মিলকরণ বন্ধ করা।
     *
     * অঙ্ক না মিললে আটকায়। এটাই পুরো ফিচারটার একমাত্র শক্ত দরজা — এটা
     * না থাকলে যে কেউ একটা মিলকরণ খুলে, কিছু না মিলিয়ে, "হয়ে গেছে" বলে
     * বন্ধ করে দিতে পারত, আর কাগজে দেখাত কাজটা হয়েছে।
     */
    public function confirm(BankReconciliation $recon): BankReconciliation
    {
        $this->assertDraft($recon);

        $summary = $this->summary($recon);

        if (! $summary['agrees']) {
            throw ValidationException::withMessages([
                'statement_balance' => __('accounts::recon.does_not_agree', [
                    'difference' => $summary['difference'],
                ]),
            ]);
        }

        $recon->update([
            'status' => BankReconciliation::CONFIRMED,
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        return $recon->refresh();
    }

    /**
     * বন্ধ করা মিলকরণ আবার খোলা।
     *
     * সম্ভব, কিন্তু নিজে থেকে নয় — আলাদা অনুমতি লাগে। খোলা মানে গত
     * মাসের বন্ধ করা অঙ্কটা আবার নড়ানো যায়, আর সেটা হালকা কাজ নয়।
     */
    public function reopen(BankReconciliation $recon): BankReconciliation
    {
        if (! $recon->isConfirmed()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::recon.not_confirmed'),
            ]);
        }

        $recon->update([
            'status' => BankReconciliation::DRAFT,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ]);

        return $recon->refresh();
    }

    private function assertDraft(BankReconciliation $recon): void
    {
        if (! $recon->isDraft()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::recon.already_confirmed'),
            ]);
        }
    }
}
