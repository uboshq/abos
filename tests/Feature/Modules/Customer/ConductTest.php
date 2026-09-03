<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Customer\Services\ConductService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Customer\Support\ConductType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পার্টির আচরণ — Status নয়, "কেমন কারবার"।
 *
 * চার সিদ্ধান্তই এখানে পরীক্ষিত: বাঁধা তালিকা · কে লিখল বাধ্যতামূলক ·
 * নামানো যায় মোছা নয় · OTHER-এ নোট বাধ্যতামূলক। আর A1-এর চুক্তি:
 * activeConduct() ঝুঁকি আগে ফেরায়, এক কোয়েরিতে।
 */
class ConductTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private ConductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->service = app(ConductService::class);
    }

    private function customer(string $name = 'Rahim Store'): Customer
    {
        return app(CustomerService::class)->create([
            'name_en' => $name,
            'credit_limit' => 0,
            'credit_days' => 0,
        ]);
    }

    public function test_a_recorded_flag_carries_who_and_when(): void
    {
        $customer = $this->customer();

        $note = $this->service->record($customer, 'LATE_PAYMENT');

        $this->assertTrue($note->is_active);
        $this->assertSame($this->user->id, $note->recorded_by);
        $this->assertNotNull($note->recorded_at);
        $this->assertSame('risk', $note->severity());
    }

    public function test_other_needs_a_note(): void
    {
        $customer = $this->customer();

        $this->expectException(ValidationException::class);
        $this->service->record($customer, ConductType::OTHER, null);
    }

    public function test_other_with_a_note_is_fine(): void
    {
        $customer = $this->customer();

        $note = $this->service->record($customer, ConductType::OTHER, 'Keeps the gate locked past noon');

        $this->assertSame(ConductType::OTHER, $note->type);
        $this->assertSame('Keeps the gate locked past noon', $note->note);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(ValidationException::class);
        $this->service->record($customer, 'NOT_A_REAL_TYPE');
    }

    /** ★ নামানো যায়, মোছা নয় — সারিটা থাকে, শুধু চলমান নয়। */
    public function test_retiring_keeps_the_row_as_history(): void
    {
        $customer = $this->customer();
        $note = $this->service->record($customer, 'CHEQUE_DISHONOURED');

        $this->service->retire($note);

        $fresh = CustomerConduct::query()->find($note->id);
        $this->assertNotNull($fresh, 'পতাকা নামাতে গিয়ে সারিটাই মুছে গেছে।');
        $this->assertFalse($fresh->is_active);
        $this->assertSame($this->user->id, $fresh->retired_by);
        $this->assertNotNull($fresh->retired_at);
    }

    /** ★ চিপ অগ্রাধিকার: ঝুঁকি আগে, তারপর লক্ষণীয়, ভালো শেষে। */
    public function test_active_conduct_comes_back_risk_first(): void
    {
        $customer = $this->customer();
        $this->service->record($customer, 'QUICK_UNLOADING');   // good
        $this->service->record($customer, 'SLOW_UNLOADING');    // notice
        $this->service->record($customer, 'LATE_PAYMENT');      // risk

        $severities = $customer->fresh()->activeConduct()->pluck('severity')->all();

        $this->assertSame(['risk', 'notice', 'good'], $severities);
    }

    public function test_retired_flags_do_not_show_as_active(): void
    {
        $customer = $this->customer();
        $keep = $this->service->record($customer, 'PAYS_ON_TIME');
        $drop = $this->service->record($customer, 'DISPUTES_INVOICE');

        $this->service->retire($drop);

        $active = $customer->fresh()->activeConduct();
        $this->assertCount(1, $active);
        $this->assertSame('good', $active->first()['severity']);
    }

    public function test_the_screen_records_through_the_endpoint(): void
    {
        $customer = $this->customer();

        $this->post(route('customer.conduct.store', $customer), [
            'type' => 'REFUSES_AT_GATE',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_conduct_notes', [
            'customer_id' => $customer->id,
            'type' => 'REFUSES_AT_GATE',
            'is_active' => true,
        ]);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $customer = $this->customer();
        /*
         * সদস্যপদ pivot-এ, `users`-এর কলামে নয়।
         *
         * ⚠️ এখানে লেখা ছিল `['company_id' => …]`, অথচ `users` টেবিলে ওই
         * কলামটাই নেই — তাই পরীক্ষাটা SQL ত্রুটিতে ভেঙে পড়ত, **ব্যর্থতা
         * নয়, error হিসেবে**। ⓘ আর ত্রুটির বার্তাটা হুবহু সেই বার্তা
         * যেটা দুইটা সুইট একসাথে চললেও আসে ("Unknown column company_id"),
         * তাই এটাকে আবর্জনা ভেবে পার করে দেওয়া সহজ ছিল।
         *
         * ⭐ আর ওটাই এই পরীক্ষার দাম: সে পাহারা দেয় *"অনুমতি ছাড়া কেউ
         * আচরণের নোট লিখতে পারে না"* — অর্থাৎ এতদিন **কেউ জানত না দরজাটা
         * আদৌ বন্ধ কি না**। আজ একই ভুল আরও দুই জায়গায় সারানো হয়েছে;
         * এটা তৃতীয় ও শেষ (গোটা `tests/` খুঁজে দেখা)।
         */
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($stranger)
            ->post(route('customer.conduct.store', $customer), ['type' => 'LATE_PAYMENT'])
            ->assertForbidden();
    }

    /* ── কাউন্টারে পড়া ─────────────────────────────────────────── */

    /**
     * ⭐ সবচেয়ে গুরুতর পতাকাটা আগে।
     *
     * ⚠️ ── একটা ফাঁদ, প্রথমবার এতেই পড়েছি ────────────────────────────
     * `ConductType::GOOD` · `RISK` · `NOTICE` **ধরন নয়, গুরুত্ব**।
     * ধরনগুলো `TYPES`-এর চাবি — `LATE_PAYMENT`, `PAYS_ON_TIME`,
     * `SLOW_UNLOADING`…, আর প্রতিটার সাথে [দল, গুরুত্ব] বাঁধা।
     *
     * ⓘ নামগুলো পাশাপাশি বসে আছে বলেই ভুলটা সহজ, আর
     * `record()` ওটা ধরে ফেলে ("এটা চেনা কোনো আচরণের ধরন নয়") —
     * অর্থাৎ যাচাইটা সত্যিই কাজ করে।
     *
     * কাউন্টারে জায়গা নেই আর সময়ও নেই — বিক্রয়কর্মী নামের পাশে এক
     * ঝলক দেখেন। "সবসময় সময়মতো দেয়" আর "৯০ দিন নেয়" পাশাপাশি থাকলে
     * চোখ কোনটায় পড়বে তার কোনো নিশ্চয়তা নেই, আর ভুলটার দাম মালের।
     */
    public function test_the_worst_flag_comes_first(): void
    {
        $customer = $this->customer('Ordering Test');

        $service = app(ConductService::class);
        $service->record($customer, 'PAYS_ON_TIME');
        $service->record($customer, 'LATE_PAYMENT');
        $service->record($customer, 'SLOW_UNLOADING');

        $flags = $service->activeFor($customer);

        $this->assertCount(3, $flags);
        $this->assertSame(ConductType::RISK, $flags->first()->severity());
    }

    /** নামানো পতাকা কাউন্টারে আর দেখা যায় না — কিন্তু সারিটা থাকে। */
    public function test_a_retired_flag_leaves_the_counter_but_not_the_history(): void
    {
        $customer = $this->customer('Retire Test');

        $flag = app(ConductService::class)->record($customer, 'LATE_PAYMENT');
        app(ConductService::class)->retire($flag);

        $this->assertCount(0, app(ConductService::class)->activeFor($customer));
        $this->assertNotNull(CustomerConduct::query()->find($flag->id));
    }

    /**
     * ⚠️ একজনের পতাকা আরেকজনের সারিতে যায় না।
     *
     * তালিকার পর্দায় সব গ্রাহকের পতাকা একসাথে তোলা হয়, তাই ভুল হলে
     * সেটা **সবচেয়ে খারাপ ধরনের ভুল**: বিক্রয়কর্মী একজন ভালো পার্টিকে
     * সন্দেহ করবেন, আর একজন ঝুঁকিপূর্ণ পার্টি পরিষ্কার দেখাবেন।
     */
    public function test_many_customers_at_once_never_mix_up_whose_flag_is_whose(): void
    {
        $rahim = $this->customer('Rahim Flags');
        $karim = $this->customer('Karim Flags');

        $service = app(ConductService::class);
        $service->record($rahim, 'LATE_PAYMENT');
        $service->record($karim, 'PAYS_ON_TIME');

        $map = $service->activeForMany([$rahim->id, $karim->id]);

        $this->assertSame(ConductType::RISK, $map[$rahim->id]->first()->severity());
        $this->assertSame(ConductType::GOOD, $map[$karim->id]->first()->severity());
    }

    /**
     * অনেক গ্রাহক, একটাই কোয়েরি।
     *
     * ⚠️ সারি প্রতি একটা কোয়েরি হলে পঞ্চাশ সারির পর্দায় পঞ্চাশটা —
     * আর ডিপোর নেট ধীর। মাইগ্রেশনের index-টা ঠিক এই কাজের জন্যই বসানো।
     */
    public function test_the_counter_asks_once_not_once_per_row(): void
    {
        $ids = [];

        foreach (['A', 'B', 'C'] as $name) {
            $c = $this->customer('Batch '.$name);
            app(ConductService::class)->record($c, 'SLOW_UNLOADING');
            $ids[] = $c->id;
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(ConductService::class)->activeForMany($ids);
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(1, $queries, 'পতাকা তুলতে একটার বেশি কোয়েরি চলেছে।');
    }

    /** কারো পতাকা না থাকলে খালি — কোনো ব্যতিক্রম নয়। */
    public function test_a_customer_with_no_flags_is_simply_empty(): void
    {
        $customer = $this->customer('Clean Party');

        $this->assertCount(0, app(ConductService::class)->activeFor($customer));
        $this->assertCount(0, app(ConductService::class)->activeForMany([$customer->id]));
    }
}
