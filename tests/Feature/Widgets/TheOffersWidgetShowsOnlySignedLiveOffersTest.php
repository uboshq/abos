<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের হোম-স্ক্রিনে চলতি অফার — উইজেট `offers` (উইজেটের চুক্তি §১৮; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; [[OffersSummary]])।
 *
 * দাবি, আসল দরজা দিয়ে (`GET /api/v1/widgets/summary`):
 *  - সই হওয়া চলতি অফার আসে; ⛔ একই তারিখের সই-ছাড়া অফার (অবস্থা চালু অথচ সই নেই, বা জমা দেওয়া) আসে না;
 *  - ⛔ মেয়াদ পেরোনো আসে না, আর যেটা এখনো শুরু হয়নি সেটাও না;
 *  - আগে যেটা শেষ হয় সেটা আগে, সর্বোচ্চ ৮টা সারি, আর `count` সব সই হওয়া চলতি অফারের;
 *  - সারির `link` ওয়েবের অফারের পাতা, উইজেটের `link` ওয়েবের অফারের তালিকা;
 *  - চাবি (`promotion.view`) থাকলে বিক্রয়কর্মীও পান; চাবি ছাড়া উইজেটটাই উত্তরে নেই।
 */
final class TheOffersWidgetShowsOnlySignedLiveOffersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(SettingsService::class)->set(PhoneModules::key('promotion'), true);
    }

    public function test_only_a_signed_offer_running_today_comes(): void
    {
        $signed = $this->offer('SIGNED');
        $unsigned = $this->offer('UNSIGNED', signed: false);
        $submitted = $this->offer('SUBMITTED', status: PromotionStatus::SUBMITTED, signed: false);
        $ended = $this->offer('ENDED', starts: Carbon::today()->subDays(20), ends: Carbon::yesterday());
        $future = $this->offer('FUTURE', starts: Carbon::tomorrow(), ends: Carbon::today()->addDays(9));

        $widget = $this->widget($this->owner);
        $this->assertIsArray($widget, '⛔ মালিকের উত্তরে অফারের উইজেটই নেই।');
        $links = array_column($widget['rows'], 'link');

        $this->assertContains('/promotions/'.$signed->id, $links, 'সই হওয়া চলতি অফার উইজেটে নেই।');
        $this->assertNotContains('/promotions/'.$unsigned->id, $links, '⛔ সই ছাড়া অফার (অবস্থা চালু) উইজেটে।');
        $this->assertNotContains('/promotions/'.$submitted->id, $links, '⛔ অনুমোদনের অপেক্ষার অফার উইজেটে।');
        $this->assertNotContains('/promotions/'.$ended->id, $links, '⛔ মেয়াদ পেরোনো অফার উইজেটে।');
        $this->assertNotContains('/promotions/'.$future->id, $links, '⛔ এখনো শুরু না হওয়া অফার উইজেটে।');
        $this->assertSame(1, $widget['count']);

        $row = $widget['rows'][0];
        $this->assertSame('অফার SIGNED', $row['title']);
        $this->assertNull($row['product']);
        $this->assertNull($row['scope']);
        $this->assertSame(Carbon::today()->addWeek()->toDateString(), $row['ends_on']);
        $this->assertSame('/promotions', $widget['link']);
        $this->assertArrayNotHasKey('as_of', $widget, '⛔ সময় উইজেটের ভিতরে — দরজার ETag প্রতিবার বদলায়, 304 কখনো আসে না (দরজার asOf-ই যথেষ্ট)।');
    }

    public function test_soonest_end_first_at_most_eight_and_the_count_is_all_of_them(): void
    {
        // ⓘ উল্টো ক্রমে বসানো — দরজা আর উইজেট নিজেরা সাজায় কি না
        $byDay = [];
        foreach ([10, 3, 7, 1, 9, 5, 2, 8, 4, 6] as $day) {
            $byDay[$day] = $this->offer('D'.$day, ends: Carbon::today()->addDays($day));
        }
        $this->offer('NOSIGN', ends: Carbon::today(), signed: false);

        $widget = $this->widget($this->owner);
        $this->assertSame(10, $widget['count'], '⛔ গোনা ভুল — সব সই হওয়া চলতি অফার নয়।');
        $this->assertCount(8, $widget['rows'], '⛔ সারি ৮টার বেশি বা কম।');

        $expected = array_map(fn (int $day) => Carbon::today()->addDays($day)->toDateString(), range(1, 8));
        $this->assertSame($expected, array_column($widget['rows'], 'ends_on'), '⛔ আগে শেষ হওয়াটা আগে নয়।');
        $this->assertSame(array_map(fn (int $day) => '/promotions/'.$byDay[$day]->id, range(1, 8)), array_column($widget['rows'], 'link'));
    }

    public function test_a_salesman_with_the_offers_key_gets_it_and_without_any_key_it_is_absent(): void
    {
        $this->offer('ANY');

        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        CompanyContext::forCompany($this->company->id, fn () => $sales->givePermissionTo(Permission::findOrCreate('promotion.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(1, $this->widget($sales)['count'] ?? null, 'অফারের চাবি থাকা বিক্রয়কর্মী উইজেট পান না।');

        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $this->assertNull($this->widget($stranger), '⛔ চাবি ছাড়াই অফারের উইজেট গেল।');
    }

    /** @return array<string, mixed>|null দরজার উত্তরে `offers`, না থাকলে null */
    private function widget(User $user): ?array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        $widgets = $this->getJson('/api/v1/widgets/summary?keys=offers')->assertOk()->json('widgets');

        return $widgets['offers'] ?? null;
    }

    private function offer(string $tag, PromotionStatus $status = PromotionStatus::ACTIVE, ?Carbon $starts = null, ?Carbon $ends = null,
        bool $signed = true): Promotion
    {
        $this->serial++;
        $offer = new Promotion([
            'name_en' => 'Offer '.$tag,
            'name_bn' => 'অফার '.$tag,
            'starts_on' => $starts ?? Carbon::today()->subDays(10),
            'ends_on' => $ends ?? Carbon::today()->addWeek(),
            'priority' => 0,
        ]);
        $offer->code = sprintf('PROM-WG-%04d', $this->serial);
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = $status;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        if ($signed) {
            // ⓘ সই — [[PromotionLifecycle::approve()]] যা লেখে
            $offer->approved_by = $this->owner->id;
            $offer->approved_at = Carbon::now()->subDay();
        }
        $offer->save();

        return $offer->fresh();
    }
}
