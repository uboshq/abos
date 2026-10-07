<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক এলাকায় একজনই পরিবেশক।
 *
 * ── ⛔ মালিকের নিয়ম, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"গ্রাহকের ধরন যদি পরিবেশক হয় তাহলে পয়েন্ট বাধ্যতামূলক। আর এক পয়েন্টে
 * দুজন সক্রিয় পরিবেশক হবে না — দুজন থাকলে একটা নিষ্ক্রিয় করে আরেকটা
 * সক্রিয় করতে হবে। কেন? **এক এলাকায় একজনই পরিবেশক হয়।**"*
 *
 * ⓘ পর্দায় তালিকাটা মালিক নিজে ধরেছেন: "ডুমডি বাজার" দুইবার, দুইজন
 * আলাদা গ্রাহকের পাশে।
 *
 * ── ⭐ কেন দাবিগুলো সার্ভিসে, কন্ট্রোলারে নয় ─────────────────────────
 * গ্রাহক তিনটা দরজা দিয়ে ঢোকে — ফর্ম, ইমপোর্ট, আর মোবাইল সিংক। ⚠️ নিয়মটা
 * [[CustomerRequest]]-এ লিখলে কেবল প্রথম দরজাটা পাহারা পেত। ⓘ তাই এই
 * ফাইলটা সার্ভিসকেই প্রশ্ন করে — যে দরজাই হোক, উত্তরটা একই।
 */
final class OneAreaHasOnlyOneDistributorTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $customers;

    private PartyType $distributor;

    private PartyType $retailer;

    private Location $point;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customers = app(CustomerService::class);

        $this->distributor = PartyType::query()
            ->where('code', PartyType::DISTRIBUTOR)->firstOrFail();

        $this->retailer = PartyType::query()
            ->where('code', 'RETAIL')->firstOrFail();

        $this->point = Location::query()->firstOrFail();
    }

    /**
     * ⛔ সেটআপের দাবি, আর এটা সবার আগে।
     *
     * ⓘ নিচের প্রতিটা দাবি ধরে নেয় ধরন দুইটা সত্যিই আলাদা, আর একটাই
     * পরিবেশক। ⚠️ না হলে "পরিবেশক আটকেছে" কথাটা অন্য কারণে সত্য হত।
     */
    public function test_the_ground_this_file_stands_on_is_really_there(): void
    {
        $this->assertTrue($this->distributor->isDistributor(),
            'যাকে পরিবেশক ধরে নিচ্ছি সে পরিবেশকই নয়।');

        $this->assertFalse($this->retailer->isDistributor(),
            'খুচরা বিক্রেতাকেও পরিবেশক ধরা হচ্ছে — তাহলে নিয়মটা সবার উপর খাটত।');
    }

    /** ⭐ পরিবেশকের পয়েন্ট ছাড়া হয় না। */
    public function test_a_distributor_without_a_point_is_refused(): void
    {
        try {
            $this->customers->create([
                'name_en' => 'Point-less Distributor',
                'party_type_id' => $this->distributor->id,
                'location_id' => null,
            ]);

            $this->fail('পয়েন্ট ছাড়াই পরিবেশক বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('location_id', $e->errors(),
                'ভুলটা পয়েন্টের ঘরে দেখানো হয়নি — ব্যবহারকারী বুঝতেন না কোথায় হাত দিতে হবে।');
        }
    }

    /**
     * ⓘ আর বাকিদের জন্য পয়েন্টটা ঐচ্ছিকই থাকে।
     *
     * ⚠️ এই দাবিটা না থাকলে নিয়মটা সবার উপর চেপে বসতে পারত আর কেউ টের
     * পেত না — নতুন দোকান বসানোর সময় এলাকা ভাগ ঠিক না-ও থাকতে পারে।
     */
    public function test_everybody_else_may_still_be_left_without_a_point(): void
    {
        $shop = $this->customers->create([
            'name_en' => 'Corner Shop',
            'party_type_id' => $this->retailer->id,
            'location_id' => null,
        ]);

        $this->assertNull($shop->location_id);
    }

    /** ⭐ আসল দাবি: এক পয়েন্টে দ্বিতীয় সক্রিয় পরিবেশক বসে না। */
    public function test_a_second_active_distributor_cannot_sit_on_the_same_point(): void
    {
        $first = $this->customers->create([
            'name_en' => 'Dumdi Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
        ]);

        try {
            $this->customers->create([
                'name_en' => 'Dumdi Second',
                'party_type_id' => $this->distributor->id,
                'location_id' => $this->point->id,
            ]);

            $this->fail('এক পয়েন্টে দুইজন সক্রিয় পরিবেশক বসে গেছে।');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['location_id'] ?? []);

            /*
             * ⓘ বার্তাটা বসে থাকা পরিবেশকের নাম বলে কি না — সেটাও দাবি।
             *
             * ⚠️ শুধু "আটকেছে" প্রমাণ করলে একটা অকেজো বার্তাও সবুজ
             * থাকত, আর ব্যবহারকারীকে তালিকায় গিয়ে খুঁজতে হত।
             */
            $this->assertStringContainsString($first->code, $message,
                'বার্তাটা বলেনি কে আগে থেকে বসে আছেন।');
        }
    }

    /**
     * ⭐ মালিকের বলে দেওয়া পথটা সত্যিই খোলা: একটা নিষ্ক্রিয় → আরেকটা সক্রিয়।
     *
     * ⓘ এটাই সবচেয়ে জরুরি দাবি, কারণ নিয়মটা যদি পথ না রাখত তাহলে
     * পরিবেশক বদলানোই যেত না — আর তখন মানুষ নিয়মটাকে ফাঁকি দিয়ে
     * দ্বিতীয় একটা পয়েন্ট বানাতেন, যা আরও খারাপ।
     */
    public function test_the_seat_frees_up_when_the_old_distributor_steps_down(): void
    {
        $old = $this->customers->create([
            'name_en' => 'Old Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
        ]);

        $this->customers->deactivate($old);

        $new = $this->customers->create([
            'name_en' => 'New Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
        ]);

        $this->assertTrue((bool) $new->is_active);
        $this->assertSame($this->point->id, $new->location_id);

        /*
         * ⛔ আর পুরনোজন ফিরে বসতে পারেন না — যতক্ষণ আসনটা ভরা।
         *
         * ⚠️ এই দাবিটা না থাকলে নিয়মটা কেবল **তৈরির** সময় খাটত, আর
         * "সক্রিয় করুন" বোতামটা পিছনের দরজা হয়ে থাকত।
         */
        $this->expectException(ValidationException::class);
        $this->customers->activate($old->fresh());
    }

    /**
     * ⓘ নিষ্ক্রিয় পরিবেশক কারো জায়গা নেন না — ইতিহাস থেকে যায়।
     *
     * ⚠️ পুরনো পরিবেশককে মুছে ফেললে তাঁর নামের বিলগুলো অনাথ হত।
     */
    public function test_an_inactive_distributor_blocks_nobody(): void
    {
        $this->customers->create([
            'name_en' => 'Retired Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
            'is_active' => false,
        ]);

        $sitting = $this->customers->create([
            'name_en' => 'Sitting Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
        ]);

        $this->assertSame(2, Customer::query()
            ->where('location_id', $this->point->id)
            ->where('party_type_id', $this->distributor->id)
            ->count(), 'দুইজনেরই সারি থাকার কথা — একজন কেবল নিষ্ক্রিয়।');

        $this->assertTrue((bool) $sitting->is_active);
    }

    /**
     * ⛔ সম্পাদনার দরজাটাও বন্ধ।
     *
     * ⚠️ একজন খুচরা বিক্রেতাকে পরে "পরিবেশক" বানিয়ে দিলে নিয়মটা ফাঁকি
     * পড়ত — আর ঐ পথটাই সবচেয়ে স্বাভাবিক, কারণ ব্যবসা বড় হলে মানুষ
     * সত্যিই ধরন বদলান।
     */
    public function test_you_cannot_promote_a_retailer_onto_a_taken_point(): void
    {
        $this->customers->create([
            'name_en' => 'Sitting Distributor',
            'party_type_id' => $this->distributor->id,
            'location_id' => $this->point->id,
        ]);

        $shop = $this->customers->create([
            'name_en' => 'Ambitious Shop',
            'party_type_id' => $this->retailer->id,
            'location_id' => $this->point->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->customers->update($shop, ['party_type_id' => $this->distributor->id]);
    }
}
