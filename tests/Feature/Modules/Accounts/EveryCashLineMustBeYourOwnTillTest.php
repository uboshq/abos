<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গ৩ — নিজের বাক্সের নিয়ম প্রতিটা নগদ সারি দেখে, শুধু প্রথমটা নয় (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে:
 *  · "প্রাপক = নিজের বাক্স, দাতা = সহকর্মীর বাক্স" লিখলে প্রথম নগদ সারি নিজের, তাই নিয়ম খুশি — সহকর্মীর বাক্স খালি
 *    হত সই ছাড়া।
 *  · রসিদে কোনো একটা নগদ সারি থাকলেই সই লাগত না — "নিজের বাক্স থেকে সরবরাহকারীকে" লিখলে আসলে টাকা বেরোয়,
 *    অথচ রসিদ বলে সই ছাড়াই চলত।
 *
 * ⓘ ছাড়টা মালিকের (২১ সেপ্টেম্বর): নিজের বাক্সে নগদ **ঢুকলে** সই লাগে না। টাকা বেরোলে ছাড় নেই।
 */
final class EveryCashLineMustBeYourOwnTillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->colleague = User::query()->whereKeyNot($this->user->id)->firstOrFail();
        $this->actingAs($this->user);

        // ⓘ ছক চালু — নাহলে "সই লাগে" দাবি কিছুই মাপত না
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'receipt', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->user->id]);
    }

    public function test_a_colleagues_till_on_the_second_line_is_refused(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);
        $theirs = $this->cashAccount('CASH-THEIRS');
        $this->till($theirs, $this->colleague->id);

        $voucher = $this->receipt([
            ['account_id' => $mine->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $theirs->id, 'debit' => '0', 'credit' => '500'],
        ]);

        try {
            app(VoucherService::class)->post($voucher);
            $this->fail('⛔ সহকর্মীর বাক্স থেকে নিজের বাক্সে টাকা নেওয়া গেল — দ্বিতীয় নগদ সারি কেউ দেখেনি।');
        } catch (ValidationException $e) {
            /*
             * ⓘ বার্তাটা হুবহু মেলানো — "বাক্সে যথেষ্ট টাকা নেই" বার্তাতেও খাতের নাম থাকে, আর প্রথম চালে ঠিক
             * সেটাতেই দাবিটা সবুজ হয়েছিল, নিয়মটা ছাড়াই।
             */
            $this->assertContains(__('accounts::validation.cash_not_your_till', ['account' => $theirs->label()]), $e->errors()['lines'] ?? [],
                'আটকেছে, কিন্তু অন্য কারণে — দাবিটা বাক্সের নিয়ম মাপছে না।');
        }

        $this->assertFalse($voucher->fresh()->isPosted());
    }

    public function test_cash_leaving_my_own_till_on_a_receipt_still_needs_the_signature(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);

        $voucher = $this->receipt([
            ['account_id' => $this->partyLike()->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $mine->id, 'debit' => '0', 'credit' => '500'],
        ]);

        $this->assertNotNull(app(VoucherApproval::class)->stopping($voucher),
            '⛔ রসিদের নামে নিজের বাক্স থেকে টাকা বেরোল, আর সই লাগল না।');
    }

    public function test_cash_coming_into_my_own_till_still_needs_none(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);

        $voucher = $this->receipt([
            ['account_id' => $mine->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->partyLike()->id, 'debit' => '0', 'credit' => '500'],
        ]);

        $this->assertNull(app(VoucherApproval::class)->stopping($voucher), 'নিজের বাক্সে নগদ ঢুকতে সই চাওয়া হচ্ছে — মালিকের ছাড় হারাল।');
    }

    /** ⓘ পোস্টের পাহারা এটা আটকায়ই; ছাড়টাও যেন একা দাঁড়িয়ে একই কথা বলে — একটা খুললে অন্যটা থাকে */
    public function test_cash_into_a_colleagues_till_gets_no_free_pass(): void
    {
        $theirs = $this->cashAccount('CASH-THEIRS');
        $this->till($theirs, $this->colleague->id);

        $voucher = $this->receipt([
            ['account_id' => $theirs->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->partyLike()->id, 'debit' => '0', 'credit' => '500'],
        ]);

        $this->assertNotNull(app(VoucherApproval::class)->stopping($voucher),
            '⛔ সহকর্মীর বাক্সে নগদ — নিজের বাক্সের ছাড় পেয়ে গেল।');
    }

    public function test_a_receipt_between_two_tills_is_not_a_cash_receipt(): void
    {
        $mine = $this->cashAccount('CASH-MINE');
        $this->till($mine, $this->user->id);
        $other = $this->cashAccount('CASH-OTHER');
        $this->till($other, $this->user->id);

        $voucher = $this->receipt([
            ['account_id' => $mine->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '500'],
        ]);

        $this->assertNotNull(app(VoucherApproval::class)->stopping($voucher),
            '⛔ এক বাক্স থেকে আরেক বাক্সে টাকা — রসিদ বলে সই ছাড়াই।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $lines */
    private function receipt(array $lines): Voucher
    {
        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'গ৩ পরীক্ষা',
        ], $lines)->load('lines.account');
    }

    private function partyLike(): Account
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->firstOrFail();
    }

    private function cashAccount(string $code): Account
    {
        $sibling = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $account = $sibling->replicate(['public_id']);
        $account->forceFill(['code' => $code, 'name_en' => $code, 'name_bn' => $code])->save();

        return $account;
    }

    private function till(Account $account, int $holderId): CashTill
    {
        return CashTill::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'account_id' => $account->id,
            'code' => 'TILL-'.$account->code,
            'name_en' => 'Till '.$account->code,
            'holder_id' => $holderId,
            'is_active' => true,
        ]);
    }
}
