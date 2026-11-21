<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Services\CostNarration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ আগের ভাড়ার ভাউচারের বিবরণ নতুন ক্রমে — মালিক, ২১ নভেম্বর ২০২৬ (নমুনা v6, ক; [[CostNarration]])।
 *
 * ⓘ কেবল যন্ত্রের নিজে লেখা ভাড়ার ভাউচার: খরচ ভাউচার (EV), কাউন্টারের (`origin counter`), চালান বা ট্রিপের বিপরীতে, আর
 * সেই কাগজের `fare_voucher_id` এই ভাউচারই। ⛔ বিবরণ এখনো হুবহু আগের যন্ত্র-লেখা হলে তবেই বদলায় — কেউ হাতে বদলালে
 * সেটা তাঁর লেখা, ছোঁয়া হয় না। আগের লেখা আবার বানিয়ে মেলানো হয় (`narration_v1` / `trip_narration_v1`), বাংলা আর
 * ইংরেজি দুই ভাষাতেই — যে ভাষায় মেলে, নতুনটাও সেই ভাষায়।
 *
 * ── যা কখনো ছোঁয় না ──────────────────────────────────────────────────────────
 *  - ⛔ অঙ্ক, খাত, তারিখ — কেবল `vouchers.narration` আর সেই ভাউচারের নিজের সারির `narration` (সারিরটা কেবল আগের লেখার
 *    সমান হলে)।
 *  - ⛔ খাতার সারি (`ledger_entries`) — হ্যাশ-শিকলে বাঁধা, মডেল বদলাতে দেয় না। তাই লোডিং (ভাউচার নেই, সরাসরি খাতায়
 *    [[ChallanLoading]]) আর পরে-দেব ট্রিপের দেনা (খাতায় [[FarePayment::bookTrip()]]) আগের লেখাতেই থাকে; কেবল নতুনগুলো
 *    নতুন লেখা পায়।
 *  - ⛔ অনুমোদনের সারি আছে এমন ভাউচার — বিবরণ সইয়ের ছাপের অংশ ([[DocumentFingerprint]]); বদলালে "সইয়ের পরে বদলেছে"
 *    দেখাত, অপেক্ষার সই বাতিল হত। এগুলো বাদ, আর তালিকায় কারণসহ।
 *
 * ⓘ `--apply` ছাড়া কেবল দেখায়। আবার চালালে বিবরণ আর আগের লেখার সমান নয়, তাই কিছুই বদলায় না।
 */
class FareNarrations extends Command
{
    protected $signature = 'sales:fare-narrations
        {--apply : সত্যিই লেখে — না দিলে কেবল দেখায় কী বদলাবে}
        {--company= : কেবল এই কোম্পানির কোড (যেমন AVA)}';

    protected $description = 'আগের ভাড়ার ভাউচারের যন্ত্র-লেখা বিবরণ নতুন ক্রমে (খাত — চালান, সব পয়েন্ট, গাড়ি, ড্রাইভার); অঙ্ক বা খাতা ছোঁয় না';

    /** যে দুই ভাষায় আগের লেখা থাকতে পারে */
    private const LOCALES = ['bn', 'en'];

    public function handle(CostNarration $narration): int
    {
        $apply = (bool) $this->option('apply');

        $companies = Company::query()->orderBy('id')
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        // ⛔ অচেনা কোড চুপচাপ "০টা বদলাবে" নয় — জোরে থামা
        if (filled($this->option('company')) && $companies->isEmpty()) {
            $this->error('  কোনো কোম্পানির কোড "'.$this->option('company').'" নয় — কিছুই দেখা বা লেখা হয়নি।');

            return self::FAILURE;
        }

        $rows = [];
        $changed = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            CompanyContext::forCompany((int) $company->id, function () use ($company, $narration, $apply, &$rows, &$changed, &$skipped): void {
                $vouchers = Voucher::query()->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('type', Voucher::EXPENSE)
                    ->where('origin', Voucher::ORIGIN_COUNTER)
                    ->whereIn('against_type', [DeliveryChallan::drillSourceType(), Shipment::drillSourceType()])
                    ->whereNotNull('against_id')
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->get();

                foreach ($vouchers as $voucher) {
                    $paper = $this->paperOf($voucher);

                    // ⓘ কাগজের ভাড়ার ভাউচার এটা নয় (বা কাগজ নেই) — যন্ত্রের লেখা কি না বলা যায় না
                    if ($paper === null) {
                        continue;
                    }

                    [$old, $new] = $this->rewrite($narration, $paper, (string) $voucher->narration);

                    if ($old === null) {
                        continue; // ⓘ হাতে বদলানো, বা ইতিমধ্যে নতুন লেখা — ছোঁয়া নয়
                    }

                    if ($this->hasApprovals($voucher)) {
                        $skipped++;
                        $rows[] = [$company->code, $voucher->document_no, $paper->document_no, $old, __('sales::fare.narration_skip_approval')];

                        continue;
                    }

                    // ⓘ লেখার সময় তালার ভেতরে আবার দেখা হয় — এর মধ্যে বদলালে বা অনুমোদন বসলে বাদ, আর গোনাও হয় না
                    if ($apply && ! $this->write($voucher, $old, $new)) {
                        $skipped++;
                        $rows[] = [$company->code, $voucher->document_no, $paper->document_no, $old, __('sales::fare.narration_skip_approval')];

                        continue;
                    }

                    $changed++;
                    $rows[] = [$company->code, $voucher->document_no, $paper->document_no, $old, $new];
                }
            });
        }

        if ($rows !== []) {
            $this->table(['কোম্পানি', 'ভাউচার', 'কাগজ', 'আগে', 'এখন'], $rows);
        }

        $this->line($apply
            ? "  বদলানো হলো: {$changed}টা ভাউচার; বাদ (অনুমোদনের সারি আছে): {$skipped}টা"
            : "  --apply দিলে বদলাবে: {$changed}টা ভাউচার; বাদ থাকবে (অনুমোদনের সারি আছে): {$skipped}টা — এখন কিছুই লেখা হয়নি");
        $this->line('  ⓘ লোডিং আর পরে-দেব ট্রিপের দেনা খাতায় সরাসরি বসে (ভাউচার নেই) — খাতার সারি বদলানো যায় না, তাই কেবল নতুনগুলো নতুন লেখা পায়।');
        $this->line('  ⓘ অঙ্ক, খাত আর খাতার সারি ছোঁয়া হয়নি।');

        return self::SUCCESS;
    }

    /** ভাউচারটা যার ভাড়ার ভাউচার — কাগজের `fare_voucher_id` এটাই হলে */
    private function paperOf(Voucher $voucher): DeliveryChallan|Shipment|null
    {
        $model = $voucher->against_type === Shipment::drillSourceType() ? Shipment::class : DeliveryChallan::class;

        $paper = $model::query()->withoutGlobalScopes()
            ->where('company_id', $voucher->company_id)
            ->whereKey((int) $voucher->against_id)
            ->where('fare_voucher_id', $voucher->id)
            ->whereNull('deleted_at')
            ->first();

        return $paper instanceof DeliveryChallan || $paper instanceof Shipment ? $paper : null;
    }

    /**
     * আগের যন্ত্র-লেখার সমান হলে [আগের, নতুন]; নইলে [null, null]। নতুনটা আগেরটার সমান হলে বদলানোর কিছু নেই।
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rewrite(CostNarration $narration, DeliveryChallan|Shipment $paper, string $current): array
    {
        foreach (self::LOCALES as $locale) {
            $legacy = $narration->legacyNarration($paper, $locale);

            if ($legacy !== $current) {
                continue;
            }

            $new = $narration->fareNarration($paper, $locale);

            return $new === $current ? [null, null] : [$current, $new];
        }

        return [null, null];
    }

    private function hasApprovals(Voucher $voucher): bool
    {
        return Approval::query()->withoutGlobalScopes()
            ->whereIn('approvable_type', array_unique([Voucher::class, $voucher->getMorphClass()]))
            ->where('approvable_id', $voucher->id)
            ->exists();
    }

    /** কেবল বিবরণ — মাথার, আর নিজের সারির যেগুলো আগের লেখার সমান; অঙ্ক নয় */
    private function write(Voucher $voucher, string $old, string $new): bool
    {
        return DB::transaction(function () use ($voucher, $old, $new): bool {
            $fresh = Voucher::query()->withoutGlobalScopes()->lockForUpdate()->find($voucher->id);

            // ⛔ এর মধ্যে কেউ বদলালে তাঁর লেখা থাকে; আর অনুমোদনের সারি এর মধ্যে বসলে ছোঁয়া নয় — দেখাটা তালার ভেতরে
            //   (স্বাধীন রিভিউ, ২১ নভেম্বর ২০২৬: আগে দেখা তালার বাইরে ছিল, মাঝে অনুমোদন বসলে ছাপ ভাঙত)
            if ($fresh === null || (string) $fresh->narration !== $old || $this->hasApprovals($fresh)) {
                return false;
            }

            $fresh->forceFill(['narration' => $new])->save();

            VoucherLine::query()->withoutGlobalScopes()
                ->where('voucher_id', $fresh->id)
                ->where('narration', $old)
                ->get()
                ->each(fn (VoucherLine $line) => $line->forceFill(['narration' => $new])->save());

            return true;
        });
    }
}
