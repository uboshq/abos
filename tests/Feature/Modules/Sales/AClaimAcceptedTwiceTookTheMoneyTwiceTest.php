<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা জমার দাবি দুইবার গ্রহণ হত, আর টাকাটাও দুইবার বসত।
 *
 * ── ⛔ অডিট §১.৪, ২৭ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * `DepositClaimService::accept()` "অপেক্ষমাণ কি না" দেখত লেনদেনের
 * **বাইরে**, সারিটা না আটকে, আর তারপর একটা **নতুন** আদায় তৈরি করত।
 * ⓘ পোস্টিং ইঞ্জিনের "এক কাগজ একবার" পাহারা তাই ধরতে পারত না — দুইটা
 * আলাদা কাগজ। ⚠️ ৳২,৫০,০০০-এর বিকাশ দাবিতে দুইবার ক্লিক = দুইটা আদায়,
 * আর ডিলারের বকেয়া কমে ৳৫,০০,০০০।
 *
 * ── ⭐ দ্বিতীয় ক্লিকটা কীভাবে মাপা ─────────────────────────────────
 * দুইটা অনুরোধ একসাথে এলে দুইটাই দাবিটা পড়ে "অপেক্ষমাণ" অবস্থায়,
 * কোনোটা শেষ হওয়ার আগেই। ⓘ তাই এখানে দুইটা আলাদা মডেল একই সময়ে
 * পড়া হয়, তারপর একে একে গ্রহণ করা হয় — দ্বিতীয়টার হাতে থাকে পুরনো
 * ছবি, আর সেবাকে সারিটা আটকে **আবার পড়ে** প্রথমটার ফল দেখতে হয়।
 */
final class AClaimAcceptedTwiceTookTheMoneyTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $decider;

    private Account $bank;

    private Account $wallet;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        // ভূমিকাহীন সদস্য — চাবিটা প্রতিটা দাবির ভিতরে দেওয়া হয়
        $this->decider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->decider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-CLAIMTWICE',
            'name_en' => 'Claim twice bkash',
            'name_bn' => 'দুইবারের বিকাশ',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        /*
         * ⓘ বিকাশের দাবি বিকাশের খাতে — ২৯ সেপ্টেম্বর ২০২৬। ⚠️ আগে TrxID-সহ দাবিটাও উপরের
         * ব্যাংকের খাতে মঞ্জুর হত; 060726c5 থেকে পথ আর খাতের ধরন মিলতে হয়
         * ([[MethodFitsAccount]]) — "বিকাশে পাঠালাম" দাবি ব্যাংকে বসে না। নগদের দাবি
         * ব্যাংকে বসতে পারে, তাই ব্যাংকের খাতটা তাদের জন্য থাকল।
         */
        $this->wallet = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1105-CLAIMTWICE',
            'name_en' => 'Claim twice wallet',
            'name_bn' => 'দুইবারের ওয়ালেট',
            'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::MFS,
        ]);

        $this->dealer = Customer::query()->firstOrFail();
    }

    /**
     * ⛔ দুইবার ক্লিক, রেফারেন্সবিহীন দাবি — দ্বিতীয়টা ফেরে, টাকা বসে একবার।
     *
     * ⓘ রেফারেন্স থাকলে আদায়ের "একই স্লিপ দুইবার" পাহারাটা পরপর দুই
     * ক্লিক ধরে ফেলত (একসাথে এলে নয় — ওটা সারি আটকায় না)। ⚠️ রেফারেন্স
     * ছাড়া নগদ জমায় সেই পাহারাও নেই, তাই এটাই খোলা দরজা।
     */
    public function test_a_double_click_without_a_reference_accepts_once(): void
    {
        $this->assertDoubleClickLandsOnce(null);
    }

    /**
     * ⛔ বিকাশের TrxID-সহ দুইবার ক্লিক — ফেরানোর কারণটা "সিদ্ধান্ত হয়ে
     * গেছে", স্লিপের কথা নয়। ⓘ দাবির অবস্থাটাই প্রথম পাহারা।
     */
    public function test_a_double_click_with_a_bkash_reference_names_the_real_reason(): void
    {
        $this->assertDoubleClickLandsOnce('BKASH-TWICE-8H2K');
    }

    private function assertDoubleClickLandsOnce(?string $reference): void
    {
        app()->setLocale('bn');
        $this->actingAs($this->decider);

        $claim = $this->pendingClaim('250000', $reference);
        $dueBefore = $this->dealer->outstanding();

        // দুইটা অনুরোধ, দুইটাই দাবিটা পড়েছে "অপেক্ষমাণ" অবস্থায়
        $firstClick = DepositClaim::query()->findOrFail($claim->id);
        $secondClick = DepositClaim::query()->findOrFail($claim->id);

        $service = app(DepositClaimService::class);
        $into = $reference === null ? $this->bank : $this->wallet;

        $service->accept($firstClick, $into->id);

        $refusal = null;
        try {
            $service->accept($secondClick, $into->id);
        } catch (ValidationException $e) {
            $refusal = $e;
        }

        $this->assertNotNull($refusal,
            '⛔ দ্বিতীয় ক্লিকও গ্রহণ হয়ে গেছে — একই দাবি থেকে দুইটা আদায়।');

        $message = $refusal->errors()['status'][0] ?? null;
        $this->assertSame(__('sales::portal.already_decided'), $message,
            '⛔ ফিরেছে, কিন্তু "সিদ্ধান্ত হয়ে গেছে" বলে নয়: '.json_encode($refusal->errors(), JSON_UNESCAPED_UNICODE));
        $this->assertNotSame('sales::portal.already_decided', $message, '⛔ বার্তার চাবিটা অনূদিত নয়।');
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', (string) $message,
            '⛔ ফেরানোর বার্তাটা বাংলায় নয়।');

        $this->assertSame(1, Collection::query()->where('customer_id', $this->dealer->id)->count(),
            '⛔ একটা দাবি থেকে একাধিক আদায় তৈরি হয়েছে।');

        $this->assertSame(
            bcsub($dueBefore, '250000', 4),
            $this->dealer->fresh()->outstanding(),
            '⛔ ডিলারের বকেয়া একবারের বেশি কমেছে (বা একবারও কমেনি)।',
        );

        $this->assertSame(
            Collection::query()->where('customer_id', $this->dealer->id)->value('id'),
            $claim->fresh()->collection_id,
            '⛔ দাবির সাথে জোড়া আদায়টা প্রথমটা নয়।',
        );
    }

    /**
     * ⛔ একই দরজায় পরপর দুইটা POST — দ্বিতীয়টা ফর্মের ভুল হয়ে ফেরে।
     */
    public function test_the_second_post_to_the_accept_door_is_refused(): void
    {
        $this->decider->givePermissionTo('sales.claim.decide');
        $claim = $this->pendingClaim('250000');
        $dueBefore = $this->dealer->outstanding();

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, Collection::query()->where('customer_id', $this->dealer->id)->count());
        $this->assertSame(bcsub($dueBefore, '250000', 4), $this->dealer->fresh()->outstanding());
    }

    /**
     * ⭐ একই লোক — চাবি ছাড়া ৪০৩ আর কিছু বদলায় না, চাবি নিয়ে গ্রহণ হয়।
     */
    public function test_the_same_user_is_refused_without_the_key_and_allowed_with_it(): void
    {
        $claim = $this->pendingClaim('250000');

        $this->assertFalse($this->decider->can('sales.claim.decide'));

        $this->actingAs($this->decider)
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertForbidden();

        $this->assertSame(DepositClaim::PENDING, $claim->fresh()->status);
        $this->assertSame(0, Collection::query()->count());

        $this->decider->givePermissionTo('sales.claim.decide');

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status);
        $this->assertSame(1, Collection::query()->count());
    }

    /**
     * ⛔ নাকচ হওয়া দাবি গ্রহণ হয় না — পুরনো ছবি হাতে থাকলেও।
     */
    public function test_a_rejected_claim_cannot_be_accepted(): void
    {
        $this->actingAs($this->decider);

        $claim = $this->pendingClaim('250000');
        $dueBefore = $this->dealer->outstanding();

        // গ্রহণের পাতাটা খোলা হয়েছিল নাকচের আগে
        $stale = DepositClaim::query()->findOrFail($claim->id);

        app(DepositClaimService::class)->reject($claim, 'ব্যাংকে পাইনি');

        $refused = false;
        try {
            app(DepositClaimService::class)->accept($stale, $this->bank->id);
        } catch (ValidationException) {
            $refused = true;
        }

        $this->assertTrue($refused, '⛔ নাকচ হওয়া দাবিটা পুরনো ছবি দিয়ে গ্রহণ হয়ে গেছে।');

        // দরজা দিয়েও
        $this->decider->givePermissionTo('sales.claim.decide');
        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasErrors('status');

        $this->assertSame(DepositClaim::REJECTED, $claim->fresh()->status);
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame($dueBefore, $this->dealer->fresh()->outstanding());
    }

    /**
     * ⛔ উল্টো দৌড়: গ্রহণের পরে পুরনো ছবি দিয়ে নাকচ — টাকা খাতায় থেকে
     * যেত, অথচ দাবিটা বলত "নাকচ"। গ্রাহক আবার দাবি তুলতেন।
     */
    public function test_an_accepted_claim_cannot_be_rejected_from_a_stale_page(): void
    {
        $this->actingAs($this->decider);

        $claim = $this->pendingClaim('250000');
        $stale = DepositClaim::query()->findOrFail($claim->id);

        app(DepositClaimService::class)->accept($claim, $this->bank->id);

        $refused = false;
        try {
            app(DepositClaimService::class)->reject($stale, 'ব্যাংকে পাইনি');
        } catch (ValidationException) {
            $refused = true;
        }

        $this->assertTrue($refused, '⛔ গৃহীত দাবিটা পুরনো ছবি দিয়ে নাকচ হয়ে গেছে।');
        $this->assertSame(DepositClaim::ACCEPTED, $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->collection_id);
    }

    private function pendingClaim(string $amount, ?string $reference = null): DepositClaim
    {
        return app(DepositClaimService::class)->raise($this->dealer, [
            'claimed_on' => now()->subDay()->toDateString(),
            'amount' => $amount,
            'method' => $reference === null ? DepositClaim::CASH : DepositClaim::MFS,
            'reference' => $reference,
            'bank_account_id' => $this->bank->id,
        ]);
    }
}
