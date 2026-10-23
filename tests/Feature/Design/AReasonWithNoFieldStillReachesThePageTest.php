<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ ঘরহীন কারণও পাতায় পৌঁছায় — একবারই (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * <p>খাতা পোস্ট না নিলে কারণ ফেরে `posting` ঘরে, পাতা যেখান থেকে এসেছিল সেখানে (`bootstrap/app.php`)। কিন্তু ১৪টা কাগজের
 * পাতায় সাধারণ বাক্স ([[x-ui.errors]]) ছিলই না — বিলের তালিকা থেকে "নিশ্চিত" চাপলে পাতা ফিরত, বিল খসড়াই থাকত, পর্দা একটা
 * শব্দও বলত না (লাইভে invoice.confirm এভাবেই চুপচাপ ব্যর্থ)। এখন layout পাতার উপরে বাক্স বসায়, পাতা যা দেখায়নি কেবল তা;
 * পাতার নিজের বাক্স থাকলে সেটাই দেখায় — কোনো কারণ দুবার নয়।
 */
final class AReasonWithNoFieldStillReachesThePageTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /** ⭐ লাইভের নীরব ব্যর্থতা: বিলের তালিকা থেকে নিশ্চিত, খাতা নিল না — তালিকায় ফিরে কারণটা পাতার বাক্সে, একবার */
    public function test_a_bill_refused_from_its_list_says_why_on_the_list(): void
    {
        $this->from(route('sales.direct.create'))->post(route('sales.direct.store'), [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'lines' => [['product_id' => Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail()->id, 'qty' => '1', 'rate' => '100']],
            'save_as_draft' => '1',
        ]);
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertTrue($invoice->isDraft(), 'প্রস্তুতিটাই ভুল — রাখা খসড়া হয়নি');
        $this->deactivate(StandardChart::SALES);

        $this->from(route('sales.invoice.index'))->post(route('sales.invoice.confirm', $invoice))
            ->assertRedirect(route('sales.invoice.index'));
        $reason = $this->reasonOn('posting');

        $this->assertOnceInTheBox(route('sales.invoice.index'), $reason,
            '⛔ বিলের তালিকা থেকে নিশ্চিত খাতায় আটকাল, আর তালিকার পাতা কারণটা দেখাল না (লাইভের নীরব ব্যর্থতা)');
    }

    /** ⛔ চালানের পরিবহন পর্দা: ভাড়ার ভাউচারের খাত নতুন সারি নেয় না — কারণটা ঐ পাতাতেই, একবার */
    public function test_a_fare_refused_on_the_transport_page_says_why_there(): void
    {
        $till = app(CashTillService::class)->create(['code' => 'ERR-F', 'name_en' => 'Fare till']);
        $this->putMoneyIn(Account::query()->findOrFail($till->account_id), '2000');
        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        )->fresh(['lines']));
        $this->deactivate(StandardChart::VEHICLE_HIRE);

        $this->from(route('sales.challan.transport', $challan))->post(route('sales.challan.fare.record', $challan), [
            'fare_paid_by' => 'us', 'transport_cost' => '250', 'fare_when' => 'now', 'fare_account_id' => $till->account_id,
        ])->assertRedirect(route('sales.challan.transport', $challan));
        $this->assertNull(DeliveryChallan::query()->findOrFail($challan->id)->fare_voucher_id, 'প্রস্তুতিটাই ভুল — ভাড়ার ভাউচার বসে গেল');
        $reason = $this->reasonOn('posting');

        $this->assertOnceInTheBox(route('sales.challan.transport', $challan), $reason,
            '⛔ ভাড়ার ভাউচার খাতায় আটকাল, আর পরিবহন পর্দা কারণটা দেখাল না');
    }

    /** ⛔ যে পাতার নিজের বাক্স আছে (নোটের পাতা), সেখানে layout চুপ — কারণটা ঠিক একবার */
    public function test_a_page_with_its_own_box_shows_the_reason_exactly_once(): void
    {
        $note = app(NoteService::class)->create([
            'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => (int) Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'tax_amount' => '50', 'reason' => 'damage_claim',
            'narration' => 'ভ্যাটসহ ক্রেডিট নোট',
        ]);
        $this->deactivate(StandardChart::VAT_PAYABLE);

        $this->from(route('accounts.note.show', $note))->post(route('accounts.note.confirm', $note))
            ->assertRedirect(route('accounts.note.show', $note));
        $reason = $this->reasonOn('posting');

        $this->assertOnceInTheBox(route('accounts.note.show', $note), $reason,
            '⛔ নিজের বাক্সওয়ালা পাতায় কারণটা একবার নয় — layout-এর বাক্স পাতার বাক্সের সঙ্গে আবার দেখাল, বা কেউই দেখাল না');
    }

    /** ⛔ এক অনুরোধে দুটো বাক্স আঁকা হলেও কারণ একবার — যে আগে আঁকা হয় সে-ই দেখায় */
    public function test_two_boxes_in_one_request_show_one(): void
    {
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['account' => ['খাতের কারণ']])));

        $html = Blade::render('<x-ui.errors /><div>মাঝে</div><x-ui.errors />');

        $this->assertSame(1, substr_count($html, 'role="alert"'), '⛔ এক অনুরোধে দুটো ভুলের বাক্স — কারণ দুবার দেখাল');
        $this->assertSame(1, substr_count($html, 'খাতের কারণ'), '⛔ কারণটা দুবার আঁকা হল');
    }

    /**
     * ⛔ হাতে লেখা তালিকার পাতা (~১৫০টা, x-ui.errors ছাড়া) — উদ্ধৃতি আর & থাকা কারণও পাতা যেভাবে escape করে আঁকে সেভাবেই
     * মেলে, তাই layout-এর বাক্স তা আবার দেখায় না: ঠিক একবার (fe, ২৪ অক্টোবর ২০২৬)।
     */
    public function test_a_reason_with_quotes_and_an_ampersand_in_a_hand_written_list_shows_once(): void
    {
        $reason = 'খাত "Cash & Bank" নতুন সারি নেয় না — অন্য খাত বাছুন';
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['posting' => [$reason]])));

        // ⓘ পাতার নিজের হাতের তালিকা, যেমন voucher/show আঁকে — তারপর layout-এর বাক্স, পাতার আঁকা লেখা দিয়ে
        $page = Blade::render('<ul class="list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>');
        $box = Blade::render('<x-ui.errors :shown="$shown" />', ['shown' => new \Illuminate\Support\HtmlString($page)]);

        $this->assertSame(1, substr_count($page.$box, e($reason)),
            '⛔ উদ্ধৃতি আর &-সহ কারণ হাতে লেখা তালিকায় আছে, তবু layout-এর বাক্স আবার দেখাল (escape না মিলিয়ে তুলনা)');
    }

    /**
     * ⛔ ছোট একটা কারণ পাতার অন্য লেখার ভিতরে অংশ হিসেবে আছে — সেটা "দেখানো" নয়; layout-এর বাক্স তবুও দেখায়, হারায় না।
     */
    public function test_a_short_reason_inside_other_text_is_still_shown(): void
    {
        $reason = 'খাত নেই';
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['account' => [$reason]])));

        $page = '<p class="text-sm">এই তালিকায় খাত নেই এমন সারি বাদ দেওয়া হয়</p>';
        $box = Blade::render('<x-ui.errors :shown="$shown" />', ['shown' => new \Illuminate\Support\HtmlString($page)]);

        $this->assertStringContainsString('role="alert"', $box, '⛔ ছোট কারণটা পাতার অন্য বাক্যের ভিতরে মিলল বলে layout-এর বাক্স দেখাল না — কারণ হারাল');
        $this->assertStringContainsString(e($reason), $box, '⛔ layout-এর বাক্সে কারণটা নেই');
    }

    /** ⭐ পাহারা: প্রতিটা layout পাতার উপরে বাক্স বসায়, পাতার আঁকা লেখা দিয়ে — নতুন layout ভুলে গেলে লাল */
    public function test_every_layout_carries_the_box(): void
    {
        $layouts = [
            ...glob(resource_path('views/components/layouts/*.blade.php')) ?: [],
            ...glob(app_path('Modules/*/Resources/views/components/*/layout.blade.php')) ?: [],
            ...glob(app_path('Modules/*/Resources/views/components/layout.blade.php')) ?: [],
        ];
        $this->assertNotEmpty($layouts, 'প্রস্তুতিটাই ভুল — কোনো layout পাওয়া গেল না');

        $missing = array_values(array_filter($layouts, fn (string $file) => substr_count((string) file_get_contents($file), '<x-ui.errors :shown="$slot" />')
            < substr_count((string) file_get_contents($file), '{{ $slot }}')));

        $this->assertSame([], $missing, "⛔ এই layout-এ slot-এর উপরে ভুলের বাক্স নেই — ঘরহীন কারণ সেখানে হারাবে:\n".implode("\n", $missing));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function deactivate(string $code): void
    {
        $account = StandardChart::find($code);
        $this->assertNotNull($account, 'প্রস্তুতিটাই ভুল — ডেমোতে '.$code.' নেই');
        DB::table((new Account)->getTable())->where('id', $account->id)->update(['is_active' => false]);
    }

    /** শেষ অনুরোধের ঐ ঘরের কারণ — সেশনের থলের দুই আকারেই */
    private function reasonOn(string $key): string
    {
        $bag = session('errors');
        $messages = is_array($bag) ? (($bag['default'] ?? $bag)['messages'] ?? []) : ($bag?->getBag('default')->toArray() ?? []);
        $reason = (string) ($messages[$key][0] ?? '');
        $this->assertNotSame('', $reason, 'প্রস্তুতিটাই ভুল — "'.$key.'" ঘরে কারণ নেই: '.json_encode($messages, JSON_UNESCAPED_UNICODE));

        return $reason;
    }

    /** পাতায় কারণটা একবারই, আর সেটা ভুলের বাক্সের ভিতরে */
    private function assertOnceInTheBox(string $url, string $reason, string $message): void
    {
        $page = (string) $this->get($url)->assertOk()->getContent();
        preg_match_all('#<div role="alert".*?</div>#s', $page, $boxes);

        $inBox = array_sum(array_map(fn (string $box) => substr_count($box, e($reason)), $boxes[0]));
        $this->assertSame(1, $inBox, $message.' (বাক্সে '.$inBox.' বার)');
        $this->assertSame(1, substr_count($page, e($reason)), $message.' (পাতায় '.substr_count($page, e($reason)).' বার)');
    }
}
