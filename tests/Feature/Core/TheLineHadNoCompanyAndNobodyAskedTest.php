<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * লাইনের নিজের কোম্পানি ছিল না, আর কেউ জিজ্ঞেসও করেনি।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ২.৩, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"১৪টি মডেলে `company_id` নেই — সবগুলোই চাইল্ড সারি। আজ এগুলো নিরাপদ,
 * কারণ প্যারেন্ট স্কোপযুক্ত। কিন্তু নিরাপত্তাটা **পরোক্ষ** — কেউ সরাসরি
 * `SalesInvoiceLine::find($id)` লিখলে কোনো দেয়াল নেই।"*
 *
 * ⚠️ "আজ নিরাপদ" কথাটার ভরসা ছিল একটা **অভ্যাসের** উপর: সবাই সবসময় কাগজ
 * ধরে লাইনে পৌঁছাবেন। ⛔ অভ্যাস দেয়াল নয় — একজন একবার সরাসরি কোয়েরি
 * লিখলেই এক কোম্পানির চালানের লাইন আরেক কোম্পানির পর্দায় চলে আসত।
 *
 * ── ⭐ কেন এই ফাইলটা কনফিগ পড়ে না ────────────────────────────────────
 * ট্রেইট বসানো আছে কি না দেখা সহজ, আর **অর্থহীন** — ট্রেইট বসেও কাজ না
 * করতে পারে (ভুল সম্পর্কের নাম, প্যারেন্টে `company_id` নেই)। ⓘ তাই
 * দাবিটা একটাই আকারের: **দুইটা কোম্পানি বানাও, একটার সারি লেখো, অন্যটার
 * হয়ে খোঁজো — আর কিছু না পাওয়াই প্রমাণ।**
 */
final class TheLineHadNoCompanyAndNobodyAskedTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code): Company
    {
        return Company::query()->create([
            'code' => $code,
            'name_en' => $code.' Ltd',
            'name_bn' => $code.' লিমিটেড',
        ]);
    }

    /**
     * ⓘ কোম্পানিপ্রতি একটাই শাখা — দুইবার ডাকলে আগেরটাই ফেরত আসে।
     *
     * ⚠️ প্রথমে প্রতিবার নতুন সারি বানাত, আর পরীক্ষাটা কোম্পানি বদলে
     * তিনবার ডাকে — ফলে `branches.company_id + code` অনন্যতা ভেঙেছিল।
     * ⛔ ভুলটা দেয়ালের নয়, আমার সাজানোর — কিন্তু ফল একই: একটা লাল বাতি
     * যার সাথে মাপা জিনিসটার কোনো সম্পর্ক নেই।
     *
     * @var array<int, Branch>
     */
    private array $branches = [];

    private function branchOf(Company $company): Branch
    {
        return $this->branches[$company->id] ??= Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'BR'.$company->id,
            'name_en' => 'Head Office',
            'name_bn' => 'প্রধান কার্যালয়',
        ]);
    }

    /**
     * ⭐ আসল দাবি: এক কোম্পানির লাইন আরেক কোম্পানির চোখে পড়ে না।
     *
     * ⓘ [[VoucherLine]] দিয়ে মাপা, কারণ ওটাই সেই মডেল যার ডকব্লকে বহু
     * আগে লেখা হয়েছিল *"BelongsToCompany নেই ইচ্ছাকৃতভাবে: সারিটা কখনো
     * নিজে থেকে খোঁজা হয় না"* — অর্থাৎ পুরো নিরাপত্তাটা ঐ একটা বাক্যের
     * উপর দাঁড়িয়ে ছিল। ⚠️ এখন সেটা দাঁড়িয়ে আছে একটা `EXISTS`-এর উপর।
     */
    public function test_one_companys_line_is_invisible_to_another(): void
    {
        $mine = $this->company('AAA');
        $theirs = $this->company('BBB');

        $voucher = null;

        CompanyContext::set($theirs->id, $this->branchOf($theirs)->id);

        /*
         * ⓘ হিসাববর্ষটা এখানেই বসানো — সিডারের উপর ভরসা নয়।
         *
         * ⚠️ `vouchers.financial_year_id` NOT NULL, আর `RefreshDatabase`
         * খালি ডাটাবেজ দেয়। ⛔ ধরে নিলে পরীক্ষাটা এমন কারণে ভাঙত যার
         * সাথে কোম্পানি-স্কোপের কোনো সম্পর্ক নেই।
         */
        $year = FinancialYear::query()->create([
            'company_id' => $theirs->id,
            'name' => '২০২৬-২৭',
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
        ]);

        $voucher = Voucher::query()->create([
            'company_id' => $theirs->id,
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => $year->id,
            'type' => Voucher::JOURNAL,
            'document_no' => 'JV-OTHER-1',
            'trx_date' => now()->toDateString(),
            'amount' => '100.0000',
            'status' => 'draft',
        ]);

        /*
         * ⓘ খাতটাও এখানেই — `voucher_lines.account_id` NOT NULL।
         *
         * ⚠️ দুইবার এই ফাইলটা এমন কারণে ভেঙেছে যার সাথে কোম্পানি-স্কোপের
         * কোনো সম্পর্ক নেই (আগে `financial_year_id`, এখন `account_id`)।
         * ⛔ প্রতিবারই কারণ একটাই: খালি ডাটাবেজে যা যা **অবশ্যই** লাগে
         * তার তালিকা কোথাও লেখা নেই, আর ধরে নেওয়াটা কাজ করে না।
         */
        $account = Account::query()->create([
            'company_id' => $theirs->id,
            'code' => '1010',
            'name_en' => 'Cash in hand',
            'name_bn' => 'হাতে নগদ',
            'type' => 'asset',
            'nature' => 'debit',
        ]);

        $line = VoucherLine::query()->create([
            'voucher_id' => $voucher->id,
            'account_id' => $account->id,
            'debit' => '100.0000',
            'credit' => '0.0000',
            'sort_order' => 1,
        ]);

        /*
         * ⛔ সেটআপের দাবি — সারিটা সত্যিই আছে তো?
         *
         * ⚠️ না থাকলে নিচের "পাওয়া যায় না" কথাটা শূন্যের উপর সত্য হত,
         * আর পরীক্ষাটা চিরকাল সবুজ থেকে কিছুই পাহারা দিত না।
         */
        $this->assertSame(1, (int) DB::table('voucher_lines')
            ->where('id', $line->id)->count(),
            'সারিটাই বসেনি — তাহলে নিচের দাবিটা কিছুই মাপে না।');

        // ── এখন অন্য কোম্পানির হয়ে খোঁজা ─────────────────────────────
        CompanyContext::set($mine->id, $this->branchOf($mine)->id);

        $this->assertNull(VoucherLine::query()->find($line->id),
            'অন্য কোম্পানির লাইনটা সরাসরি খুঁজে পাওয়া গেছে — দেয়ালটা নেই।');

        $this->assertSame(0, VoucherLine::query()->count(),
            'গোনাতেও অন্য কোম্পানির সারি ধরা পড়ছে।');

        // ── আর নিজের কোম্পানিতে ফিরলে সারিটা আবার দেখা যায় ───────────
        CompanyContext::set($theirs->id, $this->branchOf($theirs)->id);

        $this->assertNotNull(VoucherLine::query()->find($line->id),
            'নিজের কোম্পানিতেও সারিটা হারিয়ে গেছে — দেয়ালটা বড্ড উঁচু।');
    }

    /**
     * ⛔ প্রতিটা চাইল্ড টেবিলের একটা করে দেয়াল থাকতেই হবে।
     *
     * ── কেন এই দাবিটা আলাদা ───────────────────────────────────────────
     * উপরেরটা একটা মডেল দিয়ে প্রমাণ করে **ব্যবস্থাটা কাজ করে**। ⚠️ কিন্তু
     * আগামীকাল কেউ একটা নতুন লাইন-টেবিল বসাবেন, আর সেটায় কিছুই না বসিয়ে
     * চলে যাবেন — ঠিক যেমন আজ বারোটা মডেলে কিছু ছিল না, আর কারো ডকব্লকে
     * একটা শব্দও লেখা ছিল না।
     *
     * ⭐ তাই নিয়মটা যন্ত্রের হাতে: `company_id` কলাম নেই এমন প্রতিটা মডেলকে
     * হয় [[BelongsToCompany]], নয় [[BelongsToCompanyThroughParent]] নিতে
     * হবে। তৃতীয় পথ নেই।
     */
    public function test_every_model_without_its_own_company_column_leans_on_its_parent(): void
    {
        $naked = [];

        foreach ($this->modelsUnderModules() as $class) {
            try {
                $model = new $class;
                $table = $model->getTable();
            } catch (\Throwable) {
                continue;
            }

            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            if (DB::getSchemaBuilder()->hasColumn($table, 'company_id')) {
                continue;
            }

            $traits = class_uses_recursive($class);

            if (isset($traits[BelongsToCompany::class]) || isset($traits[BelongsToCompanyThroughParent::class])) {
                continue;
            }

            $naked[] = $class;
        }

        $this->assertSame([], $naked, sprintf(
            "এই মডেলগুলোর নিজের `company_id` নেই, আর কোনো দেয়ালও নেই:\n  %s\n\n".
            "⭐ দুইটার একটা করুন —\n".
            "  ১. টেবিলে `company_id` বসিয়ে `BelongsToCompany`\n".
            "  ২. অথবা `BelongsToCompanyThroughParent` আর `companyParent()`-এ কাগজের নাম\n\n".
            '⚠️ "কেউ তো সরাসরি কোয়েরি লেখে না" কোনো দেয়াল নয় — ওটা একটা অভ্যাস।',
            implode("\n  ", $naked),
        ));
    }

    /**
     * ⓘ আর দেয়ালটা যেন সত্যিই দাঁড়াতে পারে।
     *
     * ⚠️ `companyParent()`-এ ভুল নাম লিখলে (`'invoce'`) স্কোপটা কোয়েরির
     * সময় ভেঙে পড়ত — অর্থাৎ ভুলটা ধরা পড়ত **লাইভে, একটা ৫০০ হয়ে**।
     * ⛔ এখানে ধরা পড়লে সেটা একটা লাল বাতি, আর সেটাই সস্তা।
     */
    public function test_each_declared_parent_really_exists_and_carries_a_company(): void
    {
        $broken = [];

        foreach ($this->modelsUnderModules() as $class) {
            if (! isset(class_uses_recursive($class)[BelongsToCompanyThroughParent::class])) {
                continue;
            }

            try {
                $model = new $class;
                $name = $model->companyParentRelation();
            } catch (\Throwable $e) {
                $broken[] = "{$class} — `companyParent()` ডাকা গেল না: ".$e->getMessage();

                continue;
            }

            if (! method_exists($model, $name)) {
                $broken[] = "{$class} — `{$name}()` নামে কোনো সম্পর্ক নেই।";

                continue;
            }

            $parent = $model->{$name}()->getRelated();
            $parentTable = $parent->getTable();

            if (! DB::getSchemaBuilder()->hasColumn($parentTable, 'company_id')) {
                $broken[] = "{$class} — কাগজটার (`{$parentTable}`) নিজেরই `company_id` নেই; দেয়ালটা শূন্যে দাঁড়িয়ে।";
            }
        }

        $this->assertSame([], $broken, "ঘোষিত কাগজগুলো দেয়াল হতে পারছে না:\n  ".implode("\n  ", $broken));
    }

    /**
     * ⓘ সেটআপের দাবি — স্ক্যানারটা সত্যিই মডেল খুঁজে পাচ্ছে তো?
     */
    public function test_the_scanner_actually_finds_the_models(): void
    {
        $this->assertGreaterThan(80, count($this->modelsUnderModules()),
            'মডেল এত কম হতে পারে না — স্ক্যানারটাই কিছু খুঁজে পাচ্ছে না।');
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelsUnderModules(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Modules')));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_contains($path, '/Models/') || ! str_ends_with($path, '.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/\nfinal class (\w+)|\nclass (\w+)/', $source, $m)) {
                continue;
            }

            if (! preg_match('/namespace\s+([^;]+);/', $source, $n)) {
                continue;
            }

            $class = trim($n[1]).'\\'.basename($path, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $cache = $found;
    }
}
