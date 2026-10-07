<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Audit\TimeMachine;
use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "১৫ জুন এই কাগজটা কেমন ছিল?"
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * ইতিহাসটা পুরোটাই ছিল, কিন্তু পর্দায় দেখা যেত কেবল ঘটনার তালিকা।
 * নিরীক্ষার প্রশ্নটা উল্টো দিক থেকে আসে — *"৩০ জুনের ব্যালেন্স শিটে এই
 * বিলটা কত ছিল?"* — আর তার উত্তর পেতে চল্লিশটা সারি হাতে মিলিয়ে যেতে হত।
 *
 * ── এই ফাইলের কেন্দ্রীয় দাবি ─────────────────────────────────────────
 * **আজকের মান যেন কোনোভাবেই অতীতের উত্তরে ঢুকে না পড়ে।**
 *
 * সহজ বাস্তবায়নটা হত আজকের সারি নিয়ে পেছনে হাঁটা, আর সেটা ঠিক এই
 * জায়গায় নীরবে মিথ্যা বলত: যে ঘরটা কোনোদিন বদলায়নি বা যেটা অডিটে
 * যায়ই না, তার আজকের মানটাই "ওইদিনের মান" হয়ে বসত। কোনো ভুল বার্তা
 * ছাড়া, বোঝার কোনো উপায় ছাড়া।
 *
 * তাই [[TimeMachine]] সামনের দিকে গোনে, আর যা জানা নেই তাকে "জানা নেই"
 * বলে। এই ফাইলের অর্ধেক পরীক্ষা ওই একটা কথার উপর দাঁড়িয়ে।
 */
class TheDocumentAsItStoodThatDayTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private TimeMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->machine = app(TimeMachine::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        FieldSecurity::forget();
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একটা পণ্য, তিনটা মুহূর্তে তিন রকম।
     *
     * তারিখগুলো হাতে বসানো — নাহলে তিনটা ঘটনাই এক সেকেন্ডে ঘটত, আর
     * "তার আগে" বা "তার পরে" বলে কিছু থাকত না।
     */
    private function aProductWithAPast(): Product
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $product = Product::query()->create([
            'code' => 'TM-'.mt_rand(1000, 9999),
            'name_en' => 'Ceiling Fan',
            'sale_price' => '1000',
            'purchase_price' => '700',
            'is_active' => true,
        ]);

        Carbon::setTestNow('2026-06-20 10:00:00');
        $product->update(['sale_price' => '1200']);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $product->update(['sale_price' => '1500', 'name_en' => 'Ceiling Fan Deluxe']);

        Carbon::setTestNow('2026-09-02 10:00:00');

        return $product->fresh();
    }

    // ── ১ · সময়ে ফেরা ────────────────────────────────────────────────

    /**
     * ১৫ জুনে দামটা ছিল প্রথমেরটাই।
     */
    public function test_a_field_shows_the_value_it_had_at_that_moment(): void
    {
        $product = $this->aProductWithAPast();

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-15 23:59:59'));

        $this->assertTrue($state['existed']);

        /*
         * তুলনাটা সংখ্যায়, লেখায় নয়।
         *
         * অডিট মানটা যেভাবে পেয়েছে সেভাবেই রাখে, আর decimal ঘরে সেটা
         * `1000` বা `1000.0000` — দুইটাই হতে পারে, কোন পথে সারিটা
         * এসেছে তার উপর। দাবিটা ওই লেখার আকার নিয়ে নয়, **অঙ্কটা
         * নিয়ে**; লেখা ধরে মিলালে একদিন ঠিক কোডেই টেস্ট লাল হত।
         */
        $this->assertSame(0, bccomp('1000', (string) $state['fields']['sale_price']['value'], 4));
        $this->assertSame(TimeMachine::KNOWN, $state['fields']['sale_price']['certainty']);
    }

    /**
     * ২৫ জুনে দ্বিতীয়টা, আর ১ অগাস্টে তৃতীয়টা।
     *
     * তিনটা মুহূর্ত একসাথে দেখা হয় কারণ একটা মাত্র দেখলে "সবসময়
     * প্রথমটাই ফেরে" ধরনের একটা ভুলও সবুজ থাকত।
     */
    public function test_each_moment_gets_its_own_answer(): void
    {
        $product = $this->aProductWithAPast();

        $this->assertSame(0, bccomp('1200', (string) $this->machine
            ->at(Product::class, $product->id, Carbon::parse('2026-06-25 23:59:59'))['fields']['sale_price']['value'], 4));

        $this->assertSame(0, bccomp('1500', (string) $this->machine
            ->at(Product::class, $product->id, Carbon::parse('2026-08-01 23:59:59'))['fields']['sale_price']['value'], 4));
    }

    /**
     * আর তৈরির আগে কাগজটা ছিলই না।
     *
     * খালি ঘর দেখানোর বদলে এটা স্পষ্ট বলা দরকার — নাহলে কেউ "ওইদিন
     * সব শূন্য ছিল" পড়ে নিতেন, আর শূন্য একটা সংখ্যা।
     */
    public function test_before_it_was_created_the_record_did_not_exist(): void
    {
        $product = $this->aProductWithAPast();

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-05-01 23:59:59'));

        $this->assertFalse($state['existed']);
        $this->assertSame([], $state['fields']);
    }

    // ── ২ · যা জানা নেই ──────────────────────────────────────────────

    /**
     * তৈরির সময় খালি থাকা ঘর আজকের মান দেখায় না।
     *
     * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────
     * `barcode` তৈরির সময় বসানো হয়নি, তাই [[AuditEngine]] ওটা লেখেনি।
     * আজ বসানো হয়েছে। **পেছনে হাঁটা কোনো বাস্তবায়ন এখানে আজকের
     * বারকোডটাই "১৫ জুনের বারকোড" বলে দেখাত** — আত্মবিশ্বাসের সাথে,
     * কোনো সতর্কবার্তা ছাড়া।
     *
     * এখানে উত্তরটা "ওইদিন খালি ছিল", আর সেটাই সত্যি।
     */
    public function test_a_field_filled_in_later_does_not_leak_backwards(): void
    {
        $product = $this->aProductWithAPast();

        $product->update(['barcode' => '8901234567890']);

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-15 23:59:59'));

        $this->assertNotSame('8901234567890', $state['fields']['barcode']['value'],
            'আজকের বারকোডটা অতীতে চুইয়ে পড়েছে।');
        $this->assertSame(TimeMachine::EMPTY_THEN, $state['fields']['barcode']['certainty']);
        $this->assertNull($state['fields']['barcode']['value']);
    }

    /**
     * ইতিহাস শুরুর আগের কোনো তারিখ চাইলে উত্তরটা "জানা নেই"।
     *
     * ── কেন এটাই সবচেয়ে সহজে ভুল হত ─────────────────────────────────
     * পেছনে হাঁটা কোনো বাস্তবায়নের কাছে এই প্রশ্নটার উত্তর দেখতে
     * একদম স্বাভাবিক লাগে: বদলের তালিকায় কিছু নেই, তাই আজকের মানটাই
     * দেখিয়ে দাও। **আর সেটাই মিথ্যা** — অডিট তখনো চালু হয়নি, অর্থাৎ
     * ওই সময়ে ঘরটা বদলে থাকতে পারে আর কেউ জানত না।
     *
     * এখানে ইতিহাসের প্রথম সারিটা ফেলে দিয়ে ওই ফাঁকটা বানানো হয়, আর
     * তার আগের একটা তারিখ চাওয়া হয়।
     */
    public function test_a_date_before_the_history_begins_is_answered_with_do_not_know(): void
    {
        $product = $this->aProductWithAPast();

        AuditTrail::query()
            ->forRecord(Product::class, $product->id)
            ->where('action', AuditTrail::CREATED)
            ->delete();

        // এখন ইতিহাস শুরু ২০ জুনে; জিজ্ঞেস করছি ১০ জুনের কথা
        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-10 23:59:59'));

        $this->assertFalse($state['complete'], 'ইতিহাস শুরুর আগের তারিখ, তবু সম্পূর্ণ বলছে।');
        $this->assertSame(TimeMachine::UNTRACKED, $state['fields']['purchase_price']['certainty'],
            'অডিট চালুর আগের একটা মুহূর্ত সম্পর্কে নিশ্চিত বলে দাবি করা হচ্ছে।');
        $this->assertNull($state['fields']['purchase_price']['value'],
            'জানা নেই বলার পরেও একটা সংখ্যা দেখানো হচ্ছে।');
    }

    /**
     * অথচ ইতিহাস চালু থাকার পর থেকে যে ঘর বদলায়নি, তার উত্তর নিশ্চিত।
     *
     * ── কেন এটা অনুমান নয় ───────────────────────────────────────────
     * অডিট চালু ছিল, আর ওই ঘরের কোনো পরিবর্তন লেখা নেই। প্রতিটা
     * পরিবর্তন লেখা হয় (সেটাই [[AuditCoverageTest]]-এর দাবি), তাই
     * "লেখা নেই" মানে "বদলায়নি" — উপসংহার, আশা নয়।
     */
    public function test_a_field_that_never_changed_is_answered_with_certainty(): void
    {
        $product = $this->aProductWithAPast();

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-15 23:59:59'));

        $this->assertTrue($state['complete']);
        $this->assertSame(TimeMachine::KNOWN, $state['fields']['purchase_price']['certainty']);
    }

    // ── ৩ · অনুমতি অতীতেও খাটে ───────────────────────────────────────

    /**
     * ক্রয়মূল্য দেখার চাবি না থাকলে অতীতেও ঢাকা।
     *
     * ── কেন এটা না থাকলে পুরো ঘর-পাহারাটা অর্থহীন ────────────────────
     * [[FieldSecurity]] আজকের পর্দায় ক্রয়মূল্য ঢেকে রাখে। কিন্তু
     * সময়যন্ত্রটা যদি একই সংখ্যা "গত মাসে কেমন ছিল" প্রশ্নের উত্তরে
     * খুলে দিত, তবে তালাটা থাকত কেবল একটা দরজায় — আর পাশেরটা খোলা।
     */
    public function test_a_masked_field_stays_masked_in_the_past(): void
    {
        $product = $this->aProductWithAPast();

        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman);
        FieldSecurity::forget();

        $this->assertFalse(
            $salesman->can('inventory.cost.view'),
            'বিক্রয়কর্মীর কাছে ক্রয়মূল্যের চাবি আছে — তাহলে এই পরীক্ষাটা কিছুই প্রমাণ করছে না।',
        );

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-15 23:59:59'));

        $this->assertSame(FieldSecurity::mask(), $state['fields']['purchase_price']['value']);
    }

    /** আর চাবি থাকলে খোলা। */
    public function test_with_the_key_the_past_value_is_readable(): void
    {
        $product = $this->aProductWithAPast();

        $state = $this->machine->at(Product::class, $product->id, Carbon::parse('2026-06-15 23:59:59'));

        $this->assertSame(0, bccomp('700', (string) $state['fields']['purchase_price']['value'], 4));
    }

    // ── ৪ · দরজাটা ───────────────────────────────────────────────────

    /**
     * পর্দাটা অডিট দেখার অনুমতির পেছনে, আর সত্যিই খোলে।
     */
    public function test_the_screen_opens_for_someone_who_may_read_the_audit(): void
    {
        $product = $this->aProductWithAPast();

        $trail = AuditTrail::query()
            ->forRecord(Product::class, $product->id)
            ->firstOrFail();

        $this->get(route('governance.audit.at', $trail->id).'?on=2026-06-15')
            ->assertOk()
            ->assertSee('1000');
    }

    /**
     * অন্য কোম্পানির কাগজের ইতিহাস খোলা যায় না।
     *
     * ── কেন এটা আলাদা করে লেখা ──────────────────────────────────────
     * ঠিকানায় অডিট-সারির আইডি যায়, আর আইডি অনুমান করা যায়। পাহারাটা
     * `AuditTrail`-এর কোম্পানি-ছাঁকনিতে বসানো, অর্থাৎ **অন্য কারও
     * লেখা কোডে** — আর একদিন কেউ `withoutGlobalScopes()` বসিয়ে দিলে
     * এই দরজাটা নীরবে খুলে যেত।
     *
     * ── আর কেন কোম্পানিটা ব্যবহারকারীর উপর বদলানো হয় ────────────────
     * প্রথম লেখায় শুধু `CompanyContext::set()` ছিল, আর পরীক্ষাটা লাল
     * হলো — **ঠিক কারণেই**। অনুরোধ এলে [[ResolveCompanyContext]]
     * ব্যবহারকারীর নিজের বাছাই থেকে প্রসঙ্গটা **আবার বসায়**, তাই
     * হাতে বসানো প্রসঙ্গ টেকে না।
     *
     * ভুলটা আমার পরিমাপে ছিল, কোডে নয় — আর এখন দাবিটা আরও ধারালো:
     * **একই মানুষ**, যিনি দুই কোম্পানিতেই আছেন, অন্যটা বেছে নিলে
     * আগেরটার ইতিহাস তাঁর জন্যও বন্ধ।
     */
    public function test_another_companys_history_cannot_be_opened(): void
    {
        $product = $this->aProductWithAPast();

        $trail = AuditTrail::query()
            ->forRecord(Product::class, $product->id)
            ->firstOrFail();

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->switchCompany($mart->id);

        $this->actingAs($owner->fresh());

        $this->get(route('governance.audit.at', $trail->id))->assertNotFound();
    }

    /** আর যাঁর অনুমতি নেই তাঁর জন্য বন্ধ। */
    public function test_the_screen_is_closed_to_everyone_else(): void
    {
        $product = $this->aProductWithAPast();

        $trail = AuditTrail::query()
            ->forRecord(Product::class, $product->id)
            ->firstOrFail();

        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->get(route('governance.audit.at', $trail->id))->assertForbidden();
    }
}
