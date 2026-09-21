<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খোঁজার ঘরে Enter চাপলে কেবল খোঁজা হয় — আর কিছু নয়।
 *
 * ── ⛔ মালিকের অভিযোগ, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"+ ছাঁকনি search box kaj kore na"* — আর কথাটা অক্ষরে অক্ষরে সত্যি
 * ছিল।
 *
 * ⓘ কারণটা JavaScript নয়, **HTML-এর নিয়ম**: একটা ঘরে Enter চাপলে
 * ব্রাউজার ফর্মের **প্রথম** সাবমিট বোতামটা চালায়। টুলবারের ফর্মে
 * প্রথমটা ছিল `name="compact" value="1"` — অর্থাৎ **ঘনত্ব**।
 *
 * ⛔ ফল: খুঁজতে গিয়ে পাতাটা ঘন হয়ে যেত। কিছুই ভাঙত না, কোনো ত্রুটি
 * আসত না, কোনো পরীক্ষা লাল হত না — ব্রাউজার ঠিক যা করার কথা তাই
 * করত।
 *
 * ── ⚠️ কেন এটা সোর্স পড়ে মাপা হয়, পাতা এঁকে নয় ───────────────────
 * নিয়মটা **ক্রমের**, আর ক্রম দেখা যায় কেবল আঁকা HTML-এ। তাই পাতাটা
 * সত্যিই রেন্ডার করা হয়, তারপর ফর্মের ভিতরে সাবমিট বোতামগুলো **যে
 * ক্রমে আছে** সেই ক্রমে পড়া হয়।
 *
 * ⓘ আর এই পাহারাটা কেবল একটা পর্দার কথা বলে না: টুলবারটা প্রতিটা
 * তালিকার পাতায় একই, তাই এখানে ভাঙলে সব জায়গায় ভাঙে।
 */
final class EnterInTheSearchBoxOnlySearchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_first_submit_button_is_the_plain_search(): void
    {
        $form = $this->toolbarForm();

        $first = $this->submitsIn($form)[0] ?? null;

        $this->assertNotNull($first, 'ফর্মে একটাও সাবমিট বোতাম নেই — Enter কিছুই করবে না।');

        /*
         * ⓘ "সাধারণ" মানে নাম-মান ছাড়া: নাম থাকলে সেটা ঠিকানায় যায়,
         * আর তখন Enter কেবল খোঁজে না — সাথে আরেকটা সিদ্ধান্তও বসিয়ে দেয়।
         */
        $this->assertStringNotContainsString('name=', $first, implode("\n", [
            '⛔ ফর্মের প্রথম সাবমিট বোতামটা একটা নাম বহন করছে:',
            '',
            '    '.$first,
            '',
            '⚠️ খোঁজার ঘরে Enter চাপলে ব্রাউজার এটাকেই চালাবে, তাই',
            'ব্যবহারকারী খুঁজতে গিয়ে অন্য কিছু করে ফেলবেন।',
            '',
            'ⓘ খোঁজার ঘরের ঠিক পরের `sr-only` বোতামটাই প্রথম থাকা উচিত।',
        ]));
    }

    /**
     * ⭐ আর ঘনত্বের বোতামটা সত্যিই পরে — এটাই আসল ভুলটার নাম ধরে পাহারা।
     *
     * ⓘ উপরের দাবিটা বলে "প্রথমটা নিরীহ"; এটা বলে **কে আগে ছিল**।
     * ⚠️ দুইটা আলাদা কথা: কেউ `compact` বোতামটা সরিয়ে অন্য একটা নামওয়ালা
     * বোতাম আগে বসালে উপরেরটা ধরত, কিন্তু কারণটা লেখা থাকত না।
     */
    public function test_the_density_button_comes_after_the_search(): void
    {
        $form = $this->toolbarForm();

        $search = strpos($form, 'name="q"');
        $density = strpos($form, 'name="compact"');

        $this->assertNotFalse($search, 'খোঁজার ঘরটাই ফর্মে নেই।');

        if ($density === false) {
            $this->markTestSkipped('এই পর্দায় ঘনত্বের বোতাম নেই।');
        }

        $this->assertGreaterThan($search, $density,
            '⛔ ঘনত্বের বোতামটা খোঁজার ঘরের আগে চলে এসেছে — Enter আবার ঘনত্ব বদলাবে।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দুইটা দাবি সবুজ থাকত যদি `toolbarForm()` চিরকাল একটা খালি
     * লেখা ফেরাত। ⚠️ তাই এখানে মাপা হয় খোঁজাটাই কাজ করছে কি না।
     */
    public function test_the_form_really_was_found(): void
    {
        $form = $this->toolbarForm();

        $this->assertStringContainsString('name="q"', $form);
        $this->assertGreaterThan(1, count($this->submitsIn($form)),
            'ফর্মে একটাই সাবমিট পাওয়া গেল — ক্রমের দাবিটা তখন কিছুই প্রমাণ করে না।');
    }

    /**
     * টুলবারের ফর্মটা — আঁকা পাতা থেকে, সোর্স থেকে নয়।
     */
    private function toolbarForm(): string
    {
        $html = (string) $this->get(route('inventory.stock.index'))
            ->assertOk()
            ->getContent();

        $at = strpos($html, 'name="q"');

        $this->assertNotFalse($at, 'পাতায় খোঁজার ঘরই নেই — টুলবার আঁকা হয়নি।');

        $start = strrpos(substr($html, 0, $at), '<form');
        $end = strpos($html, '</form>', $at);

        $this->assertNotFalse($start, 'খোঁজার ঘরটা কোনো ফর্মের ভিতরে নেই — Enter কিছুই জমা দেবে না।');

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    /**
     * ফর্মের সাবমিট কন্ট্রোলগুলো, **যে ক্রমে লেখা আছে**।
     *
     * ⓘ `<button>`-এর ডিফল্ট ধরনই `submit`, তাই `type` না লেখা বোতামও
     * গোনা হয় — ⚠️ ওটা বাদ দিলে পাহারাটা ঠিক ঐ বোতামটাই মিস করত যেটা
     * কেউ তাড়াহুড়োয় `type` ছাড়া বসিয়েছে।
     *
     * @return list<string>
     */
    private function submitsIn(string $form): array
    {
        preg_match_all('/<button\b[^>]*>|<input\b[^>]*>/i', $form, $m);

        $out = [];

        foreach ($m[0] as $tag) {
            $type = preg_match('/type="([^"]*)"/i', $tag, $t) ? strtolower($t[1]) : null;

            if ($type === 'submit' || (str_starts_with(strtolower($tag), '<button') && $type === null)) {
                $out[] = $tag;
            }
        }

        return $out;
    }
}
