<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteAccounts;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ লাইভে UB (২৩ অক্টোবর ২০২৬, fe-র তদন্ত): মান ছকের ১১৫১ "ড্যামেজ দাবি" আসার আগেই ঐ কোডে "Star Line Accounts" ব্যাংক খোলা ছিল।
 *
 * <p>ছক বসানো থাকা কোড ছোঁয় না, তাই নোটের পর্দা কোড ধরে খুঁজে ব্যাংকটাকেই ড্যামেজ দাবি হিসেবে দেখাত — CN-0017 থেকে CN-0020,
 * ৩–৪ অক্টোবর, ৳৫৩,৮৬৮ ব্যাংকে Dr। এখন কোডের খাত মান ছকের ধরন না ধরলে, বা টাকার খাত টাকার কোডে না বসলে, নোটের তালিকায়ও
 * নেই, ডিফল্টও নয়, আর ফর্ম এড়িয়ে পাঠালেও নোট বসে না ([[StandardChart::holdsItsMeaning()]])।
 */
final class ABankOnTheDamageClaimCodeIsNoDamageClaimTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->firstOrFail();
    }

    /** ⭐ UB-র ছবি হুবহু: ১১৫১-তে ব্যাংক — গ্রাহকের ক্রেডিট নোট আর সরবরাহকারীর ডেবিট নোট, কোনোটার তালিকায় ব্যাংক নেই */
    public function test_a_bank_on_the_damage_claim_code_is_never_offered(): void
    {
        $bank = $this->bankOnTheClaimCode();
        $accounts = app(NoteAccounts::class);

        $this->assertNotContains($bank, $accounts->others(Note::KIND_CUSTOMER, Note::CREDIT)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            '⛔ ড্যামেজ দাবির কোডে বসা ব্যাংক গ্রাহকের ক্রেডিট নোটে "ড্যামেজ দাবি" হয়ে এল (লাইভের UB)');
        $this->assertSame([StandardChart::SALES_RETURN, StandardChart::DISCOUNT_GIVEN],
            $accounts->others(Note::KIND_CUSTOMER, Note::CREDIT)->pluck('code')->all(), 'প্রস্তুতিটাই ভুল — বাকি দুই খাত নেই');
        $this->assertNotContains($bank, $accounts->others(Note::KIND_SUPPLIER, Note::DEBIT)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            '⛔ ড্যামেজ দাবির কোডে বসা ব্যাংক সরবরাহকারীর ডেবিট নোটে এল');
    }

    /** ⛔ বিক্রয় ফেরত আর ছাড়ের কোডে খাত না থাকলে আগে তালিকার প্রথম — ব্যাংকটাই — চুপচাপ ডিফল্ট হত */
    public function test_the_bank_is_never_the_default(): void
    {
        $bank = $this->bankOnTheClaimCode();
        DB::table((new Account)->getTable())->whereIn('code', [StandardChart::SALES_RETURN, StandardChart::DISCOUNT_GIVEN])
            ->update(['code' => DB::raw("CONCAT('X', code)")]);

        $this->assertNotSame($bank, app(NoteAccounts::class)->defaultOther(Note::KIND_CUSTOMER, Note::CREDIT, (int) $this->dealer->id),
            '⛔ ড্যামেজ দাবির কোডে বসা ব্যাংক ক্রেডিট নোটের অন্য পাশের ডিফল্ট হল');
    }

    /** ⛔ ফর্ম এড়িয়ে ব্যাংকের id পাঠালেও নোট বসে না */
    public function test_a_note_sent_straight_to_the_bank_is_refused(): void
    {
        $bank = $this->bankOnTheClaimCode();

        try {
            app(NoteService::class)->create([
                'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => (int) $this->dealer->id,
                'trx_date' => now()->toDateString(), 'amount' => '1500', 'tax_amount' => '0', 'reason' => 'damage_claim',
                'narration' => 'কোম্পানির পাস করা ড্যামেজ বিল', 'other_account_id' => $bank,
            ]);
            $this->fail('⛔ ড্যামেজ দাবির কোডে বসা ব্যাংকে ক্রেডিট নোট বসে গেল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('other_account_id', $e->errors(), 'প্রস্তুতিটাই ভুল — অন্য কারণে আটকাল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    /** ⛔ ধরন আলাদা হলেও একই: বিক্রয় ফেরতের কোডে দেনার খাত বসে থাকলে সেটা "বিক্রয় ফেরত" নয় */
    public function test_an_account_of_another_type_on_a_standard_code_is_never_offered(): void
    {
        $return = (int) StandardChart::find(StandardChart::SALES_RETURN)->id;
        DB::table((new Account)->getTable())->where('id', $return)->update(['type' => Account::LIABILITY, 'name_en' => 'Depot Loan']);

        $this->assertNotContains($return, app(NoteAccounts::class)->others(Note::KIND_CUSTOMER, Note::CREDIT)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            '⛔ বিক্রয় ফেরতের কোডে বসা দেনার খাত ক্রেডিট নোটে "বিক্রয় ফেরত" হয়ে এল');
    }

    /** ১১৫১-এর খাতটাকে UB-র মতো ব্যাংক বানানো — ব্যাংকের নিচে, টাকার ধরন ব্যাংক */
    private function bankOnTheClaimCode(): int
    {
        $claim = StandardChart::find(StandardChart::DAMAGE_CLAIM);
        $this->assertNotNull($claim, 'প্রস্তুতিটাই ভুল — ডেমোতে ১১৫১ নেই');
        $this->assertTrue(app(StandardChart::class)->holdsItsMeaning($claim), 'প্রস্তুতিটাই ভুল — আসল ড্যামেজ দাবিকেই মান ছকের খাত বলা হল না');

        DB::table((new Account)->getTable())->where('id', $claim->id)->update([
            'name_en' => 'Star Line Accounts', 'money_kind' => Account::BANK,
            'parent_id' => (int) StandardChart::find(StandardChart::BANK)->id,
        ]);

        return (int) $claim->id;
    }
}
