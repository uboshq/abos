<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Promotion;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কুপনের ছাড় কখনো খাতায় পৌঁছাত না — পুরো ERP অডিট ⛔১০, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ কুপন কাটা হত কেবল পাকা বিলে; প্রয়োগ আর অঙ্ক লেখা হত, অথচ কোনো পোস্টিং নয় — অফারের বাজেট খরচ, আয় আর গ্রাহকের পাওনা
 * অটুট। ⭐ এখন টাকার ছাড়ের কুপন পাকা বিলে কাটলে ঐ বিলের বিপরীতে গ্রাহকের ক্রেডিট নোট (Dr দেওয়া ছাড় ৫৩০০ / Cr প্রাপ্য),
 * একই লেনদেনে — নোট না বসলে কুপনও কাটে না।
 */
final class TheCouponDiscountNeverReachedTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->givePermissionTo([Permission::findOrCreate('promotion.coupon', 'web'), Permission::findOrCreate('promotion.apply', 'web')]);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->couponOffer();
    }

    public function test_a_coupon_on_a_posted_bill_books_a_credit_note_against_it(): void
    {
        $bill = $this->bill();
        $this->coupon('BOOK-IT');

        $this->redeem('BOOK-IT', $bill)->assertOk();

        $note = Note::query()->where('against_no', $bill->document_no)->firstOrFail();
        $this->assertSame(Note::CREDIT, $note->direction);
        $this->assertSame(DocumentStatus::CONFIRMED, $note->status, '⛔ ক্রেডিট নোট পাকা হয়নি।');
        $this->assertSame((int) $bill->customer_id, (int) $note->party_id);
        $this->assertSame(0, bccomp((string) $note->total, '20', 4), '⛔ নোট কুপনের অঙ্কে (২০) নয়।');
        $this->assertStringContainsString('BOOK-IT', (string) $note->narration);

        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $discount = StandardChart::find(StandardChart::DISCOUNT_GIVEN)->id;
        $lines = DB::table('ledger_entries')->where('source_type', 'note')->where('source_id', $note->id)->get();
        $this->assertSame(0, bccomp((string) $lines->where('account_id', $receivable)->sum('credit'), '20', 4), '⛔ গ্রাহকের পাওনা কমেনি।');
        $this->assertSame((int) $bill->customer_id, (int) $lines->firstWhere('account_id', $receivable)->party_id);
        $this->assertSame(0, bccomp((string) $lines->where('account_id', $discount)->sum('debit'), '20', 4), '⛔ দেওয়া ছাড়ে (৫৩০০) বসেনি।');
    }

    public function test_when_the_note_cannot_go_in_the_coupon_is_not_spent(): void
    {
        $bill = $this->bill();
        $this->coupon('NO-ROOM');

        // ⓘ বিলের পুরো ২০ আগেই ক্রেডিট — কুপনের নোটের জায়গা নেই
        $notes = app(NoteService::class);
        $notes->confirm($notes->create(['direction' => Note::CREDIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => (int) $bill->customer_id,
            'trx_date' => now()->toDateString(), 'amount' => '20', 'tax_amount' => '0', 'reason' => 'agreed_discount', 'against_no' => $bill->document_no]));

        $this->redeem('NO-ROOM', $bill)->assertStatus(422);

        $this->assertSame(0, (int) PromotionCoupon::query()->where('code', 'NO-ROOM')->value('used_count'), '⛔ নোট বসল না, অথচ কুপন কাটা হলো।');
        $this->assertSame(1, Note::query()->where('against_no', $bill->document_no)->count());
    }

    private function redeem(string $code, SalesInvoice $bill)
    {
        return $this->actingAs($this->owner)->postJson(route('promotion.coupon.redeem'), [
            'code' => $code, 'source_type' => 'sales_invoice', 'source_id' => $bill->id,
            'source_line_id' => $bill->lines()->value('id'), 'product_id' => $this->biscuit()->id, 'qty' => '2', 'value' => '20',
        ]);
    }

    private function bill(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => $this->biscuit()->id, 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }

    private function coupon(string $code): void
    {
        (new PromotionCoupon)->forceFill([
            'promotion_id' => Promotion::query()->where('code', 'PROM-BOOKS-1')->value('id'),
            'code' => $code, 'max_uses' => 5, 'used_count' => 0, 'is_active' => true, 'issued_by' => $this->owner->id,
        ])->save();
    }

    private function biscuit(): Product
    {
        return Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
    }

    private function couponOffer(): void
    {
        $offer = new Promotion(['name_en' => 'Books coupon', 'starts_on' => Carbon::today()->subDay(), 'ends_on' => Carbon::today()->addWeek()]);
        $offer->code = 'PROM-BOOKS-1';
        $offer->type = PromotionType::COUPON;
        $offer->status = PromotionStatus::ACTIVE;
        $offer->combines = PromotionCombines::BEST;
        $offer->created_by = $this->owner->id;
        $offer->save();

        PromotionBenefit::query()->create(['promotion_id' => $offer->id, 'kind' => BenefitKind::AMOUNT, 'amount' => '100']);
    }
}
