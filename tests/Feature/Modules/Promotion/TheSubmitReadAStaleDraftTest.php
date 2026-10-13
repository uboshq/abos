<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\PromotionApprovalChain;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ জমা দেওয়ার সিদ্ধান্ত অফারের **তালার সারি** থেকে — হাতের কপি থেকে নয় (Promotion লাইন-ধরা নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[PromotionApprovalChain::submit()]])।
 *
 * ⓘ হাতের কপি বলছে "খসড়া", অথচ অন্য পর্দায় অফারটা ততক্ষণে সই হয়ে চালু। ⛔ আগে জমা দেওয়া কপির "খসড়া" দেখেই এগোত, আর চালু
 * অফারের অবস্থা "জমা" লিখে দিত — অফারটা বিলে খাটা বন্ধ করত, কেউ কিছু না বলে। ⓘ "অন্য পর্দার লেখা" সরাসরি টেবিলে বসানো, তাই
 * দাবিটা প্রতিবার একই ফল দেয়।
 */
final class TheSubmitReadAStaleDraftTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->offer = new Promotion(['name_en' => 'Stale submit', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $this->offer->code = 'PROM-STALE-02';
        $this->offer->type = PromotionType::PERCENT_DISCOUNT;
        $this->offer->status = PromotionStatus::DRAFT;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();
    }

    public function test_a_copy_that_still_says_draft_does_not_pull_a_live_offer_back_to_submitted(): void
    {
        $stale = Promotion::query()->findOrFail($this->offer->id);
        DB::table('promotions')->where('id', $this->offer->id)->update(['status' => PromotionStatus::ACTIVE->value]);

        try {
            app(PromotionApprovalChain::class)->submit($stale);
            $this->fail('⛔ চালু অফারটা আবার জমা হলো — হাতের কপি "খসড়া" বলছিল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(PromotionStatus::ACTIVE, $this->offer->fresh()->status, '⛔ চালু অফারের অবস্থা বদলে গেল');
    }

    /** ⓘ পাল্টা-দাবি — সত্যিকারের খসড়া জমা হয়, তাই উপরের থামা অবস্থার জন্যই */
    public function test_a_real_draft_is_still_submitted(): void
    {
        $back = app(PromotionApprovalChain::class)->submit(Promotion::query()->findOrFail($this->offer->id));

        $this->assertSame(PromotionStatus::SUBMITTED, $back->status);
        $this->assertSame(PromotionStatus::SUBMITTED, $this->offer->fresh()->status);
    }
}
