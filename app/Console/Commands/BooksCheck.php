<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Integrity\IntegrityRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * খাতা মেলে কি না — রোজ, কেউ না চাইলেও।
 *
 * ── কী ভাঙা ছিল, ৩১ আগস্ট ২০২৬ ───────────────────────────────────────
 * বইয়ের যাচাইগুলো তৈরিই ছিল ([[IntegrityRegistry]] — হিসাব, বিক্রয়, ক্রয়
 * ও শাসন, চারটা মডিউলের), কিন্তু ওখানে পৌঁছানোর পথ ছিল **একটাই**:
 * কেউ `/accounts/books-check` পর্দাটা খুললে।
 *
 * `routes/console.php`-এ নির্ধারিত কাজ ছিল **একটাই** — ব্যাকআপ। অর্থাৎ
 * খাতা মেলে কি না, সেই প্রশ্নটা কেউ মনে করে না-করা পর্যন্ত করাই হত না।
 *
 * ── কেন এটা ব্যাকআপের মতোই জরুরি ─────────────────────────────────────
 * ব্যাকআপের বেলায় এই শিক্ষাটা আগেই নেওয়া হয়েছে ([[BackupDue]]): যে
 * জিনিসটার অনুপস্থিতি নীরব, সেটা মানুষের মনে রাখার উপর ছেড়ে দেওয়া
 * যায় না। ভাঙা খাতাও ঠিক তেমন — রেওয়ামিল না মিললে পর্দায় কোনো লাল
 * বাতি জ্বলে না, শুধু একদিন কেউ একটা সংখ্যা মেলাতে গিয়ে আটকে যান, আর
 * ততদিনে ভুলটা কবেকার তা আর বলা যায় না।
 *
 * **আজ ভাঙল** জানা আর **কোনো এক সময় ভেঙেছিল** জানা — দুইটার মধ্যে
 * পার্থক্যটাই এই কমান্ড।
 *
 * ── কেন কোম্পানি ধরে ধরে ─────────────────────────────────────────────
 * যাচাইগুলো `CompanyContext` ধরে কোয়েরি করে, আর কনসোলে কোনো প্রসঙ্গ
 * থাকে না। প্রসঙ্গ না বসিয়ে চালালে [[BelongsToCompany]] ঠিক কাজটাই
 * করত — ব্যতিক্রম ছুঁড়ত। তাই প্রতিটা কোম্পানিতে আলাদা করে ঢুকে দেখা
 * হয়; একটার খাতা ভাঙা মানে অন্যটারও ভাঙা নয়।
 *
 * ── কেন ব্যর্থতা critical লগে ────────────────────────────────────────
 * ব্যাকআপ ব্যর্থ হলে যেভাবে চিৎকার করা হয়, ঠিক সেভাবেই। নীরব ব্যর্থতা
 * মানে সবাই ভাবে খাতা মিলছে, অথচ মিলছে না।
 */
class BooksCheck extends Command
{
    protected $signature = 'abos:books-check
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'প্রতিটা কোম্পানির খাতা মেলে কি না দেখে, না মিললে চিৎকার করে';

    public function handle(IntegrityRegistry $registry): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('কোনো কোম্পানি পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $broken = 0;

        foreach ($companies as $company) {
            $broken += $this->checkOne($registry, $company);
        }

        if ($broken > 0) {
            /*
             * ফেরত মান ব্যর্থতা — শিডিউলারের `onFailure()` তখনই কাজ করে,
             * আর ভবিষ্যতে কেউ একটা ইমেইল বা বার্তা জুড়তে চাইলে জায়গাটা
             * প্রস্তুত থাকে।
             */
            return self::FAILURE;
        }

        $this->info('সব কোম্পানির খাতা মিলেছে।');

        return self::SUCCESS;
    }

    /** কয়টা যাচাই ভাঙল। */
    private function checkOne(IntegrityRegistry $registry, Company $company): int
    {
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $broken = 0;

        try {
            foreach ($registry->all() as $check) {
                $findings = $check->run();

                if ($findings === []) {
                    continue;
                }

                $broken++;

                $message = sprintf(
                    'বইয়ের যাচাই ভেঙেছে — %s · %s · %d টি সারি। %s',
                    $company->code,
                    $check->label,
                    count($findings),
                    $check->whenBroken,
                );

                $this->error($message);
                logger()->critical($message, [
                    'company' => $company->code,
                    'check' => $check->key,
                    'findings' => count($findings),
                ]);
            }
        } finally {
            // প্রসঙ্গ রেখে বেরোলে পরের কোম্পানির যাচাই আগেরটার ডেটা দেখত
            CompanyContext::clear();
        }

        if ($broken === 0) {
            $this->line("  {$company->code} — মিলেছে।");
        }

        return $broken;
    }
}
