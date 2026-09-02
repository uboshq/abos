<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Concerns\IsAudited;
use App\Models\Company;
use App\Models\Setting;
use Tests\TestCase;

/**
 * যে সারি বদলাতে পারে, সে মনে রাখে কে বদলেছে।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬-এ গোনা হলো: ১১৯টা মডেলের **১০১টায়** [[IsAudited]],
 * বাকি ১৮টায় নেই। বেশিরভাগ বাদ পড়াই ঠিক — খতিয়ান ও চলাচল কখনো বদলায়
 * না, আর অডিটের খাতা নিজেকে অডিট করলে চক্র হত।
 *
 * কিন্তু ওই ১৮টার ভেতরে দুইটা ছিল যেগুলো **প্রতিদিন বদলায়**:
 *
 * ```
 * Setting  — একটা সেটিং কে বদলাল, কবে, কী থেকে কীসে : কোথাও লেখা ছিল না
 * Company  — BIN, TIN, আইনি নাম, মুদ্রা, আর is_active : একই
 * ```
 *
 * `Setting`-টা তাত্ত্বিক নয়। এই রিপোর নিজের খাতায় লেখা আছে (Findings,
 * ৩০ আগস্ট) — *"এক ট্যাব সংরক্ষণ করায় ৩৪টা সেটিং নীরবে বন্ধ"*। কে
 * বন্ধ করল তা বের করতে গোটা সন্ধ্যা গেছে, **কারণ জিজ্ঞেস করার মতো
 * কোনো খাতা ছিল না।**
 *
 * ── কেন এই পাহারাটা তালিকা ধরে চলে ───────────────────────────────────
 * "সব মডেলে অডিট" নিয়মটা ভুল হত — কিছু বাদ পড়া সত্যিই দরকার। কিন্তু
 * "কিছু বাদ পড়ে" আর "যা খুশি বাদ পড়তে পারে" এক জিনিস নয়।
 *
 * তাই তালিকাটা এখানে, **কারণসহ**। নতুন কোনো মডেল অডিট ছাড়া এলে
 * পাহারাটা লাল হবে, আর সবুজ করার একমাত্র উপায় হবে **এখানে এসে কারণটা
 * লিখে দেওয়া** — অর্থাৎ সিদ্ধান্তটা একবার অন্তত ভাবতে হবে।
 */
class EveryChangeableRowRemembersWhoChangedItTest extends TestCase
{
    /**
     * ইচ্ছাকৃতভাবে অডিটের বাইরে — প্রতিটার কারণ পাশে।
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // ── খাতাটা নিজে ─────────────────────────────────────────────
        'App\Models\AuditTrail' => 'অডিটের খাতা নিজেকে অডিট করলে প্রতিটা সারি অসীম সারি জন্ম দিত',
        'App\Models\AuditFieldChange' => 'একই কারণ — এটা অডিট সারির ঘর-ধরে-ধরে অংশ',

        // ── শুধু যোগ হয়, কখনো বদলায় না (ভুল হলে উল্টো সারি বসে) ─────
        'App\Models\LedgerEntry' => 'খতিয়ান শুধু যোগের — সারি বদলায় না, মোছে না',
        'App\Modules\Inventory\Models\StockMovement' => 'মজুদের চলাচলও তা-ই',
        'App\Modules\Inventory\Models\CostLayer' => 'খরচের স্তর চলাচল থেকেই জন্মায়',
        'App\Modules\Inventory\Models\CostLayerUse' => 'কোন স্তর থেকে কতটা গেল — শুধু যোগের',
        'App\Models\IssuedNumber' => 'একবার দেওয়া নম্বর ফেরত নেওয়া হয় না',
        'App\Models\ApprovalDecision' => 'একটা সিদ্ধান্ত ঘটে যাওয়া ঘটনা, সম্পাদনার জিনিস নয়',
        'App\Models\LoginAttempt' => 'চেষ্টার ইতিহাস — নিজেই একটা লগ',
        'App\Models\ExportLog' => 'কে কী রপ্তানি করল — নিজেই একটা লগ',
        'App\Models\ErrorEvent' => 'ভুলের খাতা — ব্যবস্থাটা নিজে লেখে, মানুষ নয়',
        'App\Models\Notification' => 'পড়া/না-পড়া ছাড়া কিছু বদলায় না',

        // ── ব্যক্তির নিজের সুবিধা, ব্যবসার তথ্য নয় ──────────────────
        'App\Models\SavedView' => 'নিজের তালিকার নিজের ছাঁকনি',
        'App\Models\LookSkinVersion' => 'পর্দার রূপ — হিসাবের কিছু নয়',

        // ── মূল সারির সাথেই লেখা হয়, তাই আলাদা করে নয় ───────────────
        'App\Models\ApprovalFlowStep' => 'ছক সংরক্ষণে স্তরগুলো পুরোটা বদলে বসে; ApprovalFlow নিজে অডিটে আছে',
        'App\Modules\Accounts\Models\LoanInstalment' => 'কিস্তি ঋণ থেকেই তৈরি হয়, আর Loan নিজে অডিটে আছে',
    ];

    /**
     * অডিটহীন মডেলের তালিকা আর উপরের তালিকা — হুবহু এক।
     */
    public function test_no_model_quietly_leaves_the_audit_trail(): void
    {
        $unaudited = [];

        foreach ($this->modelFiles() as $file) {
            $src = (string) file_get_contents($file);

            if (! preg_match('/\nclass (\w+) extends .*Model\b/', $src, $m)
                && ! preg_match('/\nclass (\w+) extends Model\b/', $src, $m)) {
                continue;
            }

            /*
             * লাইনের শুরুতে, মন্তব্যের ভেতরে নয়।
             *
             * প্রথম খসড়ায় এখানে `str_contains($src, 'use IsAudited;')`
             * ছিল, আর পাহারাটা ভাঙতে গিয়েই ধরা পড়ল: ট্রেইটটা মন্তব্য
             * করে দিলে (`// use IsAudited;`) লেখাটা ফাইলে থেকেই যায়,
             * তাই পাহারাটা **সবুজ থাকত** — অর্থাৎ ঠিক যে ভুলটা ধরার
             * জন্য এটা লেখা, সেটাই ধরত না।
             */
            if (preg_match('/^[ \t]*use IsAudited;/m', $src)) {
                continue;
            }

            if (! preg_match('/^namespace ([^;]+);/m', $src, $ns)) {
                continue;
            }

            $unaudited[] = $ns[1].'\\'.$m[1];
        }

        sort($unaudited);
        $expected = array_keys(self::EXEMPT);
        sort($expected);

        $newlyMissing = array_diff($unaudited, $expected);
        $goneFromList = array_diff($expected, $unaudited);

        $this->assertSame([], array_values($newlyMissing), implode("\n", [
            'এই মডেলগুলোয় অডিট নেই, আর তালিকাতেও নেই:',
            '',
            implode("\n", $newlyMissing),
            '',
            'হয় `use IsAudited;` বসান, নাহলে এই পরীক্ষার EXEMPT তালিকায়',
            'কারণসহ যোগ করুন। কারণটা লেখাই এখানে আসল কাজ।',
        ]));

        $this->assertSame([], array_values($goneFromList), implode("\n", [
            'তালিকায় আছে অথচ এখন অডিটে ঢুকে গেছে (বা মডেলটাই নেই):',
            '',
            implode("\n", $goneFromList),
            '',
            'EXEMPT তালিকা থেকে সারিটা তুলে দিন — নাহলে তালিকাটা মিথ্যা বলে।',
        ]));
    }

    /**
     * আর যে দুইটার জন্য এই পাহারাটা লেখা হলো, সেগুলো সত্যিই ঢুকেছে।
     *
     * উপরের পরীক্ষাটা তালিকা মেলায়, তাই কেউ `Setting`-কে EXEMPT-এ বসিয়ে
     * দিলে সেটাও সবুজ থাকত। এই দুইটা নাম তাই আলাদা করে লেখা।
     */
    public function test_settings_and_companies_are_audited(): void
    {
        foreach ([Setting::class, Company::class] as $model) {
            $this->assertContains(
                IsAudited::class,
                class_uses_recursive($model),
                "{$model} অডিটের বাইরে চলে গেছে।",
            );
        }
    }

    /** @return list<string> */
    private function modelFiles(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && str_contains($f->getPathname(), 'Models')) {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
