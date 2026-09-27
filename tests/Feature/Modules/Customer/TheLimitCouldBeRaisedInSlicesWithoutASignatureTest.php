<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সীমাটা টুকরো টুকরো করে বাড়ানো যেত, আর একবারও সই লাগত না।
 *
 * ── ⛔ ফাঁকটা কোথায় ছিল ───────────────────────────────────────────────
 * বাকির সীমা বাড়ালে সই চাওয়া হয়, আর অঙ্ক হিসেবে যেত **বাড়তিটা**
 * ([[CustomerService::update()]] — `amount: bcsub($after, $before)`)।
 *
 * ⓘ আর [[ApprovalFlow::appliesTo()]] সীমার **নিচের** অঙ্কে অনুমোদন চায়
 * না — সেটা ইচ্ছাকৃত, কারণ "৫০ টাকার ডিসকাউন্টে মালিকের সই" কেউ মানে না।
 *
 * ⚠️ দুইটা মিলে ফলটা এই: কোম্পানি "৫০ হাজারের বেশি বাড়ালে সই" বসালে
 * প্রতিবার ২০ হাজার করে তিনবার বাড়ানো যেত — মোট ৬০ হাজার, **একটাও সই
 * ছাড়া**। ⛔ প্রতিটা ধাপ আলাদাভাবে নিয়ম মেনেই হত।
 *
 * ── ⚠️ কেন এটা নীরব ───────────────────────────────────────────────────
 * ⓘ কোথাও কিছু ভাঙে না, কোনো পরীক্ষা লাল হয় না, খাতায় তিনটা বৈধ
 * সম্পাদনা বসে। ⛔ আর ধরা পড়ে বহু পরে — যখন ঐ গ্রাহকের টাকা আর ওঠে না।
 *
 * ── ⭐ মালিকের নিয়ম ───────────────────────────────────────────────────
 * সীমা পরম, আর সীমা বাড়ানোই একমাত্র বৈধ পথ — তাই ঐ পথটা ফাঁকা থাকতে
 * পারে না। *"টাকার বিষয়ে অঙ্ক দেখে সই এড়ানো যাবে না"* — এক পয়সা বাড়লেও
 * সই লাগবে।
 *
 * ── ⓘ এই ফাইলটা কী মাপে, আর কী মাপে না ───────────────────────────────
 * ⭐ মাপে: বাড়ানোয় অঙ্ক যা-ই হোক সই লাগে · কমানোয় লাগে না · ছক না
 *   থাকলে কিছুই বদলায় না · আর সইটা সত্যিই কাজ করে।
 * ⛔ মাপে না: সীমা ছাড়িয়ে বিক্রি আটকানো — ওটা `CreditExposure`-এর কাজ,
 *   আর তার নিজের পাহারা আছে। এখানে কেবল **সীমার ঘরটা কে বদলাতে পারে**।
 */
final class TheLimitCouldBeRaisedInSlicesWithoutASignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'SLICE', 'name_en' => 'Slice Traders']);
        $branch = Branch::create([
            'company_id' => $company->id,
            'code' => 'MAIN',
            'name_en' => 'Main',
            'is_default' => true,
        ]);

        CompanyContext::set($company->id, $branch->id);

        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@slice.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@slice.test', 'password' => 'x']);

        $this->actingAs($this->clerk);

        /*
         * ⓘ সারিটা সোজা মডেল দিয়ে বসানো — পাহারা দেওয়ার জিনিসটা
         * `update()`, তৈরি করা নয়। ⚠️ `create()` দিয়ে বসালে নকল-পাহারা আর
         * পরিবেশক-পাহারার শব্দ এখানে ঢুকত, আর পরীক্ষাটা কী মাপছে সেটা
         * ঢাকা পড়ত।
         */
        $this->customer = Customer::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'CUS-SLICE',
            'name_en' => 'Sliced Shop',
            'credit_limit' => '0',
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

    public function test_a_raise_under_the_threshold_still_needs_a_signature(): void
    {
        /*
         * ⛔ এটাই ফাঁকটা, একটা দাবিতে: ছক বলছে ৫০ হাজারের উপরে সই, আর
         * বাড়ানো হচ্ছে মাত্র ১০ হাজার। ⓘ আগে এটা নীরবে পাশ হয়ে যেত।
         */
        $this->aFlowAbove('50000');

        $this->expectException(ValidationException::class);

        $this->service()->update($this->customer, ['credit_limit' => '10000']);
    }

    public function test_the_limit_cannot_be_walked_up_in_slices(): void
    {
        /*
         * ⭐ ফাঁকটার আসল রূপ — তিনটা ছোট ধাপে ৬০ হাজার।
         *
         * ⚠️ এই দাবিটা প্রথমটার চেয়ে আলাদা, আর দুইটাই দরকার: প্রথমটা
         * বলে একটা ছোট বাড়ানো আটকায়, আর এটা বলে **সীমাটা সত্যিই
         * বাড়েনি** — নাহলে ব্যতিক্রম ছুঁড়েও সংখ্যাটা বসে যেতে পারত।
         */
        $this->aFlowAbove('50000');

        foreach (['20000', '40000', '60000'] as $step) {
            try {
                $this->service()->update($this->customer->fresh(), ['credit_limit' => $step]);
                $this->fail("সীমা {$step} করা হয়ে গেছে, একটাও সই ছাড়া।");
            } catch (ValidationException) {
                // ⓘ এটাই চাওয়া — প্রতিটা ধাপে থামা।
            }
        }

        $this->assertSame('0.0000', (string) $this->customer->fresh()->credit_limit,
            'সীমার ঘরটা বদলে গেছে যদিও প্রতিটা ধাপ আটকেছে — অর্থাৎ '
            .'ব্যতিক্রমটা সংখ্যাটা বসার পরে ছোঁড়া হচ্ছে।');
    }

    // ── ⓘ যেগুলো আগের মতোই থাকতে হবে ─────────────────────────────────

    public function test_the_signature_lets_the_raise_through(): void
    {
        /*
         * ⭐ এটা আগে প্রমাণ করা জরুরি, নাহলে উপরের দাবিগুলো একটা
         * **চিরকাল-আটকে-থাকা** ব্যবস্থাতেও পাস করত — আর সেটা সারানো নয়,
         * ভাঙা।
         */
        $this->aFlowAbove('50000');

        try {
            $this->service()->update($this->customer, ['credit_limit' => '10000']);
        } catch (ValidationException) {
            // ⓘ অনুরোধটা এখন সারিতে বসেছে।
        }

        $asked = Approval::query()->where('status', Approval::PENDING)->firstOrFail();

        app(ApprovalEngine::class)->approve($asked, $this->manager);

        $this->service()->update($this->customer->fresh(), ['credit_limit' => '10000']);

        $this->assertSame('10000.0000', (string) $this->customer->fresh()->credit_limit,
            'সই দেওয়ার পরেও সীমাটা বসেনি — তাহলে সীমা বাড়ানোর কোনো পথই নেই।');
    }

    public function test_a_big_raise_still_needs_a_signature(): void
    {
        // ⓘ আগের আচরণ — সীমার উপরের অঙ্ক আগেও আটকাত, আজও আটকাবে।
        $this->aFlowAbove('50000');

        $this->expectException(ValidationException::class);

        $this->service()->update($this->customer, ['credit_limit' => '90000']);
    }

    public function test_lowering_the_limit_needs_no_signature(): void
    {
        /*
         * ⓘ সীমা কমানো ঝুঁকি কমায়, তাই ওতে সই চাওয়া কেবল কাজ থামাত।
         * ⚠️ এই দাবিটা ছাড়া সারাইটা "সব বদলেই সই" হয়ে যেতে পারত, আর
         * সেটা কেউ টের পেত না।
         */
        $this->aFlowAbove('50000');

        $this->customer->update(['credit_limit' => '80000']);

        $this->service()->update($this->customer->fresh(), ['credit_limit' => '30000']);

        $this->assertSame('30000.0000', (string) $this->customer->fresh()->credit_limit,
            'সীমা কমাতেও সই চাওয়া হচ্ছে।');
    }

    public function test_an_edit_that_does_not_touch_the_limit_needs_no_signature(): void
    {
        /*
         * ⭐ এই দাবিটা একটা **বেঁচে যাওয়া মিউটেন্ট** থেকে এসেছে, ২৬
         * সেপ্টেম্বর ২০২৬ — আর সেটাই এর থাকার কারণ।
         *
         * ⓘ `>` কে `>=` করে দেখা গেল সাতটা পরীক্ষার **একটাও মরেনি**।
         * ⚠️ কারণ `>=` কেবল **সমান** ক্ষেত্রটা বদলায়, আর সেই ক্ষেত্রটার
         * কোনো দাবি ছিল না।
         *
         * ⛔ ফলে এই ভুলটা নীরবে ঢুকে যেতে পারত: গ্রাহকের নাম বা ফোন
         * বদলাতে গেলে ফর্ম সীমার ঘরটাও অপরিবর্তিত অবস্থায় পাঠায়, আর
         * `>=` হলে **প্রতিটা গ্রাহক-সম্পাদনা** সই চাইত। ⓘ সাতটা পরীক্ষা
         * তখনো সবুজ থাকত।
         */
        $this->aFlowAbove('50000');

        $this->customer->update(['credit_limit' => '40000']);

        // ⓘ সীমার ঘরটা পাঠানো হচ্ছে, কিন্তু একই অঙ্কে — ফর্ম যেমন পাঠায়।
        $this->service()->update($this->customer->fresh(), [
            'name_en' => 'Sliced Shop and Sons',
            'credit_limit' => '40000',
        ]);

        $this->assertSame('Sliced Shop and Sons', $this->customer->fresh()->name_en,
            'সীমা একটুও না বদলেও সম্পাদনাটা সই চেয়ে আটকে গেছে — তাহলে '
            .'নাম বা ফোন বদলাতেও ব্যবস্থাপকের সই লাগত।');

        $this->assertSame('40000.0000', (string) $this->customer->fresh()->credit_limit);
    }

    public function test_a_company_with_no_flow_cannot_raise_without_a_signature(): void
    {
        /*
         * ⛔ দাবিটা উল্টেছে — অডিট §১.২, ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ আগে লেখা ছিল *"যে কোম্পানি ছক বসায়নি, তার কাজ থামবে না"* —
         * অর্থাৎ ছক না থাকলে সীমা **সই ছাড়াই** বাড়ত। ⭐ মালিকের নিয়ম:
         * সীমা পরম, টাকার প্রতিটা সিদ্ধান্তে মানুষের সই। তাই এখন থামে,
         * আর বার্তা বলে কোথায় ছক বসাতে হবে।
         *
         * ⓘ পুরো পাহারা: [[TheSignatureWasForOneLakhAndFiftyWereSetTest]]।
         */
        try {
            $this->service()->update($this->customer, ['credit_limit' => '99999']);
            $this->fail('ছক না থাকায় সীমা সই ছাড়াই বেড়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('customer::validation.limit_needs_a_flow')],
                $e->errors()['credit_limit'] ?? null,
            );
        }

        $this->assertSame('0.0000', (string) $this->customer->fresh()->credit_limit);
    }

    public function test_a_flow_with_no_threshold_behaves_the_same(): void
    {
        /*
         * ⓘ সীমা ছাড়া ছকে আগেও সবসময় সই লাগত। ⭐ সারাইয়ের পরে দুইটা
         * ছক **একই আচরণ** করে, আর সেটাই প্রমাণ যে `threshold_amount`
         * ঘরটা এই কাজে আর কিছু বাছাই করছে না।
         */
        $this->aFlowAbove(null);

        $this->expectException(ValidationException::class);

        $this->service()->update($this->customer, ['credit_limit' => '1']);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): CustomerService
    {
        return app(CustomerService::class);
    }

    private function aFlowAbove(?string $threshold): ApprovalFlow
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
}
