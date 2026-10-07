<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বারে দুইটা ঘরের নাম এক ছিল, আর ওরা দুই জায়গায় নিয়ে যেত।
 *
 * ── ⛔ কী ভাঙা ছিল, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * বিক্রয়ের বারে পাশাপাশি **দুইটা "উদ্ধৃতি"**। একটা ছিল `quotations`
 * ভাঁজ (`core.menu.quotations`), যার ভিতরে চারটা সারিই
 * [[PlannedScreenController]]-এর *"এখনো তৈরি হয়নি"* পাতায় যেত; অন্যটা
 * ছিল `sales::quotation.menu`, অর্থাৎ **আসল, কাজ করা** উদ্ধৃতির তালিকা।
 *
 * ⚠️ ফল কেবল দেখতে খারাপ নয়, **বিভ্রান্তিকর**: ভুল ঘরে চেপে "এখনো তৈরি
 * হয়নি" দেখে মানুষ ধরে নিতেন উদ্ধৃতি জিনিসটাই কাজ করে না — অথচ করে।
 *
 * ⓘ সারাই: আসল সারিটা ঐ ভাঁজের **ভিতরে, সবার উপরে**। মালিকের ২৮
 * সেপ্টেম্বরের সিদ্ধান্ত অক্ষত (*"age bosaw, code pore korbo"*) — ভাঁজটা
 * আছে, কেবল আর দুইটা নাম নেই।
 *
 * ── ⭐ দাবিটা এই এক পর্দার নয়, নিয়মের ─────────────────────────────────
 * ⛔ *"বিক্রয়ের বারে দুইটা উদ্ধৃতি নেই"* লিখলে সেটা একটা ঘটনা পাহারা
 * দিত, নিয়ম নয় — আর কাল অন্য কোনো মডিউলে একই জিনিস হলে কিছুই লাল হত না।
 *
 * ⭐ তাই দাবিটা প্রতিটা মডিউলের বারে: **একই লেখার দুইটা ঘর কখনো নয়**।
 * ⓘ দুই ভাষাতেই, কারণ নকলটা অনুবাদেও জন্মাতে পারে — দুইটা আলাদা চাবি
 * এক ভাষায় আলাদা শব্দ পেয়ে অন্য ভাষায় এক হয়ে যেতে পারে।
 */
final class TwoCellsInTheBarWoreTheSameNameTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_no_bar_shows_the_same_name_twice_in_bengali(): void
    {
        $this->assertNoDuplicates('bn');
    }

    public function test_no_bar_shows_the_same_name_twice_in_english(): void
    {
        $this->assertNoDuplicates('en');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function assertNoDuplicates(string $locale): void
    {
        /*
         * ⛔ `app()->setLocale()` এখানে কাজ করে না, আর সেটা ধরা পড়েছে
         * মেপে: [[ResolveCompanyContext]] প্রতিটা অনুরোধে ব্যবহারকারীর
         * নিজের ভাষা বসিয়ে দেয়। ⚠️ ফলে ইংরেজির দাবিটা আসলে **বাংলাই**
         * মাপছিল — দুই ভাষার কথাটা কাগজে ছিল, বাস্তবে নয়।
         *
         * ⓘ লাইভে মালিকের ব্যবহারকারীর ভাষা `en`, তাই দশাটা কল্পনা নয়।
         */
        $this->owner->forceFill(['locale' => $locale])->save();
        $this->owner = $this->owner->fresh();

        $looked = 0;
        $complaints = [];

        /*
         * ⓘ মডিউলের তালিকা রেজিস্ট্রি থেকে, হাতে টাইপ করা নয় — নিজের
         * টাইপ করা তালিকায় নতুন মডিউল কোনোদিন যোগ হত না, আর দাবিটা
         * চিরকাল সবুজ থাকত।
         */
        foreach (app(ModuleRegistry::class)->all() as $module) {
            $response = $this->actingAs($this->owner)->get('/dashboard/'.$module->code);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $page = $response->getContent();

            /*
             * ⛔ ভাষাটা সত্যিই বদলেছে — নাহলে উপরের সবটা একই ভাষা দুইবার
             * মাপত, আর দুইটা দাবি একটার কাজ করত।
             */
            $this->assertStringContainsString('<html lang="'.$locale.'"', $page,
                'পাতাটা '.$locale.' ভাষায় আঁকা হয়নি — দাবিটা তখন কিছুই আলাদা মাপছে না।');

            $cells = $this->cellsOf($page);

            if ($cells === []) {
                continue;
            }

            $looked++;

            $counts = array_count_values($cells);

            foreach ($counts as $name => $times) {
                if ($times > 1) {
                    $complaints[] = sprintf('%s — "%s" %dবার', $module->code, $name, $times);
                }
            }
        }

        /*
         * ⛔ কয়টা বার সত্যিই দেখা হলো, সেটা আগে প্রমাণ করা হয়। ⚠️ নাহলে
         * একদিন খোঁজার নিয়মটা ভেঙে গেলে দাবিটা **শূন্য** বার দেখে সবুজ
         * থাকত — আর এই প্রকল্পে ঠিক ঐ ধরনের "কিছুই না দেখা" পাহারা আগে
         * ধরা পড়েছে।
         */
        $this->assertGreaterThanOrEqual(3, $looked,
            'মাত্র '.$looked.'টা বার দেখা গেল — খোঁজার নিয়মটাই ভেঙে গেছে কি না দেখুন।');

        $this->assertSame([], $complaints,
            $locale.': একই লেখার দুইটা ঘর — '.implode(' · ', $complaints));
    }

    /**
     * বারের ঘরগুলোর নিজের নাম — আঁকা পাতা থেকে।
     *
     * ⓘ দুইটা আকৃতি আছে: ভাঁজের নাম বোতামের ভিতরে খালি লেখা, আর ট্যাবের
     * নাম একটা `<span>`-এর ভিতরে। ⚠️ তাই কেবল ভাঁজের **অতিরিক্ত** দুইটা
     * span (চলতি সারির নাম আর `›`) ফেলা হয়, বাকি ট্যাগ খুলে নেওয়া হয়।
     *
     * @return list<string>
     */
    private function cellsOf(string $html): array
    {
        $at = strpos($html, 'data-module-bar');

        if ($at === false) {
            return [];
        }

        $closes = strpos($html, '</nav>', $at);
        $bar = substr($html, $at, ($closes === false ? strlen($html) : $closes) - $at);

        preg_match_all('#<(a|button)\b[^>]*class="[^"]*modulebar-cell[^"]*"[^>]*>(.*?)</\1>#s',
            $bar, $matches);

        $names = [];

        foreach ($matches[2] as $inner) {
            /* ⓘ চলতি সারির নাম আর তীরচিহ্নটা ঘরের নিজের নাম নয় */
            $inner = preg_replace('#<span class="opacity-70".*?</span>#s', '', $inner);
            $inner = preg_replace('#<span class="max-w-32 truncate">.*?</span>#s', '', $inner);

            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $inner)));

            if ($text !== '') {
                $names[] = $text;
            }
        }

        return $names;
    }
}
