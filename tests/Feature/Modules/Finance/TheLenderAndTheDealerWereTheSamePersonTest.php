<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ধারদাতা আর ডিলার একই মানুষ ছিলেন, আর খাতা দুইজন ভাবত।
 *
 * ── ⭐ মানচিত্র §১৪খ: "পক্ষের সাথে জোড়া" ─────────────────────────────
 * ঘরটা (`partner_type`/`partner_id`) অনেক দিন ধরেই সারিতে ছিল, আর নতুন
 * হাতধারের ফর্মে বাছাও যেত। ⛔ কিন্তু **পুরনো** সারির জোড়া লাগানোর কোনো
 * পথ ছিল না।
 *
 * ⚠️ আর দরকারটা পড়ে ঠিক পরে: টাকা দেওয়ার দিন কেউ ভাবেন না "ইনি কি
 * আমার ডিলারও"। ⓘ প্রশ্নটা ওঠে মাস শেষে, যখন দুই খাতায় একই নাম দুইবার
 * দেখা যায় আর কেউ বলতে পারেন না টাকাটা কোন দিকে।
 */
final class TheLenderAndTheDealerWereTheSamePersonTest extends TestCase
{
    use RefreshDatabase;

    private HandLoanAccount $loan;

    private Supplier $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->dealer = Supplier::query()->firstOrFail();

        $person = Person::query()->create([
            'company_id' => $company->id,
            'code' => 'P-TEST-1',
            'name_en' => 'Karim Mia',
            'name_bn' => 'করিম মিয়া',
            'is_active' => true,
        ]);

        $this->loan = app(HandLoanService::class)->open([
            'person_id' => $person->id,
            'money_account_id' => Account::query()->money()->postable()->active()->firstOrFail()->id,
            'note' => 'হাতে ধার',
        ]);
    }

    /**
     * ⭐ চালু একটা হাতধার তাঁর সরবরাহকারীর সাথে জোড়া লাগে।
     */
    public function test_a_running_loan_can_be_tied_to_the_dealer(): void
    {
        $this->assertNull($this->loan->partner_type, 'শুরুতেই জোড়া বসে আছে — পরীক্ষাটা কিছু প্রমাণ করবে না।');

        $this->post(route('finance.hand_loan.link', $this->loan), [
            'party' => 'supplier:'.$this->dealer->id,
        ])->assertRedirect();

        $fresh = $this->loan->fresh();

        $this->assertSame('supplier', $fresh->partner_type);
        $this->assertSame((int) $this->dealer->id, (int) $fresh->partner_id);
    }

    /**
     * ⭐ ভুল জোড়া খুলেও ফেলা যায়।
     *
     * ⚠️ এটা না থাকলে মানুষ জোড়া লাগাতেই ভয় পেতেন — আর ভয় পাওয়া মানে
     * ঘরটা চিরকাল খালি থাকা।
     */
    public function test_a_wrong_link_can_be_undone(): void
    {
        $this->post(route('finance.hand_loan.link', $this->loan), [
            'party' => 'supplier:'.$this->dealer->id,
        ])->assertRedirect();

        $this->post(route('finance.hand_loan.link', $this->loan), ['party' => ''])->assertRedirect();

        $fresh = $this->loan->fresh();

        $this->assertNull($fresh->partner_type, 'জোড়াটা খোলেনি।');
        $this->assertNull($fresh->partner_id);
    }

    /**
     * ⛔ বানানো পক্ষ বসানো যায় না।
     *
     * ⓘ ঘরটা একটাই লেখা ("supplier:12"), তাই ছাঁদটা না মাপলে যেকোনো
     * কিছু ঢুকে যেত — আর তখন সারিটা এমন একজনকে দেখাত যিনি নেই।
     */
    public function test_a_made_up_party_is_refused(): void
    {
        $this->post(route('finance.hand_loan.link', $this->loan), [
            'party' => 'employee:9',
        ])->assertSessionHasErrors('party');

        $this->assertNull($this->loan->fresh()->partner_type);
    }

    /**
     * ⭐ আর পর্দায় ঘরটা সত্যিই আছে — নাহলে পথটা থেকেও নেই।
     */
    public function test_the_screen_offers_the_box(): void
    {
        $this->get(route('finance.hand_loan.show', $this->loan))
            ->assertOk()
            ->assertSee('name="party"', escape: false);
    }
}
