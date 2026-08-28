<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * আমদানি করা কাগজ থাকলে নতুন কাগজ বসানো যায় কি না।
 *
 * ── কেন এই পরীক্ষাটা লেখা হলো ────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ মালিক বললেন লাইভে মানুষের মতো সব করে দেখতে। প্রথম
 * নতুন গ্রাহক বসাতে গিয়েই উত্তর এল **"এই কোডে আরেকজন গ্রাহক আছে"** —
 * অথচ কোডের ঘরটা খালি ছিল, অর্থাৎ নম্বরটা সিস্টেমের নিজেরই দেওয়া।
 *
 * Trade Depot-এ ৩১টা গ্রাহক আগে থেকেই ছিল (`CUS-0001`…`CUS-0031`),
 * সবই আমদানি করা, আর CUS সিরিজের পরের নম্বর তখনো **১**। সিরিজ জানত
 * না ওই কাগজগুলো আছে।
 *
 * ── কেন কোনো পরীক্ষা এটা ধরেনি ───────────────────────────────────────
 * প্রতিটা পরীক্ষা খালি ডাটাবেজে শুরু করে, নিজের গ্রাহক নিজে বানায়, আর
 * তখন সিরিজ ও টেবিল দুইটাই এক থেকে শুরু হয় — দুইটা সবসময় মিলে যায়।
 * ভুলটা কেবল **আমদানির পরে** দেখা দেয়, আর আমদানি কোনো পরীক্ষায় ছিল না।
 *
 * তাই এখানে আমদানিটা হাতে বানানো হয়: সিরিজকে না জানিয়ে একটা গ্রাহক
 * বসিয়ে দেওয়া, ঠিক যেভাবে ওই ৩১টা এসেছিল।
 */
class ThirtyOneCustomersWereAlreadyThereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /**
     * সিরিজকে না জানিয়ে বসানো একটা গ্রাহক — আমদানি ঠিক এটাই করে।
     */
    private function importCustomerWithCode(string $code): void
    {
        Customer::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => 'Imported '.$code,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);
    }

    private function series(string $docType): NumberSeries
    {
        return NumberSeries::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('doc_type', $docType)
            ->firstOrFail();
    }

    public function test_an_imported_code_blocks_the_next_new_one_until_the_series_catches_up(): void
    {
        /*
         * ── আমদানির অবস্থাটা হাতে বানানো ────────────────────────────
         * DemoSeeder গ্রাহক বসায় **সার্ভিসের ভেতর দিয়ে**, তাই সেখানে
         * সিরিজ আর টেবিল সবসময় মিলে থাকে — আর ঠিক এই কারণেই কোনো
         * পরীক্ষা ভুলটা কোনোদিন দেখেনি।
         *
         * লাইভে যা ঘটেছিল তা হলো উল্টোটা: কাগজ আছে, সিরিজ পেছনে।
         * সেটাই এখানে বানানো হয় — সিরিজটাকে এক-এ ফিরিয়ে দিয়ে।
         */
        $this->series('CUS')->forceFill(['next_number' => 1])->save();

        $this->assertTrue(
            Customer::query()->where('company_id', $this->company->id)->where('code', 'CUS-0001')->exists(),
            'এই পরীক্ষার শুরুর শর্ত: CUS-0001 কোডে একজন আগে থেকেই আছেন।',
        );

        /*
         * ── এটাই সেই ব্যর্থতা ──────────────────────────────────────
         * ইঞ্জিন `CUS-0001` ফেরত দেয়, আর ওই কোডে একজন আছে। মালিক
         * পর্দায় ঠিক এই বার্তাটাই পেয়েছেন।
         */
        $this->expectExceptionMessageMatches('/CUS-0001|আরেকজন/u');

        app(CustomerService::class)->create([
            'name_en' => 'The next one',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);
    }

    public function test_after_catching_up_the_next_new_customer_simply_works(): void
    {
        /* বসে থাকা কাগজ সিরিজের অনেক আগে — লাইভে ছিল ৩১টা। */
        $this->importCustomerWithCode('CUS-0031');
        $this->series('CUS')->forceFill(['next_number' => 1])->save();

        $this->artisan('abos:catch-up-numbers', ['--company' => $this->company->id])
            ->assertSuccessful();

        $this->assertSame(32, $this->series('CUS')->next_number,
            'সিরিজটা আসল কাগজের ঠিক পরের নম্বরে দাঁড়ানোর কথা।');

        $customer = app(CustomerService::class)->create([
            'name_en' => 'The thirty second',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $this->assertSame('CUS-0032', $customer->code);
    }

    /**
     * উপসর্গ মেলানোয় `PR` আর `PRD` আলাদা থাকে।
     *
     * ── কেন এটা আলাদা করে পাহারা দেওয়া ───────────────────────────────
     * ড্যাশ ছাড়া মেলালে `PRD-0007` দেখে PR সিরিজও সাতে লাফ দিত। তাতে
     * ক্রয় ফেরতের নম্বরে ছয়টা ফাঁক পড়ত — আর নিরীক্ষায় "ফাঁক কেন"
     * প্রশ্নের কোনো উত্তর থাকত না, কারণ ফাঁকটা কেউ বানায়নি, একটা
     * ভুল মিল বানিয়েছে।
     */
    public function test_a_longer_prefix_does_not_drag_a_shorter_one_forward(): void
    {
        $before = $this->series('PR')->next_number;

        Customer::query()->create([
            'company_id' => $this->company->id,
            'code' => 'PRD-0007',
            'name_en' => 'Looks like a product code',
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);

        $this->artisan('abos:catch-up-numbers', ['--company' => $this->company->id])
            ->assertSuccessful();

        $this->assertSame($before, $this->series('PR')->next_number,
            'PRD-এর নম্বর দেখে PR সিরিজ লাফ দিয়েছে — উপসর্গের সাথে ড্যাশটা মেলানো হয়নি।');
    }

    /**
     * এক কোম্পানির কাগজ দেখে অন্য কোম্পানির সিরিজ নড়ে না।
     */
    public function test_one_companys_papers_do_not_move_another_companys_series(): void
    {
        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        $before = NumberSeries::query()->withoutGlobalScopes()
            ->where('company_id', $other->id)->where('doc_type', 'CUS')->value('next_number');

        $this->importCustomerWithCode('CUS-0144');

        $this->artisan('abos:catch-up-numbers')->assertSuccessful();

        $after = NumberSeries::query()->withoutGlobalScopes()
            ->where('company_id', $other->id)->where('doc_type', 'CUS')->value('next_number');

        $this->assertSame($before, $after,
            'অন্য কোম্পানির সিরিজ নড়েছে — খোঁজাটা কোম্পানিতে সীমাবদ্ধ থাকেনি।');
    }

    /**
     * এক গ্রাহক বসিয়ে ইঞ্জিন কী দেয়, সেটাই আসল প্রমাণ।
     *
     * উপরের পরীক্ষাগুলো সিরিজের ঘরটা দেখে; এটা দেখে **কাগজে কী ছাপা
     * হবে** — কারণ শেষমেশ ব্যবহারকারী ওটাই পান।
     */
    public function test_the_engine_hands_out_the_number_after_the_last_paper(): void
    {
        $this->importCustomerWithCode('CUS-0109');

        $this->artisan('abos:catch-up-numbers', ['--company' => $this->company->id])
            ->assertSuccessful();

        $this->assertSame('CUS-0110', app(NumberSeriesEngine::class)->next('CUS'));
    }
}
