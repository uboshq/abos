<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Finance\Services\DueNotices;
use Illuminate\Console\Command;

/**
 * যে তারিখগুলো পর্দায় গোনা হত, সেগুলো এখন নিজেরাই খবর পাঠায়।
 *
 * ── ⛔ কী ভাঙা ছিল, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * অর্থের মানচিত্র §১৪ক ও §১৪খ — জমার মেয়াদ আর হাতধারের তাগাদা দুইটাই
 * **পর্দায় গোনা হত, বিজ্ঞপ্তি যেত না**। ⚠️ গোনাটা কাজে লাগে কেবল
 * পাতাটা কেউ খুললে, অথচ তারিখ ফসকায় ঠিক ওই দিনগুলোতেই যেদিন কেউ
 * পাতাটা খোলেননি — [[BooksCheck]]-এর মাথায় লেখা একই শিক্ষা: যে
 * জিনিসের অনুপস্থিতি নীরব, সেটা মানুষের মনে রাখার উপর ছাড়া যায় না।
 *
 * ── ⓘ কেন কোম্পানি ধরে ধরে ──────────────────────────────────────────
 * কোয়েরিগুলো `CompanyContext` ধরে চলে, আর কনসোলে কোনো প্রসঙ্গ থাকে না।
 * প্রসঙ্গ না বসিয়ে চালালে [[BelongsToCompany]] ঠিক কাজটাই করত —
 * ব্যতিক্রম ছুঁড়ত। ⓘ একটার মেয়াদ আসা মানে অন্যটারও নয়।
 *
 * ── ⚠️ কেন ব্যর্থতা নীরব নয় ─────────────────────────────────────────
 * একটা কোম্পানিতে আটকে গেলে বাকিগুলোর খবরও যেত না। তাই প্রতিটা
 * আলাদা করে ধরা হয়, ভাঙাটা লগে ওঠে, আর ফেরত মান ব্যর্থতা — শিডিউলারের
 * `onFailure()` তখনই ডাকে।
 */
class MoneyDue extends Command
{
    protected $signature = 'abos:money-due
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'মেয়াদ ও তাগাদার আগাম খবর পাঠায় — জমা ও হাতধার';

    public function handle(DueNotices $notices): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('কোনো কোম্পানি পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $broke = false;
        $maturing = 0;
        $handLoans = 0;

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            try {
                $sent = $notices->sendAll();

                $maturing += $sent['maturing'];
                $handLoans += $sent['hand_loans'];
            } catch (\Throwable $e) {
                $broke = true;

                $this->error("{$company->code}: {$e->getMessage()}");

                logger()->error('মেয়াদ ও তাগাদার খবর পাঠানো যায়নি।', [
                    'company' => $company->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("মেয়াদের খবর {$maturing}টি, হাতধারের তাগাদা {$handLoans}টি।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
