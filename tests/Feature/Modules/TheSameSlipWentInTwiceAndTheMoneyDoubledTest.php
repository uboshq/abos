<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই স্লিপ দুইবার ঢুকল, আর টাকাটা দ্বিগুণ হয়ে গেল।
 *
 * ── মালিকের যুক্তি, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * *"গত সপ্তাহে একটা অর্ডার দিয়েছে, সে সব প্রোডাক্ট নিবে — তাহলে
 * quantity তো same, date same না। অনলাইন টাকা payment same হতে পারে,
 * slip same হবে না, slip no same হবে না।"*
 *
 * ⭐ অর্থাৎ কাগজের নকল ধরার একমাত্র সৎ জায়গা **রেফারেন্স নম্বর** —
 * অঙ্ক নয়, তারিখ নয়, পরিমাণ নয়। একই গ্রাহক পরপর দুই সপ্তাহে হুবহু একই
 * মাল নিতে পারেন আর হুবহু একই টাকা দিতে পারেন; ওটা নকল নয়, ওটাই ব্যবসা।
 *
 * ⛔ এই পরীক্ষাটা তাই **দুই দিক থেকেই** মাপে: নকলটা আটকায় কি না, আর
 * সৎ পুনরাবৃত্তিটা ঢোকে কি না। ⚠️ প্রথমটা একা লিখলে "সব আটকে দাও"
 * লিখেও সবুজ হত।
 */
class TheSameSlipWentInTwiceAndTheMoneyDoubledTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    /** ⛔ একই গ্রাহকের একই স্লিপ দ্বিতীয়বার বসে না। */
    public function test_the_same_bkash_slip_cannot_be_entered_twice(): void
    {
        $this->collect('BK-77219', '5000', '2026-09-01');

        $this->expectException(ValidationException::class);

        $this->collect('BK-77219', '5000', '2026-09-08');
    }

    /**
     * ⭐ কিন্তু সৎ পুনরাবৃত্তি আটকায় না — এটাই মালিকের আসল কথা।
     *
     * একই গ্রাহক, একই অঙ্ক, ভিন্ন সপ্তাহ, ভিন্ন স্লিপ। ⚠️ অঙ্ক ধরে
     * নকল খুঁজলে এই সৎ লেনদেনটাই আটকে যেত।
     */
    public function test_the_same_amount_next_week_on_a_new_slip_goes_in_fine(): void
    {
        $first = $this->collect('BK-77219', '5000', '2026-09-01');
        $second = $this->collect('BK-88431', '5000', '2026-09-08');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('5000.0000', (string) $second->amount);
    }

    /**
     * ⓘ স্লিপ নম্বর ছাড়া নগদ আদায় — যতবার খুশি।
     *
     * ⚠️ ডিপোর বেশিরভাগ আদায় হাতে হাতে, কোনো স্লিপ নেই। খালি ঘরকে
     * "নকল" গণ্য করলে দিনের দ্বিতীয় নগদ আদায়টাই আটকে যেত।
     */
    public function test_cash_with_no_slip_is_never_a_duplicate(): void
    {
        $this->collect(null, '1200', '2026-09-01');
        $this->collect(null, '1200', '2026-09-01');

        $this->assertSame(2, \App\Modules\Sales\Models\Collection::query()
            ->where('customer_id', $this->customer->id)
            ->whereNull('instrument_no')
            ->count());
    }

    /**
     * ⭐ দুইটা ব্যাংকের স্লিপ নম্বর এক হতেই পারে।
     *
     * ⚠️ পাহারাটা **পক্ষ ধরে**, গোটা কোম্পানি ধরে নয় — নাহলে দ্বিতীয়
     * সরবরাহকারীর `000123` স্লিপটা আটকে যেত, অথচ ওটা সম্পূর্ণ বৈধ।
     * ⓘ প্রতিটা প্রতিষ্ঠানের নিজের ক্রম আছে।
     */
    public function test_two_parties_may_share_a_slip_number(): void
    {
        $other = Customer::query()->where('id', '!=', $this->customer->id)->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় গ্রাহক নেই।');
        }

        $this->collect('000123', '900', '2026-09-01');

        $second = app(CollectionService::class)->create([
            'customer_id' => $other->id,
            'trx_date' => '2026-09-01',
            'amount' => '900',
            'instrument_no' => '000123',
        ], []);

        $this->assertSame('000123', $second->instrument_no);
    }

    /**
     * ⭐ বড়-ছোট হরফ আর সামনে-পিছনের ফাঁকা মিলিয়ে দেখা হয়।
     *
     * ⚠️ কেউ `bk-77219` টাইপ করলে বা কপি-পেস্টে একটা স্পেস এলে ওটা
     * নতুন স্লিপ গণ্য হত, আর নকলটা ঢুকেই যেত — পাহারা থাকা সত্ত্বেও।
     */
    public function test_case_and_stray_spaces_do_not_slip_past(): void
    {
        $this->collect('BK-77219', '5000', '2026-09-01');

        $this->expectException(ValidationException::class);

        $this->collect('  bk-77219 ', '5000', '2026-09-08');
    }

    private function collect(?string $slip, string $amount, string $on): \App\Modules\Sales\Models\Collection
    {
        return app(CollectionService::class)->create([
            'customer_id' => $this->customer->id,
            'trx_date' => $on,
            'amount' => $amount,
            'instrument_no' => $slip,
        ], []);
    }
}
