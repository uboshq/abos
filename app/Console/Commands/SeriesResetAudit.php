<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\NumberSeriesProvisioner;
use App\Models\NumberSeries;
use Illuminate\Console\Command;

/**
 * বছরহীন ছকে বসে থাকা বছর-রিসেট খুঁজে বের করা, আর বললে নিভিয়ে দেওয়া।
 *
 * ── ⛔ কেন এটা লাগল, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * লাইভে `number_series`-এর ১৬০টা সারির **১৬০টাতেই** `reset_yearly` চালু,
 * আর **একটাতেও** ছকে বছর নেই। ⓘ কেউ ঘরটা লেখেনি — [[NumberSeriesProvisioner]]
 * মানটা বসাতই না, আর কলামের নিজের default `true`।
 *
 * ⚠️ ফল: বছর বন্ধ হলে গুনতি ১-এ ফেরে, নম্বর হয় আগের বছরের হুবহু সমান,
 * `issued_numbers`-এর unique সেটা আটকায়, লেনদেন rollback হয় — আর
 * rollback-এ গুনতির বাড়াটাও মুছে যায়। ⛔ নতুন বছরের প্রথম কাগজটা তখন
 * **কখনো** কাটা যায় না, যতবারই চেষ্টা হোক।
 *
 * ── ⭐ কোডটা সারানো হয়েছে, কিন্তু পুরনো সারিগুলো সারায় না ──────────────
 * এখন সারিটা নিজেই ভুল মানটা আর বসতে দেয় না ([[NumberSeries::booted()]]),
 * আর বছর বদলের সময় ছকটা দেখে সিদ্ধান্ত হয়। ⓘ কিন্তু ডাটাবেজে বসে থাকা
 * ১৬০টা সারি আগের মতোই ভুল — সেগুলো কেউ সেভ না করা পর্যন্ত শোধরায় না।
 * এই কমান্ডটা ঠিক সেই কাজটা করে।
 *
 * ── ⚠️ ডিফল্টে কিছুই লেখে না ──────────────────────────────────────────
 * ⛔ লাইভের নম্বর সিরিজ এমন জিনিস নয় যেটা "চালিয়ে দেখি" করা যায়। তাই
 * ডিফল্টে কেবল রিপোর্ট; লিখতে হলে `--apply` স্পষ্ট করে বলতে হয়।
 *
 * ⓘ পতাকা নিভলেও যে কোম্পানির বছর **ইতিমধ্যে** বন্ধ হয়ে গেছে তার গুনতি
 * আটকেই থাকে — ওটা সামনে আনে `abos:catch-up-numbers`, আর সে
 * `issued_numbers`-ও পড়ে। দুইটা একসাথে চালানোই পুরো সারাই।
 */
class SeriesResetAudit extends Command
{
    protected $signature = 'abos:series-reset-audit
        {--company= : কেবল এই কোম্পানির সারিগুলো}
        {--apply : সত্যিই নিভিয়ে দাও (ডিফল্টে কেবল দেখায়)}';

    protected $description = 'বছরহীন ছকে বছর-রিসেট চালু আছে কি না দেখে, আর --apply দিলে নিভিয়ে দেয়';

    public function handle(): int
    {
        /*
         * ⚠️ `withoutGlobalScopes()` — কমান্ডটা কনসোল থেকে চলে, কোনো
         * কোম্পানির ভিতর থেকে নয়। ⓘ স্কোপ থাকলে এটা **শূন্য সারি** দেখাত
         * আর "সব ঠিক আছে" বলে চলে যেত, যেটা সবচেয়ে খারাপ উত্তর।
         */
        $wrong = NumberSeries::query()
            ->withoutGlobalScopes()
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->orderBy('company_id')
            ->orderBy('financial_year_id')
            ->orderBy('doc_type')
            ->get()
            ->filter(fn (NumberSeries $s) => $s->reset_yearly
                && ! NumberSeriesProvisioner::resetsWith((string) $s->format));

        if ($wrong->isEmpty()) {
            $this->info('বছরহীন ছকে বছর-রিসেট কোথাও চালু নেই।');

            return self::SUCCESS;
        }

        $this->warn($wrong->count().'টা সারিতে বছর-রিসেট চালু, অথচ ছকে বছর নেই:');
        $this->newLine();

        foreach ($wrong->groupBy('company_id') as $companyId => $rows) {
            $this->line("  কোম্পানি {$companyId} — {$rows->count()}টা সারি");

            /*
             * ⓘ প্রতিটা সারি আলাদা করে ছাপা হয় না — ১৬০টা লাইন পড়ে কেউ
             * সিদ্ধান্ত নেন না। ছক ধরে দল বাঁধলে বোঝা যায় রোগটা একটাই।
             */
            foreach ($rows->groupBy('format') as $format => $sameFormat) {
                $this->line(sprintf(
                    '      %-22s  %s',
                    $format,
                    $sameFormat->pluck('doc_type')->unique()->sort()->implode(', '),
                ));
            }
        }

        $this->newLine();

        if (! $this->option('apply')) {
            $this->line('কিছুই বদলানো হয়নি। নিভিয়ে দিতে: php artisan abos:series-reset-audit --apply');
            $this->line('তারপর আটকে থাকা গুনতি সামনে আনতে: php artisan abos:catch-up-numbers');

            return self::SUCCESS;
        }

        /*
         * ⚠️ সারি ধরে সেভ, গণ-আপডেট নয় — প্রতিটা বদল তখন অডিটে ওঠে, আর
         * নম্বরের নিয়ম বদলানো মানুষের জানার মতো ঘটনা।
         *
         * ⛔ মানটা এখানে **স্পষ্ট করে** `false` বসানো হয়, সারিটার নিজের
         * পাহারার ভরসায় নয়। ⓘ ভরসা করলে কোডটা পড়ে মনে হত এই কমান্ড
         * রিসেট **চালু** করছে, আর পাহারাটা কোনোদিন সরলে সেটাই করত।
         */
        foreach ($wrong as $series) {
            $series->reset_yearly = false;
            $series->save();
        }

        $this->info($wrong->count().'টা সারিতে বছর-রিসেট নিভিয়ে দেওয়া হয়েছে।');
        $this->line('এবার চালান: php artisan abos:catch-up-numbers');

        return self::SUCCESS;
    }
}
