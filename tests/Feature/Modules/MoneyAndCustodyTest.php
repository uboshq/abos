<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * টাকা ও হেফাজত — কোন টাকা কার কাছে আছে।
 *
 * ── প্রশ্নটা কেন এক পর্দায় দরকার ────────────────────────────────────
 * সংখ্যাগুলো আগে থেকেই ছিল, কিন্তু ছড়ানো: টিলের তালিকায় ব্যালেন্স,
 * হিসাবের ছকে ব্যাংক, ড্যাশবোর্ডে যোগফল। কেউ চলে গেলে বা রাতে সিন্দুক
 * মেলাতে গেলে হিসাবরক্ষককে তিন জায়গা ঘুরে কাগজে যোগ করতে হত।
 */
class MoneyAndCustodyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->till = app(CashTillService::class)->ensurePrimaryTill();

        Account::query()->whereKey($this->till->account_id)->update([
            'opening_balance' => '20000',
            'opening_date' => '2026-07-01',
        ]);
    }

    private function screen()
    {
        return $this->get(route('accounts.custody'));
    }

    public function test_the_screen_opens_and_lists_every_place_money_sits(): void
    {
        $this->till->forceFill(['holder_id' => $this->owner->id])->save();

        $this->screen()
            ->assertOk()
            ->assertSee($this->till->name())
            ->assertSee($this->owner->name)
            ->assertSee(__('accounts::custody.on_the_road'));
    }

    /**
     * হেফাজতকারীহীন নগদ কাউন্টার আলাদা করে বলা হয়।
     *
     * ── কেন এটাই এই পর্দার সবচেয়ে কাজের ঘর ──────────────────────────
     * কারও নাম বসানো না থাকলে টাকাটা কার্যত অভিভাবকহীন: ঘাটতি হলে কেউ
     * দায়ী নয়, আর কেউ দায়ী না হলে ঘাটতি নিয়ে কেউ প্রশ্নও করে না।
     * খালি ঘর রেখে দিলে ওটা চোখেই পড়ত না।
     */
    public function test_a_cash_counter_with_nobody_responsible_is_flagged(): void
    {
        $this->till->forceFill(['holder_id' => null])->save();

        $this->screen()->assertOk()->assertSee(__('accounts::custody.nobody'));
    }

    public function test_a_counter_with_a_custodian_is_not_flagged(): void
    {
        $this->till->forceFill(['holder_id' => $this->owner->id])->save();

        $this->screen()->assertOk()->assertDontSee(__('accounts::custody.nobody'));
    }

    /**
     * ব্যাংকের খালি ঘরটা সতর্কতা নয়।
     *
     * ব্যাংকের টাকা কারও ড্রয়ারে থাকে না; ওটা ব্যাংকের কাছে। ওখানে
     * "কেউ দায়িত্বে নেই" লিখলে প্রতিটা ব্যাংক সারি মিথ্যা সতর্কতা
     * দেখাত, আর তখন আসল সতর্কতাটাও কেউ পড়ত না।
     */
    public function test_a_bank_account_is_not_flagged_for_having_no_custodian(): void
    {
        $this->till->forceFill(['holder_id' => $this->owner->id])->save();

        Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-CITY',
            'name_en' => 'City Bank',
            'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK_AND_MFS)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->screen()
            ->assertOk()
            ->assertSee('সিটি ব্যাংক')
            ->assertDontSee(__('accounts::custody.nobody'));
    }

    // ── পথের টাকা ───────────────────────────────────────────────────

    /**
     * পথের টাকা নিজের সারিতে বসে, কারও নামের পাশে নয়।
     *
     * টাকাটা ড্রয়ার ছেড়েছে, কেউ এখনো নেয়নি — অর্থাৎ এই মুহূর্তে ওটা
     * কারও হেফাজতে নেই। কারও নামের পাশে দেখালে ওই মানুষটাকে এমন টাকার
     * জন্য দায়ী দেখাত যেটা তাঁর কাছে নেই।
     */
    public function test_money_on_the_road_is_in_nobodys_hands(): void
    {
        $safe = app(CashTillService::class)->create([
            'code' => 'SAFE',
            'name_en' => 'Office Safe',
            'name_bn' => 'অফিসের সিন্দুক',
        ]);

        app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->till->id,
            'to_till_id' => $safe->id,
            'amount' => '12000',
            'trx_date' => now()->toDateString(),
        ]);

        $this->screen()
            ->assertOk()
            ->assertSee(__('accounts::custody.nobody_holds_it'))
            // যোগফলটা পথের সারিতে
            ->assertSee('12,000.00')
            // আর কে কাকে পাঠিয়েছেন সেটাও, কারণ যোগফল দেখে কেউ খুঁজতে যেতে পারে না
            ->assertSee(__('accounts::custody.on_the_road_detail'));
    }

    /**
     * আমার টিলে আসা হস্তান্তরটা উপরে ইনবক্সে ওঠে।
     *
     * না দেখালে হাতে হাতে দেওয়া টাকা দিনের পর দিন অগৃহীত পড়ে থাকে, আর
     * তখন দুই ধাপের হস্তান্তর নিছক একটা বাড়তি বোতাম।
     */
    public function test_a_handover_coming_to_me_shows_up_as_my_work(): void
    {
        $mine = app(CashTillService::class)->create([
            'code' => 'MINE',
            'name_en' => 'My Counter',
            'name_bn' => 'আমার কাউন্টার',
            'holder_id' => $this->owner->id,
        ]);

        app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->till->id,
            'to_till_id' => $mine->id,
            'amount' => '5000',
            'trx_date' => now()->toDateString(),
        ]);

        $this->screen()->assertOk()->assertSee(
            trans_choice('accounts::custody.waiting_for_you', 1, ['count' => 1])
        );
    }

    /** অন্যের টিলে যাওয়া হস্তান্তর আমার ইনবক্সে ওঠে না। */
    public function test_somebody_elses_handover_is_not_my_work(): void
    {
        $theirs = app(CashTillService::class)->create([
            'code' => 'THEIRS',
            'name_en' => 'Their Counter',
            'name_bn' => 'তাদের কাউন্টার',
        ]);

        app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->till->id,
            'to_till_id' => $theirs->id,
            'amount' => '5000',
            'trx_date' => now()->toDateString(),
        ]);

        $this->screen()->assertOk()->assertDontSee(
            trans_choice('accounts::custody.waiting_for_you', 1, ['count' => 1])
        );
    }

    // ── অনুমতি ──────────────────────────────────────────────────────

    public function test_someone_without_the_till_permission_cannot_open_it(): void
    {
        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.view', 'web'));

        $this->actingAs($reader)->get(route('accounts.custody'))->assertForbidden();
    }

    public function test_a_guest_gets_the_login_screen(): void
    {
        auth()->logout();

        $this->get(route('accounts.custody'))->assertRedirect(route('login'));
    }
}
