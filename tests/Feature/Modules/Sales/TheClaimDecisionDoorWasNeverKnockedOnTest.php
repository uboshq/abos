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
use Tests\TestCase;

/**
 * গ্রাহকের জমার দাবি মঞ্জুর বা নাকচ করার দরজায় কেউ কোনোদিন কড়া নাড়েনি।
 *
 * ── ⛔ কেন এই ফাইলটা, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * বিক্রয়ের চেকলিস্ট বানাতে গিয়ে ১০৪টা রুট একে একে খুঁজে দেখা গেছে
 * **৩৭টা দরজা কোনো পরীক্ষা নাম ধরে ডাকে না** (`de052cdc`)। ⓘ এই দুইটা
 * তার মধ্যে সবচেয়ে জরুরিদের একটা, কারণ মঞ্জুর করলে **গ্রাহকের খাতায়
 * টাকা বসে**।
 *
 * ⚠️ কাজটা মাপা ছিল, দরজাটা নয়: `DepositClaimService` সেবা হিসেবে ডাকা
 * হত, কিন্তু `sales.claim.accept` ও `.reject` রুট দুইটা কেউ ছুঁত না।
 *
 * ⛔ তাই যা ধরা পড়ত না: ঐ দরজায় অনুমতির চাবি ভুল বা অনুপস্থিত, রুটটা
 * ভুল মেথডে বাঁধা, বা নিয়ামকের যাচাই বাইপাস।
 *
 * ── ⭐ নকশাটা কেন এরকম: একই লোক, আগে চাবি ছাড়া, পরে চাবি নিয়ে ────────
 * ⚠️ দুইজন আলাদা ব্যবহারকারী নিলে ৪০৩-টা **অন্য কারণেও** আসতে পারত —
 * মডিউলের সুইচ বন্ধ, কোম্পানির সদস্যপদ নেই, রুটের নাম ভুল। ⓘ তখন
 * দাবিটা সবুজ থাকত অথচ চাবিটা আদৌ কিছু পাহারা দিচ্ছে কি না, তার
 * কোনো প্রমাণ থাকত না।
 *
 * ⭐ একই লোককে চাবি দিলেই দরজা খুলে যায় — এটাই প্রমাণ করে তালাটা ঠিক
 * ঐ চাবিরই ([[my-own-claims-never-catch-the-open-door]])।
 */
final class TheClaimDecisionDoorWasNeverKnockedOnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $decider;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        /*
         * ⛔ চাবিহীন লোকটা **হিসাবরক্ষক**, বিক্রয়কর্মী নয় — আর সেটা
         * মেপে জানা, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ প্রথমে বিক্রয়কর্মীকে নিয়েছিলাম, ধরে নিয়ে তাঁর চাবি নেই।
         * ⛔ ভুল: `salesman` **ভূমিকাতেই** `sales.claim.view` ও
         * `sales.claim.decide` দুইটাই আছে। ⓘ ধরা পড়েছে কেবল এই কারণে
         * যে ভিত্তিটা মন্তব্য নয়, **দাবি** হিসেবে লেখা ছিল
         * ([[same-user-key-off-then-on]])।
         *
         * ⭐ আর এই মাপটা নিজেই একটা খবর: গ্রাহকের ব্যাংক-জমা সত্যিই
         * এসেছে কি না, সেই সিদ্ধান্ত হিসাবরক্ষক নিতে পারেন না, অথচ
         * বিক্রয়কর্মী পারেন। ⓘ সেটা ভূমিকার নকশার প্রশ্ন, মালিকের কাছে
         * পাঠানো হয়েছে — এই ফাইলের কাজ নয়।
         */
        /*
         * ⭐ চাবিহীন লোকটা **ভূমিকাহীন**, কোনো ডেমো-ভূমিকা নয়।
         *
         * ── ⛔ কেন, ২৬ সেপ্টেম্বর ২০২৬ ────────────────────────────
         * ⚠️ প্রথমে বিক্রয়কর্মী ধরেছিলাম — ভুল, তাঁর চাবি ছিল। তারপর
         * হিসাবরক্ষক — ⛔ সেটাও ভুল হয়ে যাবে, কারণ মালিকের সিদ্ধান্তে
         * `sales.claim.decide` এখন **হিসাবরক্ষকের ভূমিকাতেই** যাচ্ছে।
         *
         * ⓘ অর্থাৎ যেকোনো ডেমো-ভূমিকার উপর দাঁড়ালে এই দাবিগুলো
         * ভূমিকার ছাঁচ বদলানোমাত্র **ভুল কারণে** লাল হত।
         *
         * ⭐ তাই কারো ভূমিকা ধার করা হয় না: একজন নতুন ব্যবহারকারী,
         * কোম্পানির সদস্য কিন্তু ভূমিকাহীন, আর চাবিটা প্রতিটা দাবির
         * ভিতরেই দেওয়া হয় ([[same-user-key-off-then-on]])।
         */
        $this->decider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->decider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-CLAIMDOOR',
            'name_en' => 'Claim door bank',
            'name_bn' => 'দাবির ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
    }

    /**
     * ⛔ চাবি ছাড়া মঞ্জুর করা যায় না — আর কিছুই বদলায় না।
     *
     * ⚠️ দুইটা আলাদা কথা, আর দ্বিতীয়টাই বেশি জরুরি: ৪০৩ ফেরত দিয়েও
     * যদি ভিতরে সারিটা বদলে যেত, তবে তালাটা কেবল পর্দার, খাতার নয়।
     */
    public function test_a_claim_cannot_be_accepted_without_the_key(): void
    {
        $claim = $this->pendingClaim();

        $this->assertFalse($this->decider->can('sales.claim.decide'),
            'ⓘ এই দাবির ভিত্তি: ভূমিকাহীন ব্যবহারকারীর সিদ্ধান্তের চাবি নেই। '
            .'⛔ থাকলে নিচের ৪০৩ কিছুই প্রমাণ করত না।');

        $this->actingAs($this->decider)
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertForbidden();

        $this->assertSame(DepositClaim::PENDING, $claim->fresh()->status,
            '⛔ ৪০৩ ফিরেছে, তবু দাবিটার অবস্থা বদলে গেছে।');

        $this->assertSame(0, Collection::query()->count(),
            '⛔ ৪০৩ ফিরেছে, তবু একটা আদায়ের সারি তৈরি হয়ে গেছে — '
            .'অর্থাৎ গ্রাহকের খাতায় টাকা বসেছে।');
    }

    /**
     * ⭐ চাবিটাই দরজা খোলে — আর খুলে সত্যিই কাজটা করে।
     *
     * ⓘ একই ব্যবহারকারী, কেবল চাবিটা যোগ করা। ⚠️ সফল হওয়া মানে ৪০৩-টা
     * মডিউলের সুইচ বা সদস্যপদের জন্য ছিল না — ঠিক এই চাবিটার জন্যই ছিল।
     */
    public function test_the_key_is_what_opens_the_accept_door(): void
    {
        $claim = $this->pendingClaim();

        $this->decider->givePermissionTo('sales.claim.decide');

        /*
         * ⚠️ `assertRedirect()` একা যথেষ্ট নয় — ২৬ সেপ্টেম্বর শেখা।
         *
         * ⛔ একটা **যাচাই-ব্যর্থতাও ৩০২**, তাই ফর্মের ভুল সাফল্যের মতো
         * দেখায়। ⓘ "কেবল ২০০ নয়" ফাঁদটারই ৩০২-সংস্করণ।
         */
        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.accept', $claim), ['account_id' => $this->bank->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $claim = $claim->fresh();

        $this->assertSame(DepositClaim::ACCEPTED, $claim->status,
            '⛔ দরজা খুলেছে, কিন্তু দাবিটা মঞ্জুর হয়নি — অর্থাৎ ২০০ এসেছে, কাজ হয়নি।');

        /*
         * ⭐ আসল কাজটা এখানে: মঞ্জুর করা মানে একটা **আদায়ের সারি**
         * তৈরি হওয়া আর দাবির সাথে জোড়া লাগা ([[DepositClaimService::accept]])।
         *
         * ⓘ ভাউচারটা আলাদা করে মাপা হয়নি, কারণ বড় অঙ্কে আদায় অনুমোদনের
         * জন্য অপেক্ষা করতে পারে — তখন সারিটা থাকে, দাখিলাটা পরে বসে।
         * ⚠️ ঐ শাখাটা মাপা এই ফাইলের কাজ নয়, আর মাপতে গেলে দাবিটা
         * অনুমোদনের সীমার উপর নির্ভরশীল হয়ে পড়ত।
         */
        $this->assertNotNull($claim->collection_id,
            '⛔ দাবিটা মঞ্জুর, অথচ কোনো আদায়ের সারি তৈরি হয়নি — টাকাটা কোথাও বসেনি।');

        $this->assertSame(1, Collection::query()->count());

        $this->assertSame($this->decider->id, $claim->decided_by,
            '⛔ কে সিদ্ধান্ত নিলেন সেটা লেখা হয়নি।');
    }

    /** ⛔ নাকচও চাবি ছাড়া হয় না, আর কারণটাও বসে না। */
    public function test_a_claim_cannot_be_rejected_without_the_key(): void
    {
        $claim = $this->pendingClaim();

        $this->actingAs($this->decider)
            ->post(route('sales.claim.reject', $claim), ['decision_reason' => 'ব্যাংকে পাইনি'])
            ->assertForbidden();

        $claim = $claim->fresh();

        $this->assertSame(DepositClaim::PENDING, $claim->status,
            '⛔ ৪০৩ ফিরেছে, তবু দাবিটা নাকচ হয়ে গেছে।');

        $this->assertNull($claim->decision_reason,
            '⛔ ৪০৩ ফিরেছে, তবু কারণটা সারিতে লেখা হয়ে গেছে।');
    }

    /** ⭐ চাবি নিয়ে নাকচ — অবস্থা আর কারণ দুইটাই বসে। */
    public function test_the_key_is_what_opens_the_reject_door(): void
    {
        $claim = $this->pendingClaim();

        $this->decider->givePermissionTo('sales.claim.decide');

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.reject', $claim), ['decision_reason' => 'ব্যাংকে পাইনি'])
            ->assertRedirect();

        $claim = $claim->fresh();

        $this->assertSame(DepositClaim::REJECTED, $claim->status);

        $this->assertSame('ব্যাংকে পাইনি', $claim->decision_reason,
            '⛔ নাকচ হয়েছে, কিন্তু কারণটা লেখা হয়নি — গ্রাহক জানবেন না কেন।');
    }

    /**
     * ⚠️ কারণ ছাড়া নাকচ করা যায় না — নিয়ামকের যাচাইটাও দরজা ধরেই মাপা।
     *
     * ⓘ এটা সেবার দাবি নয়: `DepositClaimService::reject()`-এর নিজের
     * পাহারা আছে, কিন্তু **নিয়ামকের `required` নিয়মটা** কেবল HTTP পথেই
     * চলে। ⛔ ওটা মুছে গেলে সেবার পাহারাটা তখনো ধরত, তবে ব্যবহারকারী
     * একটা ৫০০ দেখতেন, ফর্মের ভুল-বার্তা নয়।
     */
    public function test_a_rejection_needs_a_reason(): void
    {
        $claim = $this->pendingClaim();

        $this->decider->givePermissionTo('sales.claim.decide');

        $this->actingAs($this->decider->fresh())
            ->post(route('sales.claim.reject', $claim), [])
            ->assertSessionHasErrors('decision_reason');

        $this->assertSame(DepositClaim::PENDING, $claim->fresh()->status);
    }

    /** একটা অপেক্ষমাণ দাবি — গ্রাহকের তোলা। */
    private function pendingClaim(): DepositClaim
    {
        return app(DepositClaimService::class)->raise(
            Customer::query()->firstOrFail(),
            [
                'claimed_on' => now()->subDay()->toDateString(),
                'amount' => '5000',
                'method' => DepositClaim::BANK,
                'reference' => 'TRX-CLAIMDOOR',
                'bank_account_id' => $this->bank->id,
            ],
        );
    }
}
