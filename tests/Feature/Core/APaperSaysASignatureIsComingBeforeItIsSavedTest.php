<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\PaperStates;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⭐ সংরক্ষণের আগেই চিপ বলে "অনুমোদনের অপেক্ষায় হবে" — যখন এই ধরনের কাগজে এই কোম্পানিতে সই লাগে (রসিদ-পরিশোধ মিল-তালিকা #৫৪,
 * নকশা পাতা ৪; ২৫ অক্টোবর ২০২৬)। ⓘ রায় [[ApprovalEngine::requires()]]-এর — পোস্টের একই প্রশ্ন ([[PaperStates::beforeSave()]])।
 *
 * দাবি:
 *  ১. ছক চালু আর সীমা পেরোলে খসড়ার চিপ `will_wait` — "অনুমোদনের অপেক্ষায় হবে", কমলা, ড্যাশ রেখা (আগাম কথা, এখনো সত্য নয়)।
 *  ২. ছক নেই বা সীমার নিচে — আগের মতোই "খসড়া"; রায় সবসময় ইঞ্জিনের রায়ের সমান।
 *  ৩. `approval` কেবল খসড়াকে ছোঁয় — পোস্ট হওয়া কাগজ পোস্ট হওয়াই থাকে।
 *  ৪. আগের দশটা অবস্থা অবিকল — লেখা, আইকন, রং আর রেখা।
 */
final class APaperSaysASignatureIsComingBeforeItIsSavedTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ আগের দশ অবস্থা, এই বদলের আগে যেমন ছিল — লেখা · আইকন · ভূমিকা · রেখা */
    private const BEFORE = [
        'draft' => ['খসড়া', 'edit', 'neutral', 'solid'],
        'waiting' => ['অনুমোদনের অপেক্ষায়', 'clock', 'critical', 'solid'],
        'sent_back' => ['সংশোধনে ফেরত', 'undo', 'critical', 'solid'],
        'posting' => ['খাতায় উঠছে', 'edit', 'information', 'solid'],
        'posted' => ['পোস্ট হয়েছে', 'check_circle', 'positive', 'solid'],
        'cancelled' => ['বাতিল', 'do_not_disturb_on', 'negative', 'solid'],
        'not_posted' => ['পোস্ট হয়নি', 'alert-triangle', 'negative', 'dashed'],
        'rejected' => ['প্রত্যাখ্যাত', 'cancel', 'negative', 'solid'],
        'approved' => ['অনুমোদিত', 'verified', 'information', 'solid'],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ১ আর ২ — রায় ইঞ্জিনের, চিপ তার কথা বলে */
    public function test_the_chip_says_will_wait_exactly_when_the_engine_asks_for_a_signature(): void
    {
        $this->assertSame('draft', $this->stateOf(['purchase', 'payment', '10000', 'Payment']), '⛔ সইয়ের ছক নেই, অথচ চিপ "অনুমোদনের অপেক্ষায় হবে" বলে');

        $this->flow('5000');

        $this->assertTrue(app(ApprovalEngine::class)->requires('purchase', 'payment', '10000', 'Payment'), 'প্রস্তুতিটাই ভুল — ছক ৳10,000-এ সই চায় না');
        $this->assertSame('will_wait', $this->stateOf(['purchase', 'payment', '10000', 'Payment']),
            '⛔ ছক সই চায়, অথচ সংরক্ষণের আগে চিপ "অনুমোদনের অপেক্ষায় হবে" বলে না');
        $this->assertSame('draft', $this->stateOf(['purchase', 'payment', '1000', 'Payment']),
            '⛔ সীমার নিচের টাকায়ও চিপ "অনুমোদনের অপেক্ষায় হবে" — পোস্টে সই লাগবে না');
        $this->assertSame('will_wait', $this->stateOf(['purchase', 'payment', null, 'Payment']),
            '⛔ টাকা লেখার আগে চিপ সাবধানের দিকে বলে না — সীমাওয়ালা ছকে পোস্ট সই চাইতে পারে');

        foreach (['10000', '1000', null] as $amount) {
            $this->assertSame(
                app(ApprovalEngine::class)->requires('purchase', 'payment', $amount, 'Payment') ? 'will_wait' : 'draft',
                PaperStates::beforeSave('purchase', 'payment', $amount, 'Payment'),
                '⛔ চিপ আর পোস্ট দুই রায় দিল — সই লাগে কি না',
            );
        }
    }

    /** ১ — দেখতে: লেখা, কমলা, ড্যাশ */
    public function test_the_new_chip_reads_will_await_signature_in_amber_with_a_dashed_line(): void
    {
        $this->flow(null);

        $html = Blade::render('<x-ui.paper-state state="draft" :approval="$a" />', ['a' => ['purchase', 'payment', null, 'Payment']]);

        $this->assertMatchesRegularExpression('/<span aria-hidden="true">অনুমোদনের অপেক্ষায় হবে<\/span>/u', $html, '⛔ চিপে "অনুমোদনের অপেক্ষায় হবে" লেখা নেই');
        $this->assertStringContainsString('border-style: dashed', $html, '⛔ আগাম অবস্থার চিপ ড্যাশ রেখায় নয় — "এখনই অপেক্ষায়" চিপের সাথে গুলিয়ে যায়');
        $this->assertStringContainsString('--ps-fg: #B44F00', $html, '⛔ চিপ সইয়ের কমলা রঙে নয়');
        $this->assertStringContainsString('অবস্থা: খসড়া, পাঠালে অনুমোদনের অপেক্ষায় যাবে', $html, '⛔ পর্দা-পাঠক আগাম অবস্থার মানে শোনে না');
    }

    /** ৩ — কেবল খসড়া */
    public function test_the_approval_prop_touches_only_a_draft(): void
    {
        $this->flow(null);

        $html = Blade::render('<x-ui.paper-state state="posted" :approval="$a" />', ['a' => ['purchase', 'payment', null, 'Payment']]);

        $this->assertStringContainsString('data-paper-state="posted"', $html, '⛔ পোস্ট হওয়া কাগজের চিপ "অনুমোদনের অপেক্ষায় হবে" হয়ে গেল');
        $this->assertStringContainsString('data-paper-state="draft"', Blade::render('<x-ui.paper-state state="draft" />'),
            '⛔ `approval` না দিলেও খসড়ার চিপ বদলাল');
    }

    /** ৪ — আগের অবস্থা অবিকল */
    public function test_the_existing_states_are_unchanged(): void
    {
        foreach (self::BEFORE as $state => [$label, $icon, $role, $border]) {
            $chip = PaperStates::chip($state);

            $this->assertNotNull($chip, "⛔ {$state} আর আঁকা হয় না");
            $this->assertSame([$label, $icon, $role, $border], [$chip['label'], $chip['icon'], $chip['role'], $chip['border_style']],
                "⛔ আগের অবস্থা {$state} বদলে গেল");
        }

        $this->assertNull(PaperStates::chip('unsent'), '⛔ কেবল-অ্যাপের "unsent" ওয়েবে আঁকা হল');
    }

    /** একটা চালু ছক — ক্রয়ের পরিশোধে, এক ধাপ */
    private function flow(?string $threshold): void
    {
        $flow = ApprovalFlow::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'payment', 'document_type' => ''],
            ['threshold_amount' => $threshold, 'is_active' => true],
        );
        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->delete();
        $flow->conditions()->delete();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => auth()->id()]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    /** @param  array<int, mixed>  $approval */
    private function stateOf(array $approval): string
    {
        preg_match('/data-paper-state="([a-z_]+)"/', Blade::render('<x-ui.paper-state state="draft" :approval="$a" />', ['a' => $approval]), $m);

        return $m[1] ?? '';
    }
}
