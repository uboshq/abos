<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Services\BudgetKeeper;
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
 * ⛔ ছাদ বসানোর সিদ্ধান্ত অফারের **তালার সারির** অবস্থা থেকে — হাতের কপি থেকে নয় (Promotion লাইন-ধরা নিরীক্ষা,
 * ১৪ অক্টোবর ২০২৬; [[BudgetKeeper::set()]])।
 *
 * ⓘ দুইটা পর্দা: একটায় ছাদ বাড়ানো চলছে, হাতের কপি বলছে "খসড়া"; অন্যটায় অফারটা ততক্ষণে জমা (বা বাতিল)। ⛔ আগে সেবা তালা নিত, অথচ
 * সিদ্ধান্ত নিত হাতের কপি দেখে — জমা অফারের ছাদ সই ছাড়াই বাড়ত (এক লাখে সইয়ের ছক, দশ লাখে চলা), আর বাতিল অফারেও ছাদ বসত।
 * ⓘ "অন্য পর্দার লেখা" এখানে সরাসরি টেবিলে বসানো, তাই দাবিটা প্রতিবার একই ফল দেয়।
 */
final class TheCeilingWasRaisedOnAStaleCopyTest extends TestCase
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

        $this->offer = new Promotion(['name_en' => 'Stale ceiling', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $this->offer->code = 'PROM-STALE-01';
        $this->offer->type = PromotionType::PERCENT_DISCOUNT;
        $this->offer->status = PromotionStatus::DRAFT;
        $this->offer->combines = PromotionCombines::BEST;
        $this->offer->created_by = $owner->id;
        $this->offer->save();

        app(BudgetKeeper::class)->set($this->offer, PromotionBudget::TOTAL, '1000');
    }

    public function test_a_raise_on_a_copy_that_still_says_draft_waits_for_the_signature_the_row_now_needs(): void
    {
        $stale = Promotion::query()->findOrFail($this->offer->id);
        DB::table('promotions')->where('id', $this->offer->id)->update(['status' => PromotionStatus::SUBMITTED->value]);

        try {
            app(BudgetKeeper::class)->set($stale, PromotionBudget::TOTAL, '5000');
            $this->fail('⛔ জমা অফারের ছাদ সই ছাড়াই বাড়ল — হাতের কপি "খসড়া" বলছিল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ceiling', $e->errors());
        }

        $this->assertSame(0, bccomp('1000', (string) $this->ceiling(), 4), '⛔ ছাদ বদলে গেল');
    }

    public function test_a_ceiling_is_not_set_on_an_offer_the_row_says_is_cancelled(): void
    {
        $stale = Promotion::query()->findOrFail($this->offer->id);
        DB::table('promotions')->where('id', $this->offer->id)->update(['status' => PromotionStatus::CANCELLED->value]);

        try {
            app(BudgetKeeper::class)->set($stale, PromotionBudget::TOTAL, '800');
            $this->fail('⛔ বাতিল অফারে ছাদ বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ceiling', $e->errors());
        }

        $this->assertSame(0, bccomp('1000', (string) $this->ceiling(), 4));
    }

    /** ⓘ পাল্টা-দাবি — সত্যিকারের খসড়ায় বাড়ানো চলে, তাই উপরের থামা অবস্থার জন্যই */
    public function test_a_raise_on_a_real_draft_still_goes_through(): void
    {
        app(BudgetKeeper::class)->set(Promotion::query()->findOrFail($this->offer->id), PromotionBudget::TOTAL, '5000');

        $this->assertSame(0, bccomp('5000', (string) $this->ceiling(), 4));
    }

    private function ceiling(): ?string
    {
        return PromotionBudget::query()->where('promotion_id', $this->offer->id)->where('kind', PromotionBudget::TOTAL)->value('ceiling');
    }
}
