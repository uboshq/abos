<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\SalaryHead;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খালি পাতা আর খালি তালিকা এক জিনিস নয়।
 *
 * ── কী ভাঙত, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * বেতনের খাতের পর্দায় পাতা ভাগ বসানো হলো — `->get()` থেকে
 * `->paginate(50)`। নিরীহ বদল, আর তালিকাটা ঠিকই কাজ করত।
 *
 * কিন্তু ঠিক নিচেই একটা শর্ত ছিল:
 *
 *     'canInstallDefaults' => $heads->isEmpty() && ! ...
 *
 * `->get()`-এর Collection-এ `isEmpty()` মানে **তালিকাটা খালি**।
 * paginator-এ ওটার মানে **এই পাতাটা খালি** — সম্পূর্ণ আলাদা প্রশ্ন।
 *
 * ⛔ ফল: ভরা তালিকার `?page=9` খুললে পাতাটা খালি ফিরত, আর পর্দায়
 * ভেসে উঠত "তালিকাগুলো এখনো খালি — প্রমিত খাত বসান" সহ একটা বোতাম।
 * চাপলে চলতি খাতের উপর আবার প্রমিত খাত বসানোর চেষ্টা হত।
 *
 * ⚠️ আর এটা কোনো কাল্পনিক ঠিকানা নয়: ছাঁকনি দেওয়া একটা লিংক সেভ করা,
 * বা তিন নম্বর পাতায় থাকা অবস্থায় "নিষ্ক্রিয় দেখান" টিক দেওয়া — দুইটাতেই
 * মানুষ এমন একটা পাতায় পৌঁছান যা খালি, অথচ তালিকা খালি নয়।
 *
 * ── কেন পরীক্ষাটা পাতা-৯ নয়, পাতা-২ ─────────────────────────────────
 * পাতা ৯ খালি হত, আর খালি পাতায় দাবিটা প্রমাণ করা সহজ। পাতা ২-এ
 * সারি **আছে**, তাই এখানে বোতামটা না আসা প্রমাণ করে শর্তটা সত্যিই
 * মোটের কথা বলছে — পাতার সারি আছে কি নেই, তার কথা নয়।
 *
 * ── দাবিটা দুই ভাগে, আর সেটা ইচ্ছাকৃত ───────────────────────────────
 * "বোতামটা নেই" — এই দাবিটা **বোতামটা কোনোদিন না থাকলেও পাশ করত**,
 * বা কেউ পুরো ব্লকটা মুছে দিলেও। তাই প্রথম পরীক্ষাটা উল্টো দিক দেখে:
 * তালিকা সত্যিই খালি হলে বোতামটা **আসে**। অনুপস্থিতির উপর দাবি
 * অনুপস্থিতিতেই পাশ করে, তাই উপস্থিতিটা আগে প্রমাণ করতে হয়।
 */
class ThePageWasEmptySoItOfferedToFillTheListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * তালিকা সত্যিই খালি — তখন প্রস্তাবটা আসে।
     *
     * এটাই নিচের দুইটা পরীক্ষার শর্ত: বোতামটা খুঁজে পাওয়া যায়, আর
     * চিহ্নটা (install-এর ঠিকানা) সত্যিই পর্দায় বসে।
     */
    public function test_an_empty_list_offers_to_install_the_standard_heads(): void
    {
        $this->clearTheHeads();

        $this->get(route('hr.salary_head.index'))
            ->assertOk()
            ->assertSee(route('hr.salary_head.install'));
    }

    /**
     * ভরা তালিকার প্রথম পাতায় প্রস্তাবটা আসে না — আগেও আসত না।
     */
    public function test_a_full_list_does_not_offer_to_install(): void
    {
        $this->clearTheHeads();
        $this->makeHeads(60);

        $this->get(route('hr.salary_head.index'))
            ->assertOk()
            ->assertDontSee(route('hr.salary_head.install'));
    }

    /**
     * ⭐ আসল পরীক্ষাটা — দ্বিতীয় পাতাতেও আসে না।
     *
     * `isEmpty()` থাকলে এটা লাল হত, আর `total()` থাকলে সবুজ।
     */
    public function test_the_second_page_does_not_offer_to_install_a_list_that_is_already_full(): void
    {
        $this->clearTheHeads();
        $this->makeHeads(60);

        $page = $this->get(route('hr.salary_head.index', ['page' => 2]))->assertOk();

        /*
         * ⚠️ আগে প্রমাণ করা যে আমরা সত্যিই একটা **সারি-সহ** দ্বিতীয়
         * পাতায় আছি।
         *
         * এটা না থাকলে পরীক্ষাটা এমন একটা পাতাতেও পাশ করত যেখানে
         * পাতা ভাগই হয়নি (তখন `?page=2` উপেক্ষিত হয়ে প্রথম পাতাই ফিরত),
         * অর্থাৎ যে জিনিসটা পরীক্ষা করছি সেটা না থাকলেও সবুজ।
         */
        $page->assertSee('SH-051');

        $page->assertDontSee(route('hr.salary_head.install'));
    }

    /**
     * ডেমো ডেটার খাতগুলো সরানো — নাহলে "খালি তালিকা" কোনোদিন খালি নয়।
     *
     * `forceDelete`, কারণ মডেলটা SoftDeletes ব্যবহার করে আর নরম-মোছা
     * সারি `total()`-এ গোনা হত না ঠিকই, কিন্তু `code`-এর অনন্যতায়
     * রয়ে যেত — পরের ধাপে একই কোড বসাতে গিয়ে সংঘর্ষ হত।
     */
    private function clearTheHeads(): void
    {
        SalaryHead::query()->withoutGlobalScopes()->forceDelete();
    }

    /**
     * গুনতি ভরার মতো খাত — একই ধরন ও ক্রম, কেবল কোড আলাদা।
     *
     * ⓘ ধরন ও `sort_order` সবার এক রাখা ইচ্ছাকৃত: পর্দার ডিফল্ট সাজ
     * পে-স্লিপের ক্রম (kind → sort_order → code), তাই ওই দুইটা এক
     * হলে ক্রমটা দাঁড়ায় কেবল কোডে — আর তখন "একান্নতম সারিটা SH-051"
     * কথাটা নিশ্চিতভাবে সত্যি।
     */
    private function makeHeads(int $many): void
    {
        for ($i = 1; $i <= $many; $i++) {
            SalaryHead::query()->create([
                'company_id' => CompanyContext::id(),
                'code' => sprintf('SH-%03d', $i),
                'name_en' => sprintf('Test head %03d', $i),
                'kind' => SalaryHead::EARNING,
                'calculation' => SalaryHead::FIXED,
                'sort_order' => 0,
                'is_active' => true,
            ]);
        }

        /*
         * সেটআপটা সত্যিই যা দাবি করে তা-ই করেছে — গোনাটা **স্কোপ ছাড়া**।
         *
         * ── কেন স্কোপ ছাড়া ──────────────────────────────────────────
         * মডেলটা `BelongsToCompany`, তাই সাধারণ `query()` কেবল চলতি
         * কোম্পানির সারি গোনে। সেটআপে কোনো কারণে কোম্পানির প্রসঙ্গ
         * সরে গেলে এই গোনাটা শূন্য বলত, আর নিচের "বোতামটা নেই" দাবিটা
         * **খালি তালিকাতেই পাশ করত** — অর্থাৎ যে বাগটা ধরার জন্য
         * টেস্টটা লেখা, ঠিক সেই অবস্থাতেই সবুজ।
         *
         * নিয়মটা: উপস্থিতি প্রমাণে স্কোপসহ চলে, অনুপস্থিতি বা গোনার
         * প্রমাণে স্কোপ ছাড়া।
         */
        $this->assertSame($many, SalaryHead::withoutGlobalScopes()->count(),
            'সেটআপেই খাতগুলো বসেনি — নিচের দাবিগুলো তখন কিছুই প্রমাণ করে না।');
    }
}
