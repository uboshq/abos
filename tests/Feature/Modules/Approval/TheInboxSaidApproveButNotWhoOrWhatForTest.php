<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনুমোদনের ইনবক্স — কার টাকা, কী বাবদ, কোথায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ইনবক্সে ছিল তারিখ, কাজের নাম, কে চেয়েছেন, আর অঙ্ক। ⓘ ভাউচারের তালিকা
 * নিয়ে মালিক যা বলেছিলেন — *"kothy theke eseche kothay joma holo ro kichu
 * bistarito kolam dewar dorkar"* — এখানেও তাই: কার টাকা আর কোথায় যাচ্ছে
 * না দেখে সই করা মানে চোখ বুজে সই করা।
 *
 * ⓘ মাপ রেন্ডার হওয়া সারিতে: একজন ব্যক্তির মূলধনের রসিদ অনুমোদনে গেলে
 * ঐ সারিতেই তাঁর নাম, বিবরণ আর টাকার খাতটা থাকে।
 */
final class TheInboxSaidApproveButNotWhoOrWhatForTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_waiting_row_says_who_what_for_and_where(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($user);

        $person = Person::query()->create([
            'company_id' => $company->id, 'code' => 'INB1', 'name_en' => 'Jamil Investor', 'is_active' => true,
        ]);

        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        /*
         * ⓘ ছকটা আগে — ইনবক্স কেবল সেই সারিগুলো দেখায় যেগুলোর কাজে এই
         * মানুষটা সিদ্ধান্তদাতা ([[ApprovalEngine::pendingQueryFor]])। ছক না
         * থাকলে সারিটা তৈরি হয়েও কারো ইনবক্সে পড়ত না।
         */
        $flow = ApprovalFlow::query()->create([
            'company_id' => $company->id,
            'module' => VoucherApproval::MODULE,
            'action' => Voucher::RECEIPT,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $user->id,
        ]);

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => '5000',
            'to_account_id' => $bank->id,
            'party_type' => 'person',
            'party_id' => $person->id,
            'from_account_id' => $capital->id,
            'narration' => 'INBOX-ROW',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->where('narration', 'INBOX-ROW')->firstOrFail();

        /*
         * ⓘ ছক থাকলে ভাউচারটা নিজেই অনুমোদনে যায়; না গেলে (থ্রেশহোল্ড বা
         * অন্য নিয়মে) সারিটা এখানে বসানো হয়, যাতে মাপটা ঐ নিয়মের উপর
         * নির্ভর না করে — পর্দার কলামই এই পরীক্ষার বিষয়।
         */
        Approval::query()->firstOrCreate([
            'approvable_type' => Voucher::class,
            'approvable_id' => $voucher->id,
        ], [
            'company_id' => $company->id,
            'module' => VoucherApproval::MODULE,
            'action' => Voucher::RECEIPT,
            'amount' => '5000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $html = $this->get(route('approval.inbox.index'))->assertOk()->getContent();

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        $row = collect($rows[1])->first(fn (string $r) => str_contains($r, 'INBOX-ROW'));

        $this->assertNotNull($row, 'অপেক্ষমাণ সারিটা ইনবক্সে নেই।');

        $text = strip_tags($row);

        $this->assertStringContainsString('Jamil Investor', $text, 'পক্ষের নাম সারিতে নেই।');
        $this->assertStringContainsString($bank->name(), $text, 'টাকাটা কোথায় যাচ্ছে, সারিতে নেই।');
    }
}
