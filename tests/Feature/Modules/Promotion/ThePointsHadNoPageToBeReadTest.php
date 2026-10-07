<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Promotion\Models\LoyaltyEntry;
use App\Modules\Promotion\Support\LoyaltyKind;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * পয়েন্ট জমত, খরচ হত, ফুরোত — কিন্তু পড়ার কোনো পাতা ছিল না (স্পেক §৭-ঠ, §১৩)।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[LoyaltyLedger]] খাতা লিখত, অথচ ক্রেতা *"আমার কত পয়েন্ট?"* জিজ্ঞেস
 * করলে কাউন্টারের লোকের দেখার জায়গা ছিল না। ⛔ আর পাতা বানানোর সহজ পথ
 * — `SUM(points)` দেখানো — রাতের কাজ না চললে ফুরোনো পয়েন্টও দেখাত।
 *
 * ⭐ সংখ্যাগুলো ইচ্ছা করে অদ্ভুত (`4817.5`, `263`, যোগফল `5080.5`) — ⓘ যাতে
 * পাতার অন্য কোথাও কাকতালীয়ভাবে না মেলে, আর `assertDontSee` সত্যিই কিছু মাপে।
 *
 * ⓘ খাতার সারি সরাসরি লেখা — [[LoyaltyEntry]] কেবল বদলানো ও মোছা আটকায়,
 * নতুন সারি নয়। ⚠️ দিনগুলো এমন বাছা যে কোনো মুহূর্তেই খরচযোগ্য ব্যালান্স
 * `5080.5` হয় না: পুরনো `263` ফুরিয়েছে নতুন `4817.5` আসার **আগেই**।
 */
final class ThePointsHadNoPageToBeReadTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE = '4817.5';

    private const EXPIRED = '263';

    /** ⓘ দুইটার যোগফল — পাতায় কখনো দেখা যাওয়ার কথা নয় */
    private const NAIVE_SUM = '5080.5';

    private Company $company;

    private User $owner;

    private Customer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->buyer = Customer::query()->orderBy('id')->firstOrFail();

        /* ⚠️ পুরনো লট: ২০ দিন আগে এল, ১০ দিন আগে ফুরোল — রাতের কাজ কখনো চলেনি, মেয়াদ-শেষের সারি নেই */
        $this->writeEntry($this->buyer, self::EXPIRED, Carbon::now()->subDays(20), Carbon::today()->subDays(10), 9102);

        /* ⓘ নতুন লট: ৫ দিন আগে, কখনো ফুরোয় না */
        $this->writeEntry($this->buyer, self::LIVE, Carbon::now()->subDays(5), null, 9101);
    }

    private function writeEntry(Customer $customer, string $points, Carbon $at, ?Carbon $expiresOn, int $billId): LoyaltyEntry
    {
        return LoyaltyEntry::query()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'kind' => LoyaltyKind::EARN,
            'points' => $points,
            'expires_on' => $expiresOn?->toDateString(),
            'source_type' => 'sales_invoice',
            'source_id' => $billId,
            'occurred_at' => $at,
        ]);
    }

    /** ⓘ এই কোম্পানির একজন সাধারণ কর্মী — কেবল `promotion.view`, পয়েন্টের চাবি নয় */
    private function counterClerk(): User
    {
        $clerk = User::factory()->create(['is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('promotion.view', 'web'));

        return $clerk;
    }

    private function grantLoyaltyKey(User $clerk): User
    {
        $clerk->givePermissionTo(Permission::findOrCreate('promotion.loyalty', 'web'));

        return $clerk->fresh();
    }

    /**
     * ⭐ একই মানুষ: `loyalty` চাবি ছাড়া দুই দরজাই বন্ধ, চাবি পেলে খোলে আর ব্যালান্স দেখায়।
     *
     * ⚠️ দুইজন আলাদা মানুষ হলে ৪০৩-টা কোম্পানির সদস্যপদ বা অন্য কিছুর জন্যও
     * হতে পারত — একই মানুষে কেবল চাবিটাই বদলায়।
     */
    public function test_the_same_clerk_needs_the_loyalty_key(): void
    {
        $clerk = $this->counterClerk();

        $this->actingAs($clerk)->get(route('promotion.loyalty.index'))->assertForbidden();
        $this->actingAs($clerk)->get(route('promotion.loyalty.show', $this->buyer))->assertForbidden();

        $clerk = $this->grantLoyaltyKey($clerk);

        $this->actingAs($clerk)->get(route('promotion.loyalty.index'))
            ->assertOk()
            ->assertSee($this->buyer->code)
            ->assertSee(self::LIVE);

        $this->actingAs($clerk)->get(route('promotion.loyalty.show', $this->buyer))
            ->assertOk()
            ->assertSee(self::LIVE);
    }

    /**
     * ⛔ ফুরোনো পয়েন্ট দেখানো ব্যালান্সে নেই — রাতের কাজ না চললেও।
     *
     * ⚠️ বিপজ্জনক ইনপুট: দিন পেরোনো লট, অথচ মেয়াদ-শেষের সারি লেখা হয়নি।
     * ⓘ পাতা `SUM(points)` দেখালে দুই পাতাতেই `5080.5` উঠত — আর কাউন্টারের
     * লোক ক্রেতাকে এমন পয়েন্টের কথা দিতেন যা খরচের দরজা ফিরিয়ে দিত।
     */
    public function test_expired_points_are_not_in_the_shown_balance(): void
    {
        $this->assertDatabaseMissing('promotion_loyalty_entries', [
            'customer_id' => $this->buyer->id,
            'kind' => LoyaltyKind::EXPIRE->value,
        ]);

        $clerk = $this->grantLoyaltyKey($this->counterClerk());

        $this->actingAs($clerk)->get(route('promotion.loyalty.index'))
            ->assertOk()
            ->assertSee(self::LIVE)
            ->assertDontSee(self::NAIVE_SUM);

        $this->actingAs($clerk)->get(route('promotion.loyalty.show', $this->buyer))
            ->assertOk()
            ->assertSee(self::LIVE)
            ->assertDontSee(self::NAIVE_SUM);
    }

    /**
     * ⛔ অন্য কোম্পানির ক্রেতার খাতা ৪০৪ — চাবি থাকলেও; আর তালিকাতেও তিনি নেই।
     *
     * ⓘ ৪০৪, ৪০৩ নয়: *"এই নম্বরে কেউ আছেন"* কথাটাও অন্য কোম্পানির কাছে যায় না।
     */
    public function test_another_companys_customer_is_not_found(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $stranger = CompanyContext::forCompany($beta->id, function () {
            $customer = app(CustomerService::class)->create([
                'name_en' => 'Far Mart Buyer',
                'name_bn' => 'দূরের ক্রেতা',
                'credit_limit' => 0,
                'credit_days' => 0,
            ]);

            $this->writeEntry($customer, '7171.25', Carbon::now()->subDay(), null, 9201);

            return $customer;
        });

        $this->assertNotSame($this->company->id, $stranger->company_id,
            'ক্রেতাটা অন্য কোম্পানিতে বসেনি — তাহলে ৪০৪ দাবিটা কিছুই মাপে না।');

        $clerk = $this->grantLoyaltyKey($this->counterClerk());

        $this->actingAs($clerk)->get(route('promotion.loyalty.show', $stranger->id))->assertNotFound();

        /* ⭐ পাল্টা-দাবি: একই মানুষ, একই চাবি — নিজের কোম্পানির ক্রেতা খোলে */
        $this->actingAs($clerk)->get(route('promotion.loyalty.show', $this->buyer))->assertOk();

        $this->actingAs($clerk)->get(route('promotion.loyalty.index'))
            ->assertOk()
            ->assertDontSee('7171.25');
    }
}
