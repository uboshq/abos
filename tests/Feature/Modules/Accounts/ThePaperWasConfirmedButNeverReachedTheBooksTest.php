<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\PostingBacklog;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিশ্চিত হয়েছে, অথচ খাতায় ওঠেনি — সেটা এখন দেখা যায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"Accounts e post pending hoye thak le bujazay na tar jonno ki kono
 * porda lage?"* ⓘ পোস্টিং মনিটরের "আটকে আছে" ট্যাব কেবল ভাউচার দেখত।
 * অথচ বিপজ্জনক অবস্থাটা অন্য: বিলটা নিশ্চিত, কাগজে কাজ শেষ, খাতায় নেই —
 * তখন লাভ-ক্ষতি, বকেয়া আর মজুদের মূল্য তিনটাই চুপচাপ ভুল বলে।
 *
 * ⚠️ মাপটা ডেমোর আসল বিল ধরে: তার খতিয়ানের সারিগুলো সরিয়ে দিলে সেটা
 * "খাতায় ওঠেনি" অবস্থায় পড়ে — ঠিক যেমন পোস্ট করতে গিয়ে ভেঙে গেলে হত।
 */
final class ThePaperWasConfirmedButNeverReachedTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_confirmed_paper_with_no_ledger_rows_is_listed_and_counted(): void
    {
        $before = app(PostingBacklog::class)->count();

        $bill = $this->aConfirmedBillOutsideTheBooks();

        $this->assertSame($before + 1, app(PostingBacklog::class)->count(), 'খাতায় নেই, তবু গোনা হয়নি।');

        $html = $this->get(route('accounts.control.posting', ['tab' => 'stuck']))->assertOk()->getContent();

        $this->assertStringContainsString('data-not-posted', $html, 'পর্দায় "খাতায় ওঠেনি" অংশটাই নেই।');
        $this->assertStringContainsString($bill->document_no, $html, 'কাগজটা তালিকায় আসেনি।');

        /*
         * ⚠️ কম্পাইল না হওয়া ট্যাগ — abos-f9-এর ধরা, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ অ্যাট্রিবিউটের ভিতরে একটা ASCII ডবল কোট ঢুকলে Blade ট্যাগটা আর
         * চেনে না, আর পুরোটা **লেখা হিসেবে** ছাপে। পাতা তবু ২০০ দেয়, তাই
         * কোনো পরীক্ষা লাল হয় না — টেবিলটা নীরবে উধাও।
         */
        $this->assertStringNotContainsString('<x-ui.', $html, 'একটা কম্পোনেন্ট কম্পাইল না হয়ে লেখা হিসেবে ছাপা হয়েছে।');

        /* নম্বরটা ক্লিকযোগ্য — কাগজটা খুলেই বোঝা যায় কেন খাতায় ওঠেনি */
        $this->assertMatchesRegularExpression('/<a[^>]*>\s*'.preg_quote($bill->document_no, '/').'/', $html,
            'নথি নম্বরটা লিংক নয়।');
    }

    /** সংখ্যাটা ড্যাশবোর্ডেও — যিনি জানেন না, তিনি মনিটরে যাবেন না। */
    public function test_the_dashboard_says_it_without_being_asked(): void
    {
        $before = app(PostingBacklog::class)->count();

        $this->aConfirmedBillOutsideTheBooks();

        $this->get(route('accounts.dashboard'))
            ->assertOk()
            ->assertSee('data-not-posted-count="'.($before + 1).'"', false);
    }

    /** ⓘ ১২০ দিনের পুরনো কাগজ তালিকায় আসে না — নাহলে আমদানি করা পুরনো সারিতে আসলটা হারাত। */
    public function test_an_old_paper_stays_out_of_the_list(): void
    {
        $before = app(PostingBacklog::class)->count();

        $this->aConfirmedBillOutsideTheBooks(PostingBacklog::LOOK_BACK_DAYS + 5);

        $this->assertSame($before, app(PostingBacklog::class)->count(), 'সীমার বাইরের কাগজও গোনা হয়েছে।');
    }

    /**
     * একটা নিশ্চিত ক্রয় বিল, যার নামে খতিয়ানে একটাও সারি নেই।
     *
     * ⚠️ প্রথমে ডেমোর একটা পোস্ট হওয়া কাগজ ধরে লিখেছিলাম আর তার খতিয়ানের
     * সারি মুছে দিচ্ছিলাম। ⓘ কিন্তু ডেমোতে পোস্ট হওয়া কোনো বিক্রয় বা ক্রয়
     * বিলই নেই (খতিয়ানে কেবল খোলা মজুদ), তাই মাপার কাগজটা এখানেই বসানো
     * হয় — অবস্থাটা হুবহু একই: নিশ্চিত কাগজ, খাতায় কিছু নেই।
     */
    private function aConfirmedBillOutsideTheBooks(int $daysAgo = 3): PurchaseBill
    {
        $supplier = Supplier::query()->firstOrFail();

        $bill = PurchaseBill::query()->create([
            'company_id' => $this->company->id,
            'document_no' => 'PB-NOTPOSTED-'.$daysAgo,
            'supplier_id' => $supplier->id,
            'trx_date' => now()->subDays($daysAgo)->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
            'total' => '1500',
        ]);

        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', 'purchase_bill')
            ->where('source_id', $bill->id)
            ->count(), 'বসানো বিলটার নামে খতিয়ানে সারি আছে — মাপটা তখন অর্থহীন।');

        return $bill;
    }
}
