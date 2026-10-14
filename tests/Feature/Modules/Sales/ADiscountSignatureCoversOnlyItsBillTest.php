<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\SignedButNotPosted;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ছাড়ের সই কেবল সেই বিলের — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[SalesInvoiceService::assertDiscountApproved()]])।
 *
 * ⛔ আগে কেবল অঙ্ক মেলানো হত: ৫০ বস্তার বিলে ১,০০০ টাকা ছাড়ের সই নিয়ে খসড়াটা ২ বস্তা বা অন্য গ্রাহকের করে একই ১,০০০ ছাড়ে
 * নিশ্চিত করা যেত — মালিক যে বিক্রিতে ছাড় দেননি, সেখানে তাঁর সই।
 *
 * দাবি:
 *  - সইয়ের পরে পরিমাণ বদলালে (ছাড় একই) — নতুন সই, আর নতুন অনুরোধ পুরনোটার কথা বলে;
 *  - সইয়ের পরে গ্রাহক বদলালে — নতুন সই;
 *  - কিছু না বদলালে সেই সই-ই চলে, দ্বিতীয় অনুরোধ নয় — আর বিলটা "সই হয়েছে, পোস্ট হয়নি" তালিকায় থাকে;
 *  - ছাপ ছাড়া পুরনো সই আগের মতো কেবল অঙ্কে।
 */
final class ADiscountSignatureCoversOnlyItsBillTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->signer = User::factory()->create(['current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);

        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $flow->update(['threshold_amount' => '0', 'is_active' => true]);
        $flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false]);

        Customer::query()->orderBy('id')->take(2)->get()->each(fn (Customer $c) => $c->forceFill(['credit_limit' => '10000000'])->save());
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_fewer_goods_under_the_same_discount_ask_for_a_new_signature(): void
    {
        [$bill, $signed] = $this->signedBill();

        $bill = app(SalesInvoiceService::class)->update($bill->fresh(), $this->header(), [$this->line('2', '1000')]);

        $this->refused($bill, '⛔ ৫০ বস্তার বিলে দেওয়া ছাড়ের সই ২ বস্তার বিলে খাটল।');
        $asked = $this->latest($bill);
        $this->assertSame(Approval::PENDING, $asked->status, '⛔ বদলানো বিলের জন্য নতুন সই চাওয়া হয়নি।');
        $this->assertNotSame((int) $signed->id, (int) $asked->id);
        $this->assertSame((int) $signed->id, (int) ($asked->payload['supersedes'] ?? 0), '⛔ নতুন অনুরোধ বলে না কোন সইয়ের জায়গা নিল।');

        app(ApprovalEngine::class)->approve($asked, $this->signer, 'ঠিক আছে');
        $this->assertSame(DocumentStatus::CONFIRMED, app(SalesInvoiceService::class)->confirm($bill->fresh())->status,
            '⛔ নতুন সইয়ের পরেও বিল নিশ্চিত হলো না।');
    }

    public function test_another_customer_under_the_same_discount_asks_for_a_new_signature(): void
    {
        [$bill] = $this->signedBill();

        $other = Customer::query()->orderBy('id')->skip(1)->value('id');
        $bill = app(SalesInvoiceService::class)->update($bill->fresh(), [...$this->header(), 'customer_id' => $other], [$this->line('50', '1000')]);

        $this->refused($bill, '⛔ এক গ্রাহকের বিলে দেওয়া ছাড়ের সই অন্য গ্রাহকের বিলে খাটল।');
        $this->assertSame(Approval::PENDING, $this->latest($bill)->status);
    }

    public function test_an_unchanged_bill_confirms_on_its_own_signature_and_is_listed_until_then(): void
    {
        [$bill, $signed] = $this->signedBill();

        Cache::flush();
        $this->assertArrayHasKey((int) $signed->id, app(SignedButNotPosted::class)->papers($this->signer),
            '⛔ সই-হওয়া, বদলহীন বিল "সই হয়েছে, পোস্ট হয়নি" তালিকায় নেই — ছাড়ের সইয়ের ছাপ ভুল ছাপের সাথে মেলানো হচ্ছে।');

        $this->assertSame(DocumentStatus::CONFIRMED, app(SalesInvoiceService::class)->confirm($bill->fresh())->status,
            '⛔ বিল এক চুলও বদলায়নি, তবু নতুন সই চাইল।');
        $this->assertSame(1, Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $bill->id)
            ->where('action', 'discount')->count(), '⛔ বদলহীন বিলের জন্য দ্বিতীয় অনুরোধ লেখা হলো।');
        $this->assertSame(Approval::APPROVED, $signed->fresh()->status);
    }

    public function test_a_signature_from_before_fingerprints_keeps_the_amount_rule(): void
    {
        [$bill, $signed] = $this->signedBill();
        DB::table('approvals')->where('id', $signed->id)->update(['state_hash' => null]);

        $bill = app(SalesInvoiceService::class)->update($bill->fresh(), $this->header(), [$this->line('2', '1000')]);

        $this->assertSame(DocumentStatus::CONFIRMED, app(SalesInvoiceService::class)->confirm($bill->fresh())->status,
            '⛔ ছাপ ছাড়া পুরনো সই হঠাৎ বাতিল — লাইভে আগে থেকে সই-হওয়া বিলগুলো ডিপ্লয়ের দিন আটকে যেত।');
    }

    /** @return array{0: SalesInvoice, 1: Approval} ৫০ বস্তা, ১,০০০ ছাড় — সই হয়েছে, খসড়া */
    private function signedBill(): array
    {
        $bill = app(SalesInvoiceService::class)->create($this->header(), [$this->line('50', '1000')]);
        $this->refused($bill, 'প্রস্তুতি — সই চাওয়ার কথা');

        $asked = $this->latest($bill);
        $this->assertSame(Approval::PENDING, $asked->status);
        app(ApprovalEngine::class)->approve($asked, $this->signer, 'ঠিক আছে');

        return [$bill->fresh(), $asked->fresh()];
    }

    private function refused(SalesInvoice $bill, string $why): string
    {
        try {
            app(SalesInvoiceService::class)->confirm($bill->fresh());
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors(), $why.' — অন্য কারণে আটকাল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return (string) $e->errors()['discount'][0];
        }
    }

    private function latest(SalesInvoice $bill): Approval
    {
        return Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $bill->id)
            ->where('action', 'discount')->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString()];
    }

    /** @return array<string, string|int> */
    private function line(string $qty, string $discount): array
    {
        return ['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => $qty, 'rate' => '1000', 'discount' => $discount];
    }
}
