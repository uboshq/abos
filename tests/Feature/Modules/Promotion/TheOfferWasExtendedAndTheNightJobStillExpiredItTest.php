<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionExpiry;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মেয়াদ বাড়ানো হলো, তবু নির্ধারিত কাজটা অফারটাকে "মেয়াদ শেষ" লিখল — Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⓘ [[PromotionExpiry::expireLapsed()]] তালিকা পড়ত, তারপর তালিকার কপিতেই `EXPIRED` লিখত। ⛔ মাঝখানে কেউ
 * মেয়াদ বাড়ালে (মালিকের নিয়মে মেয়াদ বাড়ানো যায়) সদ্য বাড়ানো অফারটা থেমে যেত।
 *
 * ⚠️ মাঝখানটা নিশ্চিতভাবে বানানো: তালিকার প্রশ্নটা চলার ঠিক পরে (`DB::listen`) সারির মেয়াদ এক মাস বাড়ানো হয়।
 */
final class TheOfferWasExtendedAndTheNightJobStillExpiredItTest extends TestCase
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

    private function anOfferThatEndedYesterday(): Promotion
    {
        $offer = new Promotion([
            'name_en' => 'Ended yesterday',
            'name_bn' => 'গতকাল শেষ',
            'starts_on' => Carbon::today()->subWeek(),
            'ends_on' => Carbon::today()->subDay(),
        ]);
        $offer->code = 'PROM-EX-0001';
        $offer->type = PromotionType::QUANTITY_SLAB;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = auth()->id();
        $offer->save();

        return $offer->fresh();
    }

    public function test_an_offer_extended_after_the_list_was_read_stays_running(): void
    {
        $offer = $this->anOfferThatEndedYesterday();
        $extended = false;
        $newEnd = Carbon::today()->addMonth()->toDateString();

        DB::listen(function (QueryExecuted $q) use ($offer, $newEnd, &$extended) {
            $sql = strtolower($q->sql);

            if ($extended || ! str_contains($sql, 'from `promotions`') || ! str_contains($sql, 'ends_on')
                || str_contains($sql, 'for update')) {
                return;
            }

            /* ⓘ অন্য ট্যাবে মেয়াদ বাড়ানো — তালিকা পড়ার ঠিক পরে */
            $extended = true;
            DB::table('promotions')->where('id', $offer->id)->update(['ends_on' => $newEnd]);
        });

        app(PromotionExpiry::class)->expireLapsed();

        $this->assertTrue($extended, 'পরীক্ষার ফাঁদটা চলেনি — তালিকার প্রশ্নটা চেনা যায়নি।');
        $this->assertSame(PromotionStatus::ACTIVE, $offer->fresh()->status,
            'মেয়াদ বাড়ানো অফারকে পুরনো তালিকা থেকে "মেয়াদ শেষ" লেখা হয়েছে।');
        $this->assertSame($newEnd, $offer->fresh()->ends_on->toDateString());
    }

    /** ⓘ উল্টো দিক — কেউ না বাড়ালে মেয়াদ পেরোনো অফার আগের মতোই শেষ হয় */
    public function test_an_untouched_lapsed_offer_still_expires(): void
    {
        $offer = $this->anOfferThatEndedYesterday();

        $this->assertGreaterThanOrEqual(1, app(PromotionExpiry::class)->expireLapsed());
        $this->assertSame(PromotionStatus::EXPIRED, $offer->fresh()->status);
    }
}
