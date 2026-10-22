<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⛔ যে সারি অডিটে যায়, সে বলতে পারে কার খাতায় — ১২ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * [[User]]-এ [[IsAudited]] বসানোর দিনে **ছয়টা স্থাপত্য-পরীক্ষা লাল
 * হলো**, আর ভুলটা পর্দার নয় — ব্যবহারকারী তৈরিই ব্যর্থ হচ্ছিল:
 *
 *     SQLSTATE[23000] … audit_trails_branch_id_foreign
 *     SQLSTATE[23000] … audit_trails_company_id_foreign
 *     insert into `audit_trails` … values (1, 1, …, created, App\Models\User, 5)
 *
 * ⓘ কারণ: সারির নিজের `company_id` না থাকলে [[AuditEngine]] **চলতি
 * প্রসঙ্গের** আইডিটা বসায় — আর সেই সারিটা সত্যিই টেবিলে আছে কি না সে
 * জানে না। ⚠️ `CompanyContext` স্ট্যাটিক, আর সে **রোলব্যাক হওয়া
 * লেনদেনের পরেও বেঁচে থাকে**; MySQL-এ auto-increment রোলব্যাকে ফেরে
 * না। ⛔ ফলে প্রসঙ্গে পড়ে থাকা আইডিটা এমন একটা সারির দিকে ইশারা করত
 * **যেটা আর নেই**, আর খাতা লেখার চেষ্টাটাই মূল কাজটাকে ফেলে দিত।
 *
 * ── ⭐ কেন [[User]]-ই এটা প্রথম দেখাল ────────────────────────────────
 * ⓘ বাকি প্রায় প্রতিটা অডিটেড সারির **নিজের `company_id` আছে**, তাই
 * প্রশ্নটাই ওঠে না। আর যাদের নেই, তারা জন্মায় কোনো না কোনো ডকুমেন্টের
 * ভেতরে — অর্থাৎ কোম্পানি ও শাখা বসার **অনেক পরে**।
 *
 * ⚠️ [[User]] সেই নিয়মের বাইরে: `FirstRun::open()` ব্যবহারকারী বানায়
 * আগে (লাইন ~১৪৫), কোম্পানি তার পরে (~২০৩) — কারণ কোম্পানিটা কার,
 * সেটা বলতে একজন মালিক লাগে। ⭐ অর্থাৎ সে-ই একমাত্র মডেল যে **কোম্পানির
 * আগেও** জন্মাতে পারে, আর সেজন্যই পুরনো ফাঁকটা তার হাতেই ধরা পড়ল।
 *
 * ── কেন রানটাইমে নয়, এখানে ───────────────────────────────────────────
 * ⓘ [[AuditEngine]]-এ প্রতি লেখায় একটা `exists()` বসানো যেত। ⚠️ কিন্তু
 * সেটা **প্রতিটা বিল, ভাউচার, চালান ও পণ্যের গরম পথে** একটা বাড়তি
 * রাউন্ড-ট্রিপ — অথচ ওদের কারও এই রোগ নেই। ⭐ একজনের রোগে সবার উপর কর
 * বসানোর বদলে প্রশ্নটা CI-তে একবার করা হয়, আর দাম শূন্য।
 *
 * ── ⚠️ কেন নিয়মটা "সব মডেলে হুক" নয় ────────────────────────────────
 * প্রথম খসড়ায় দাবিটা ছিল: প্রতিটা অডিটেড মডেলের `branch_id` কলাম থাক,
 * নয়তো `auditBranchId()`। ⛔ গুনে দেখা গেল সেই নিয়মে **১১৮টার ৭০টা**
 * লাল হয় — একক, ব্র্যান্ড, কর, শ্রেণি, প্রায় প্রতিটা মাস্টার ডাটা।
 *
 * ⓘ আর ওদের বেলায় প্রসঙ্গের শাখা বসাটা **ভুল নয়, উদ্দেশ্য**: একটা করের
 * হার কোন শাখার সম্পত্তি নয়, কিন্তু কে কোথা থেকে বদলাল সেটা জানা ভালো।
 * ⚠️ আর [[EveryUserListAsksWhichCompanyTest]]-এর নিজের কথা: *"যে পাহারা
 * ৪০টা নাম দেয়, মানুষ তাতে ছাড় যোগ করতে শেখে — আর তখন ছাড়ের তালিকাটাই
 * পাহারার জায়গা নিয়ে নেয়।"*
 *
 * ⭐ তাই প্রশ্নটা সরু করা হলো, আর সরু করেই ধারালো: **যে সারি নিজের
 * ঘর দেখে বলতে পারে না সে কার খাতার, সে-ই কেবল মুখে বলতে বাধ্য।**
 * আজ এমন মডেল বারোটা — দুইটা বলে (নিচে প্রমাণ), দশটা বলে না (নিচে
 * কারণসহ তালিকা)।
 */
class AnAuditedModelMustSayWhoseBooksItBelongsToTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ নিজের `company_id` নেই, তবু হুক ঘোষণা করে না — কারণসহ।
     *
     * ── ⛔ দশটাই এক পরিবারের, আর কারণটাও এক ──────────────────────────
     * প্রতিটাই একটা **ডকুমেন্টের সারি** — বিলের লাইন, চালানের লাইন,
     * ভাউচারের লাইন। ⓘ কোনোটাই একা জন্মায় না: প্রত্যেকে তার প্যারেন্ট
     * ডকুমেন্টের সেভের ভেতরে বসে, আর সেই ডকুমেন্টের নিজের `company_id`
     * **আছে**। অর্থাৎ যে মুহূর্তে একটা লাইন লেখা হয়, কোম্পানি ও শাখা
     * দুইটাই ততক্ষণে বসানো ও বৈধ — [[User]]-এর মতো "কোম্পানির আগে
     * জন্ম" এখানে ঘটতে পারে না।
     *
     * ⚠️ তবু এটা **স্থায়ী ছাড় নয়, একটা দেনা**: ঝুঁকিটা আজ শূন্যের
     * কাছাকাছি, শূন্য নয়। ⓘ কেউ যদি কোনোদিন প্যারেন্ট ছাড়া একটা লাইন
     * বানান — আমদানিতে, সারাইয়ের স্ক্রিপ্টে — তখন এরাও ঠিক ঐ পথেই
     * ভাঙবে। ⭐ সঠিক সারাই হলো এদের `auditCompanyId()` প্যারেন্ট
     * ডকুমেন্ট থেকে ফেরানো, আর সেটা যে মডিউলের কাজ সেই মডিউলের।
     *
     * @var array<class-string, string>
     */
    private const EXEMPT = [
        'App\Modules\Accounts\Models\VoucherLine' => 'ভাউচারের সারি — প্যারেন্ট ভাউচারের company_id-ই এর প্রসঙ্গ',
        'App\Modules\Purchase\Models\PurchaseOrderLine' => 'ক্রয়াদেশের সারি — আদেশটা নিজে কোম্পানি জানে',
        'App\Modules\Purchase\Models\PurchaseReceiptLine' => 'গ্রহণের সারি — রসিদটা নিজে কোম্পানি জানে',
        'App\Modules\Purchase\Models\PurchaseBillLine' => 'ক্রয় বিলের সারি — বিলটা নিজে কোম্পানি জানে',
        'App\Modules\Purchase\Models\PurchaseBillGiftLine' => 'বিলের উপহার-সারি — একই বিলের ভেতরে',
        'App\Modules\Sales\Models\SalesOrderLine' => 'বিক্রয়াদেশের সারি — আদেশটা নিজে কোম্পানি জানে',
        'App\Modules\Sales\Models\DeliveryChallanLine' => 'চালানের সারি — চালানটা নিজে কোম্পানি জানে',
        'App\Modules\Sales\Models\DeliveryChallanGiftLine' => 'চালানের উপহার-সারি — একই চালানের ভেতরে',
        'App\Modules\Sales\Models\SalesInvoiceLine' => 'বিক্রয় বিলের সারি — বিলটা নিজে কোম্পানি জানে',
        'App\Modules\Sales\Models\CollectionLine' => 'আদায়ের সারি — আদায়টা নিজে কোম্পানি জানে',
    ];

    /**
     * ⛔ নিজের ঘরে উত্তর না থাকলে মুখে বলতে হয়।
     *
     * ⓘ দুইটা প্রশ্নই — কোন কোম্পানি, কোন শাখা। ⚠️ কেবল কোম্পানিটা
     * সারালে যথেষ্ট নয়: মেপে দেখা গেছে `auditBranchId()` ছাড়া ত্রুটিটা
     * `branch_id`-র বিদেশি চাবিতে **হুবহু একইভাবে** ফেরত আসে।
     */
    public function test_a_model_with_no_company_column_says_whose_books_it_is(): void
    {
        $silent = [];

        foreach ($this->auditedModels() as $class => $model) {
            if (Schema::hasColumn($model->getTable(), 'company_id')) {
                continue;
            }

            $missing = array_values(array_filter([
                method_exists($model, 'auditCompanyId') ? null : 'auditCompanyId()',
                method_exists($model, 'auditBranchId') ? null : 'auditBranchId()',
            ]));

            if ($missing !== []) {
                $silent[$class] = implode(' ও ', $missing);
            }
        }

        $unexplained = array_diff(array_keys($silent), array_keys(self::EXEMPT));
        $stale = array_diff(array_keys(self::EXEMPT), array_keys($silent));

        sort($unexplained);
        sort($stale);

        $this->assertSame([], array_values($unexplained), implode("\n", array_merge(
            ['⛔ এই মডেলগুলোর নিজের `company_id` নেই, আর তারা মুখেও বলে না',
                'তাদের অডিট-সারি কার খাতায় বসবে:',
                ''],
            array_map(fn (string $c) => "    {$c} — {$silent[$c]} নেই", $unexplained),
            ['',
                '⚠️ প্রসঙ্গ থেকে নেওয়া আইডিটা ঐ সারিটা সত্যিই আছে কি না জানে না,',
                'আর না থাকলে খাতা লেখার চেষ্টাটাই **মূল কাজটাকে ফেলে দেয়** —',
                'একটা ৫০০, আর ব্যবহারকারী বুঝতেই পারেন না কী হলো।',
                '',
                'হয় মডেলে `auditCompanyId()` ও `auditBranchId()` বসান',
                '([[User]] ও [[Company]] দেখুন), নাহলে এই পরীক্ষার EXEMPT',
                'তালিকায় **কারণসহ** যোগ করুন। কারণটা লেখাই এখানে আসল কাজ।'],
        )));

        $this->assertSame([], array_values($stale), implode("\n", array_merge(
            ['⚠️ এই নামগুলো ছাড়ের তালিকায় আছে, অথচ তাদের আর ছাড় লাগে না',
                '(হয় হুক বসেছে, নয় `company_id` কলাম এসেছে, নয় মডেলটাই নেই):',
                ''],
            $stale,
            ['',
                'সারিগুলো তুলে দিন — নাহলে তালিকাটা মিথ্যা বলে, আর পরের',
                'জন ওটা পড়ে ভুল সিদ্ধান্ত নেন।'],
        )));
    }

    /**
     * ⭐ আর যে দুইটার জন্য এই পাহারাটা লেখা হলো, তারা সত্যিই বলে।
     *
     * ⓘ উপরের দাবিটা তালিকা মেলায়, তাই কেউ [[User]]-কে EXEMPT-এ বসিয়ে
     * দিলে সেটাও সবুজ থাকত। ⚠️ এই দুইটা নাম তাই আলাদা করে লেখা — আর
     * দুইটাই ঐ ভাঙনের সাক্ষী: [[Company]] ২ সেপ্টেম্বর, [[User]] ১২
     * সেপ্টেম্বর, একই বিদেশি চাবি, একই কারণ।
     */
    public function test_the_two_that_taught_us_this_still_say_it(): void
    {
        foreach ([User::class, Company::class] as $class) {
            $model = new $class;

            $this->assertTrue(method_exists($model, 'auditCompanyId'),
                "{$class} আর বলে না তার অডিট-সারি কোন কোম্পানির খাতায় বসবে।");

            $this->assertTrue(method_exists($model, 'auditBranchId'),
                "{$class} আর বলে না তার অডিট-সারি কোন শাখার — আর প্রসঙ্গের শাখাটা তার নিজের নয়।");

            $this->assertNull($model->auditBranchId(),
                "{$class}-এর সারি কোনো শাখার নয়; প্রসঙ্গের শাখা বসলে খাতাটা মিথ্যা বলে।");
        }
    }

    /**
     * ⚠️ পাহারাটা সত্যিই মডেলগুলো দেখছে তো?
     *
     * ⓘ উপরের দাবিগুলো **খালি তালিকাতেও সবুজ**। খোঁজার ছাঁচটা একদিন
     * ভাঙলে — ফোল্ডারের নাম বদলালে, ট্রেইটটা অন্যভাবে লেখা শুরু হলে —
     * পাহারাটা চুপচাপ কিছুই না দেখে সবুজ থাকত।
     *
     * ⭐ সংখ্যাটা গুনে দেখা: ১২ সেপ্টেম্বর ২০২৬-এ ১১৮টা মডেল অডিটেড।
     * একশোর নিচে নামলে কিছু একটা ভেঙেছে, আর সেটা জানা দরকার।
     */
    public function test_the_guard_is_actually_looking_at_the_models(): void
    {
        $models = $this->auditedModels();

        $this->assertGreaterThan(100, count($models),
            'অডিটেড মডেল খুঁজে পাওয়া গেল মাত্র '.count($models).'টা — খোঁজার ছাঁচটা ভেঙেছে।');

        $this->assertArrayHasKey(User::class, $models,
            '[[User]] অডিটেড মডেলের তালিকায় নেই — অথচ এই ফাইলটা তার জন্যই লেখা।');
    }

    /**
     * প্রতিটা `IsAudited` মডেল — নাম ধরে নয়, ফাইল পড়ে।
     *
     * ⓘ ছাঁচটা [[EveryChangeableRowRemembersWhoChangedItTest]]-এর মতোই:
     * লাইনের **শুরুতে** `use IsAudited;`, মন্তব্যের ভেতরে নয়। ⚠️ ওখানে
     * কারণটা লেখা আছে — ট্রেইটটা মন্তব্য করে দিলে (`// use IsAudited;`)
     * লেখাটা ফাইলে থেকেই যায়, আর `str_contains` দিয়ে খুঁজলে পাহারাটা
     * সবুজ থাকত।
     *
     * @return array<class-string, Model>
     */
    private function auditedModels(): array
    {
        $models = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($file->getPathname(), 'Models')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (! preg_match('/^[ \t]*use IsAudited;/m', $src)) {
                continue;
            }

            if (! preg_match('/^namespace ([^;]+);/m', $src, $ns)) {
                continue;
            }

            /*
             * ⓘ ক্লাসের নাম ফাইলের নাম থেকেই — PSR-4 তাই বলে, আর
             * regex দিয়ে `class X extends …` খুঁজতে গেলে `final`,
             * `readonly` বা বাসা-বাঁধা ক্লাসে হোঁচট খেতে হত।
             */
            $class = $ns[1].'\\'.$file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $models[$class] = new $class;
        }

        ksort($models);

        return $models;
    }
}
