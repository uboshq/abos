<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ সইটা ছিল এক লাখ থেকে দুই লাখের জন্য — আর বসানো গেল পঞ্চাশ লাখ।
 *
 * ── ⛔ অডিট §১.২, ২৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * বাকির সীমা বাড়ানোর অনুরোধে নতুন অঙ্কটা থাকত কেবল কারণের **লেখায়**,
 * আর অনুমোদনের ছাপ নেওয়া হত **পুরনো** সীমার উপর। ⚠️ ফলে ১ লাখ → ২
 * লাখের সই পাওয়ার পর যিনি চেয়েছিলেন তিনি ৫০ লাখ লিখে সেভ করতে
 * পারতেন — ছাপ মিলত, কারণ কাগজটা তখনো ১ লাখেই ছিল।
 *
 * ⓘ সাথে আরও তিনটা দরজা: নতুন গ্রাহক ও ইমপোর্ট যেকোনো সীমা নিয়ে ঢুকত,
 * ছক না থাকলে বাড়ানো নীরবে পার হত, আর শূন্য সীমার মানে ছিল সীমাহীন।
 *
 * ⭐ মালিকের নিয়ম: সীমা পরম, কেউ পাশ কাটাতে পারে না, টাকার প্রতিটা
 * সিদ্ধান্তে মানুষের সই।
 */
final class TheSignatureWasForOneLakhAndFiftyWereSetTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'FIFTY', 'name_en' => 'Fifty Lakh Traders']);
        $branch = Branch::create([
            'company_id' => $company->id,
            'code' => 'MAIN',
            'name_en' => 'Main',
            'is_default' => true,
        ]);

        CompanyContext::set($company->id, $branch->id);

        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@fifty.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@fifty.test', 'password' => 'x']);

        $this->actingAs($this->clerk);

        $this->customer = Customer::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CUS-FIFTY',
            'name_en' => 'Fifty Shop',
            'credit_limit' => '100000',
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবিটা ─────────────────────────────────────────────────

    public function test_a_signature_for_two_lakh_does_not_set_fifty_lakh(): void
    {
        $this->aFlow();
        $this->signed('200000');

        try {
            $this->service()->update($this->customer->fresh(), ['credit_limit' => '5000000']);
            $this->fail('দুই লাখের সই দিয়ে পঞ্চাশ লাখ বসে গেছে।');
        } catch (ValidationException) {
            // ⓘ এটাই চাওয়া।
        }

        $this->assertSame('100000.0000', (string) $this->customer->fresh()->credit_limit);
    }

    public function test_one_taka_more_than_signed_is_refused(): void
    {
        $this->aFlow();
        $this->signed('200000');

        $this->expectException(ValidationException::class);

        $this->service()->update($this->customer->fresh(), ['credit_limit' => '200001']);
    }

    public function test_exactly_the_signed_amount_passes(): void
    {
        $this->aFlow();
        $this->signed('200000');

        // ⓘ একই অঙ্ক, অন্য লেখায় — ফর্ম "200000.00" পাঠাতে পারে
        $this->service()->update($this->customer->fresh(), ['credit_limit' => '200000.00']);

        $this->assertSame('200000.0000', (string) $this->customer->fresh()->credit_limit,
            'সই পাওয়া অঙ্কটাও বসছে না — তাহলে সীমা বাড়ানোর কোনো পথই নেই।');
    }

    public function test_the_request_carries_the_proposed_limit_as_its_amount(): void
    {
        $this->aFlow();

        $this->asked('200000');

        $pending = Approval::query()->where('status', Approval::PENDING)->firstOrFail();

        $this->assertSame('200000.0000', (string) $pending->amount,
            'অনুরোধের অঙ্কের ঘরে প্রস্তাবিত সীমা নেই — সইকারী কীসে সই দিচ্ছেন?');
    }

    public function test_a_second_proposal_after_a_signature_asks_again_with_its_own_amount(): void
    {
        $this->aFlow();
        $this->signed('200000');

        $this->asked('5000000');

        $pending = Approval::query()->where('status', Approval::PENDING)->firstOrFail();

        $this->assertSame('5000000.0000', (string) $pending->amount);
    }

    /**
     * ⓘ প্রস্তাবিত সীমাটা ছাপের ভেতরেও — তাই "না" বলা অঙ্কটা অন্য অঙ্কের
     * অনুরোধ আটকে রাখে না। ⚠️ ছাপে প্রস্তাব না থাকলে ৫০ লাখে "না" পাওয়ার
     * পর ২ লাখ চাইলেও পুরনো প্রত্যাখ্যানটাই ফিরত, নতুন অনুরোধ বসত না।
     */
    public function test_a_refused_amount_does_not_block_asking_for_a_different_one(): void
    {
        $this->aFlow();

        $this->asked('5000000');

        $pending = Approval::query()->where('status', Approval::PENDING)->firstOrFail();
        app(ApprovalEngine::class)->reject($pending, $this->manager, 'Too much');

        $this->asked('200000');

        $again = Approval::query()->where('status', Approval::PENDING)->first();

        $this->assertNotNull($again, 'অন্য অঙ্কের অনুরোধ বসেনি — পুরনো "না"-টাই ফিরছে।');
        $this->assertSame('200000.0000', (string) $again->amount);
    }

    public function test_a_threshold_on_the_flow_still_does_not_let_a_small_raise_through(): void
    {
        // ⓘ 653f65c8-এর নিয়ম অটুট: সীমার নিচের বাড়ানোও সই চায়
        $this->aFlow('5000000');

        $this->expectException(ValidationException::class);

        $this->service()->update($this->customer, ['credit_limit' => '100001']);
    }

    // ── ছক নেই → নীরবে পার নয় ─────────────────────────────────────────

    public function test_with_no_flow_the_raise_is_refused_and_says_what_to_do(): void
    {
        try {
            $this->service()->update($this->customer, ['credit_limit' => '200000']);
            $this->fail('ছক ছাড়াই সীমা বেড়ে গেছে, কারো সই ছাড়া।');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('customer::validation.limit_needs_a_flow')],
                $e->errors()['credit_limit'] ?? null,
            );
        }

        $this->assertSame('100000.0000', (string) $this->customer->fresh()->credit_limit);
    }

    public function test_the_no_flow_message_is_bengali(): void
    {
        app()->setLocale('bn');

        $this->assertMatchesRegularExpression('/\p{Bengali}/u', __('customer::validation.limit_needs_a_flow'));
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', __('customer::validation.limit_on_create'));
    }

    public function test_lowering_still_needs_no_flow_and_no_signature(): void
    {
        $this->service()->update($this->customer, ['credit_limit' => '50000']);

        $this->assertSame('50000.0000', (string) $this->customer->fresh()->credit_limit);
    }

    // ── নতুন গ্রাহক ও ইমপোর্ট ──────────────────────────────────────────

    public function test_a_new_customer_cannot_be_born_with_a_limit(): void
    {
        $this->aFlow();

        try {
            $this->service()->create(['code' => 'CUS-RICH', 'name_en' => 'Born Rich', 'credit_limit' => '5000000']);
            $this->fail('নতুন গ্রাহক পঞ্চাশ লাখ সীমা নিয়ে ঢুকে গেছে, সই ছাড়া।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_limit', $e->errors());
        }

        $this->assertFalse(Customer::query()->where('name_en', 'Born Rich')->exists());
    }

    public function test_a_new_customer_with_a_zero_limit_is_created(): void
    {
        $customer = $this->service()->create(['code' => 'CUS-PLAIN', 'name_en' => 'Born Plain', 'credit_limit' => '0']);

        $this->assertSame('0.0000', (string) $customer->fresh()->credit_limit);
    }

    public function test_an_imported_row_with_a_limit_is_refused_on_the_check_screen(): void
    {
        $errors = app(CustomerImporter::class)->check($this->row(['credit_limit' => '5000000']));

        $this->assertContains(__('customer::validation.limit_on_create'), $errors);
    }

    public function test_an_imported_row_with_a_limit_is_refused_when_it_is_placed(): void
    {
        try {
            app(CustomerImporter::class)->import($this->row(['credit_limit' => '5000000']));
            $this->fail('ইমপোর্টে পঞ্চাশ লাখ সীমার গ্রাহক বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_limit', $e->errors());
        }

        $this->assertFalse(Customer::query()->where('name_en', 'Imported Rich')->exists());
    }

    // ── শূন্য মানে শূন্য — মালিকের সুইচে, মালিকের দিনে ──────────────────

    /**
     * ⓘ অডিট §১.২ সুইচটা ডিফল্টে চালু করতে বলেছিল। ⛔ মালিকের সিদ্ধান্ত
     * জেতে ([[ZeroMeansZeroOnTheDayYouSayTest]]-এর মাথায়): ১৪৮ জনের সীমা
     * বসানো নেই, তাই ডিফল্ট **বন্ধ**, আর সুইচটা মালিক নিজের দিনে টেপেন।
     * ⭐ এই দাবি দুইটাই পাহারা দেয়: ডিফল্ট বন্ধ, আর টিপলে শূন্য সত্যিই থামায়।
     */
    public function test_zero_limit_blocks_is_off_by_default_and_blocks_credit_once_turned_on(): void
    {
        $settings = app(SettingsService::class);

        $this->assertFalse($settings->enabled('customer.zero_limit_blocks'),
            'সুইচটা ডিফল্টেই চালু — মালিকের সিদ্ধান্তের আগেই ডিপো থেমে যেত।');

        $this->customer->update(['credit_limit' => '0']);

        $this->assertFalse($this->customer->fresh()->wouldExceedCreditLimit('1'));

        $settings->set('customer.zero_limit_blocks', true);

        $this->assertTrue($this->customer->fresh()->wouldExceedCreditLimit('1'),
            'সুইচ চালু, তবু শূন্য সীমার গ্রাহকের কাছে বাকি যাচ্ছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): CustomerService
    {
        return app(CustomerService::class);
    }

    private function asked(string $limit): void
    {
        try {
            $this->service()->update($this->customer->fresh(), ['credit_limit' => $limit]);
            $this->fail("সীমা {$limit} করা হয়ে গেছে, সই ছাড়া।");
        } catch (ValidationException) {
            // ⓘ অনুরোধটা এখন সারিতে বসেছে।
        }
    }

    private function signed(string $limit): void
    {
        $this->asked($limit);

        $pending = Approval::query()->where('status', Approval::PENDING)->firstOrFail();

        app(ApprovalEngine::class)->approve($pending, $this->manager);
    }

    private function aFlow(?string $threshold = null): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'module' => 'customer',
            'action' => 'credit_limit',
            'threshold_amount' => $threshold,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);

        return $flow;
    }

    /**
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    private function row(array $over): array
    {
        return [
            'code' => 'CUS-IMP',
            'name_en' => 'Imported Rich',
            'name_bn' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'party_type' => '',
            'payment_term' => '',
            'credit_limit' => '',
            'credit_days' => '',
            'opening_balance' => '',
            'opening_date' => '',
            ...$over,
        ];
    }
}
