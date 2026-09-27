<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * নিশ্চিত ক্রয় বিলে "নিশ্চিত করুন" বোতাম — লাইভ QA, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী দেখা গেল ─────────────────────────────────────────────────────
 * নিশ্চিত (খাতায় বসা) বিলের পাতায় তখনো "সম্পাদনা" আর "নিশ্চিত করুন"
 * দুইটা বোতামই দেখা যাচ্ছিল। ⚠️ ভয়টা: একই দেনা সরবরাহকারীর নামে
 * দ্বিতীয়বার বসা।
 *
 * ── ⓘ নকশাটা যা পাওয়া গেল ─────────────────────────────────────────────
 * মালিকের সিদ্ধান্তে (১৮ সেপ্টেম্বর) **কেবল super admin** নিশ্চিত বিল
 * বদলাতে পারেন — [[PurchaseBillPolicy::update]] — আর তখন সেবা পুরনো
 * দাখিলা উল্টে নতুনটা বসায় ([[PurchaseBillService::updatePosted]])।
 * অর্থাৎ super admin-এর "সম্পাদনা" ইচ্ছাকৃত পথ, আর সেটা থাকছে।
 *
 * ⛔ কিন্তু "নিশ্চিত করুন" বোতামটা একই `@can('update')`-এর ভেতরে বসা
 * ছিল, তাই যিনি সম্পাদনা পারেন তিনি নিশ্চিত বিলেও "নিশ্চিত করুন"
 * দেখতেন — যে কাজ কেবল খসড়ায় মানে রাখে, আর সেবা যেটা ফিরিয়ে দেয়।
 *
 * ⭐ তাই এখানে তিনটা দাবি, প্রতিটা একই মানুষ দিয়ে দুইবার (খসড়া আর
 * নিশ্চিত) — যাতে বোতাম সবার জন্য লুকিয়ে সবুজ হওয়া না যায়।
 */
final class AConfirmedBillStillOfferedToBeEditedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // মালিক — super admin; নিশ্চিত বিল উল্টে-বসিয়ে বদলানোর অধিকার কেবল তাঁর
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        // রোল ছাড়া কর্মী — ঠিক বিলের তিনটা চাবি, আর কিছু নয়
        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);

        foreach (['purchase.bill.view', 'purchase.bill.create', 'purchase.bill.cancel'] as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $this->clerk->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** কর্মী: খসড়ায় দুই বোতাম, নিশ্চিতে একটাও না — আর দরজাও বন্ধ। */
    public function test_the_clerk_loses_edit_and_confirm_once_the_bill_is_posted(): void
    {
        $bill = $this->draftBill();

        $this->assertSeesDoors($this->clerk, $bill, edit: true, confirm: true, when: 'কর্মী, খসড়া');

        $this->confirm($bill);

        $this->assertSeesDoors($this->clerk, $bill, edit: false, confirm: false, when: 'কর্মী, নিশ্চিত');

        $before = $this->snapshot($bill);

        $this->asUser($this->clerk)->get(route('purchase.bill.edit', $bill))
            ->assertForbidden();

        $this->asUser($this->clerk)->put(route('purchase.bill.update', $bill), $this->payload('99'))
            ->assertForbidden();

        $this->asUser($this->clerk)->post(route('purchase.bill.confirm', $bill))
            ->assertSessionHasErrors('status');

        $this->assertSame($before, $this->snapshot($bill),
            '⛔ নিশ্চিত বিলে কর্মীর সম্পাদনা/নিশ্চিত — খাতা, মজুদ বা বিল বদলে গেছে।');
    }

    /**
     * মালিক: নিশ্চিত বিলে "সম্পাদনা" থাকে (উল্টে-বসানোর ইচ্ছাকৃত পথ),
     * কিন্তু "নিশ্চিত করুন" আর নয়।
     */
    public function test_the_owner_keeps_the_repost_edit_but_never_confirm_on_a_posted_bill(): void
    {
        $bill = $this->draftBill();

        $this->assertSeesDoors($this->owner, $bill, edit: true, confirm: true, when: 'মালিক, খসড়া');

        $this->confirm($bill);

        $this->assertSeesDoors($this->owner, $bill, edit: true, confirm: false, when: 'মালিক, নিশ্চিত');

        $before = $this->snapshot($bill);

        $this->asUser($this->owner)->post(route('purchase.bill.confirm', $bill))
            ->assertSessionHasErrors('status');

        $this->assertSame($before, $this->snapshot($bill),
            '⛔ নিশ্চিত বিল আবার "নিশ্চিত" হলো — দেনা দ্বিতীয়বার বসেছে।');

        // ⓘ উল্টে-বসানোর পথ তখনো খোলা, আর তাতে দেনা দ্বিগুণ হয় না
        $this->asUser($this->owner)->get(route('purchase.bill.edit', $bill))->assertOk();

        $this->asUser($this->owner)->put(route('purchase.bill.update', $bill), $this->payload('30'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('purchase.bill.show', $bill));

        $bill->refresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->status);
        $this->assertSame(0, bccomp($this->ledgerNet($bill), (string) $bill->total, 4),
            "⛔ উল্টে-বসানোর পরে খাতায় বিলের নিট {$this->ledgerNet($bill)}, অথচ বিলের মোট {$bill->total} — দেনা দুইবার বসেছে।");
    }

    private function draftBill(): PurchaseBill
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        $payload = $this->payload('48');

        return app(PurchaseBillService::class)->create($payload, $payload['lines']);
    }

    private function confirm(PurchaseBill $bill): void
    {
        $this->actingAs($this->owner);

        app(PurchaseBillService::class)->confirm($bill->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'বিলটা নিশ্চিতই হয়নি — দাবিটা কিছু মাপছে না।');
    }

    private function assertSeesDoors(User $user, PurchaseBill $bill, bool $edit, bool $confirm, string $when): void
    {
        $response = $this->asUser($user)->get(route('purchase.bill.show', $bill->fresh()))->assertOk();

        $html = (string) $response->getContent();

        foreach ([
            'সম্পাদনা' => [route('purchase.bill.edit', $bill), $edit],
            'নিশ্চিত করুন' => [route('purchase.bill.confirm', $bill), $confirm],
        ] as $label => [$url, $expected]) {
            $expected
                ? $this->assertStringContainsString($url, $html, "{$when}: \"{$label}\" বোতামটা থাকার কথা, অথচ নেই — বোতাম সবার জন্য লুকানো হয়েছে।")
                : $this->assertStringNotContainsString($url, $html, "⛔ {$when}: \"{$label}\" বোতামটা থাকার কথা নয়, অথচ পাতায় বসে আছে।");
        }
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }

    /** @return array<string, mixed> */
    private function payload(string $qty): array
    {
        return [
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'QA-EDIT-1',
            'lines' => [['product_id' => Product::query()->value('id'), 'qty' => $qty, 'rate' => '100']],
        ];
    }

    /**
     * খাতা, মজুদ আর বিল — সারির সংখ্যা **আর** অঙ্ক দুইটাই।
     *
     * @return array<string, string|int>
     */
    private function snapshot(PurchaseBill $bill): array
    {
        $bill = $bill->fresh(['lines']);

        return [
            'ledger_rows' => LedgerEntry::query()->count(),
            'ledger_debit' => (string) LedgerEntry::query()->sum('debit'),
            'ledger_credit' => (string) LedgerEntry::query()->sum('credit'),
            'stock_rows' => StockMovement::query()->count(),
            'bill_status' => $bill->status,
            'bill_total' => (string) $bill->total,
            'bill_lines' => $bill->lines->count(),
            'bill_qty' => (string) $bill->lines->sum('qty'),
        ];
    }

    private function ledgerNet(PurchaseBill $bill): string
    {
        $type = PurchaseBill::drillSourceType();

        $posted = (string) LedgerEntry::query()->where('source_type', $type)->where('source_id', $bill->id)->sum('debit');
        $reversed = (string) LedgerEntry::query()->where('source_type', $type.':reversal')->where('source_id', $bill->id)->sum('credit');

        return bcsub($posted, $reversed, 4);
    }
}
