<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ বাক্সের খোলার জের খাতায় ওঠে ঠিক যেমন সই হয়েছে — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ আগে: "করিমের বাক্সে ২৫,০০০" সইয়ের অপেক্ষায় থাকতে বাক্সটা রহিমের নামে করা যেত (জের তখনো শূন্য, তাই হস্তান্তরের পাহারা
 * ধরত না); আর শেষ সই খাতের **এখনকার** খোলার অঙ্ক পোস্ট করত — সইয়ের পরে অঙ্কটা বদলালেও।
 */
final class ATillOpeningIsPostedOnlyAsSignedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::TILL_OPENING, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);
    }

    public function test_the_holder_cannot_change_while_the_opening_waits(): void
    {
        $till = $this->open('25000');
        $other = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        try {
            app(CashTillService::class)->update($till, ['holder_id' => $other->id]);
            $this->fail('⛔ খোলার জের সইয়ের অপেক্ষায়, অথচ বাক্সটা আরেকজনের নামে হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.till_opening_awaits_signature'), $e->errors()['holder_id'][0] ?? null,
                json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertNotSame((int) $other->id, (int) $till->fresh()->holder_id);
    }

    public function test_an_opening_changed_after_the_request_is_not_posted_on_the_old_signature(): void
    {
        $till = $this->open('25000');

        // ⓘ কোনো পথে খাতের খোলার অঙ্ক বদলে গেছে
        DB::table('accounts')->where('id', $till->account_id)->update(['opening_balance' => '90000']);

        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($till, AccountsSignature::TILL_OPENING), $this->owner);

        $this->assertSame(0, bccomp($till->fresh()->balance(), '0', 4), '⛔ ২৫,০০০-এর সই ৯০,০০০ খাতায় তুলল।');
        $this->assertTrue(DB::table('notifications')->where('type', SignedPaperFinisher::NOTICE)->exists(),
            '⛔ সই-হওয়া জের খাতায় উঠল না, অথচ কেউ খবর পেলেন না।');
    }

    private function open(string $opening): CashTill
    {
        return app(CashTillService::class)->create([
            'code' => 'SGT-'.random_int(100, 999),
            'name_en' => 'Signed Till',
            'name_bn' => 'সই-বাক্স',
            'opening_balance' => $opening,
            'opening_date' => now()->toDateString(),
        ])->fresh(['account']);
    }
}
