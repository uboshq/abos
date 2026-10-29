<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Reports\SalespersonReports;
use App\Modules\Sales\Widgets\SrBoardSummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বিক্রয়কর্মীদের আজকের বোর্ড ([[SrBoardSummary]]; মালিকের আদেশ, ২০ অক্টোবর ২০২৬)।
 *
 * দাবি: দুই বিক্রয়কর্মীর আজকের বিক্রিতে মালিক অঙ্ক ধরে সাজানো সারি পান, ওয়েবের "বিক্রয়কর্মী ধরে বিক্রি"-র হুবহু অঙ্কে;
 * দেয়ালের ভিতরের প্রত্যেক বিক্রয়কর্মী (সুইচ চালু, নিজের-ডিলার চাবি, সব-ডিলার চাবি নেই) কেবল নিজের ক্রম, অঙ্ক আর মোট জন
 * পান — অন্যজনের নাম বা অঙ্ক গোটা উত্তরের কোথাও নেই, `rows` ঘরটাই নেই; হেডারের শাখা বাছলে সারি সেই শাখায় নামে; আর দরজা
 * দুজনকেই উইজেটটা দেয়।
 */
final class TheSrBoardNeverShowsAnSrSomeoneElsesFigureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $first;

    private User $second;

    private Branch $main;

    private Branch $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->first = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->main = Branch::query()->withoutGlobalScopes()->findOrFail($this->company->defaultBranch()?->id);
        $this->other = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereKeyNot($this->main->id)->orderBy('id')->firstOrFail();

        $this->second = User::factory()->create([
            'name' => 'Widget Second Seller', 'email' => 'second-sr@abos.test',
            'current_company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->second->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->second->assignRole('salesman'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();

        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        app(DealerBindingService::class)->bind((int) $this->second->id, [(int) $niloy->id], Carbon::today()->toDateString());
        app(DealerScope::class)->forget();
    }

    public function test_the_owner_gets_rows_ranked_by_amount_equal_to_the_web_report(): void
    {
        $this->todaysSales($this->main);

        $board = $this->board($this->owner);
        $this->assertSame('সব', $board['branch_label']);
        $this->assertNotEmpty($board['rows'], 'প্রস্তুতিটাই ভুল — আজকের কোনো সারি নেই।');

        // ⭐ ওয়েবের রিপোর্ট, মালিকের নামে, আজকের দিন — মানুষগুলোর সারি, একই ক্রমে
        $web = $this->webRows($this->owner);
        $this->assertSame(count($web), count($board['rows']), '⛔ উইজেট আর ওয়েবের রিপোর্টে মানুষের সংখ্যা আলাদা।');

        foreach ($board['rows'] as $i => $row) {
            $this->assertSame($i + 1, $row['rank']);
            $this->assertSame((string) $web[$i]['salesperson'], $row['name'], "⛔ {$row['rank']} নম্বরে ওয়েব আর উইজেট আলাদা মানুষ।");
            $this->assertSame(bcadd((string) $web[$i]['net_sales'], '0', 4), $row['amount'], '⛔ অঙ্ক ওয়েবের রিপোর্টের নয়।');
            if ($i > 0) {
                $this->assertGreaterThanOrEqual(0, bccomp($board['rows'][$i - 1]['amount'], $row['amount'], 4), '⛔ ক্রম অঙ্ক ধরে নয়।');
            }
        }

        // ⓘ দ্বিতীয়জনের বড় বিল — আইডির ক্রমে তিনি পরে, অঙ্কের ক্রমে প্রথম
        $this->assertSame('Widget Second Seller', $board['rows'][0]['name'], '⛔ সবচেয়ে বড় বিক্রি প্রথমে নেই।');
        $this->assertSame('9345.5000', $board['rows'][0]['amount']);
        $this->assertContains($this->first->name, array_column($board['rows'], 'name'), 'প্রস্তুতিটাই ভুল — প্রথম বিক্রয়কর্মী নেই।');
        $this->assertSame(route('sales.report.show', ['slug' => 'by-salesperson', 'from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->toDateString()], false), $board['link']);
    }

    public function test_each_walled_salesman_gets_only_himself_and_never_the_others_name_or_figure(): void
    {
        $this->todaysSales($this->main);
        $owners = $this->board($this->owner)['rows'];
        $this->assertLessThan(SrBoardSummary::ROWS + 1, count($owners), 'প্রস্তুতিটাই ভুল — ৮-এর বেশি বিক্রেতা, "of" মেলানো যায় না।');
        $byName = array_column($owners, null, 'name');

        foreach ([[$this->first, $this->second], [$this->second, $this->first]] as [$me, $them]) {
            $me = $me->fresh();
            $this->assertTrue(app(DealerScope::class)->switchOn(), 'প্রস্তুতিটাই ভুল — ডিলারের দেয়াল বন্ধ।');
            $this->assertTrue($me->can(DealerScope::OWN) && ! $me->can(DealerScope::ALL), "প্রস্তুতিটাই ভুল — {$me->email}-এর চাবি দেয়ালের নয়।");
            $this->assertTrue(app(DealerScope::class)->walled($me), "প্রস্তুতিটাই ভুল — {$me->email} দেয়ালে নেই।");
            $this->assertFalse(app(DataScope::class)->viewsOneBranch($me), "প্রস্তুতিটাই ভুল — {$me->email} হেডারে এক শাখায়।");
            $this->assertNull(app(DataScope::class)->idsFor($me, UserDataScope::BRANCH), "প্রস্তুতিটাই ভুল — {$me->email} শাখায় সীমিত, মোট জন মালিকের সাথে মেলে না।");

            $response = $this->as($me)->getJson('/api/v1/widgets/summary?keys=sr_board')->assertOk();
            $board = $response->json('widgets.sr_board');

            $this->assertNotNull($board, "⛔ দরজা {$me->email}-কে উইজেটটা দিল না।");
            $this->assertArrayNotHasKey('rows', $board, "⛔ {$me->email} পুরো তালিকা পেলেন।");
            $this->assertArrayNotHasKey('branch_label', $board);
            $this->assertSame(['self', 'link'], array_keys($board), '⛔ বিক্রয়কর্মীর উত্তরে নিজের ঘর আর লিংক ছাড়া কিছু — সময়ও নয় (দরজার asOf)।');
            $this->assertSame(['rank', 'amount', 'of'], array_keys($board['self']));

            $mine = $byName[$me->name] ?? null;
            $this->assertNotNull($mine, "প্রস্তুতিটাই ভুল — মালিকের তালিকায় {$me->email} নেই।");
            $this->assertSame($mine['rank'], $board['self']['rank'], "⛔ {$me->email}-এর ক্রম ভুল।");
            $this->assertSame($mine['amount'], $board['self']['amount'], "⛔ {$me->email}-এর অঙ্ক ভুল।");
            $this->assertSame(count($owners), $board['self']['of'], "⛔ {$me->email}: মোট কতজন ভুল।");

            // ⛔ অন্যজনের নাম আর অঙ্ক — গোটা উত্তরের কোথাও নয়, কাঁচা লেখাতেও নয়
            $theirs = $byName[$them->name] ?? null;
            $this->assertNotNull($theirs);
            $this->assertNotSame($theirs['amount'], $mine['amount'], 'প্রস্তুতিটাই ভুল — দুজনের অঙ্ক এক।');
            $whole = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).$response->getContent();
            $this->assertStringNotContainsString($them->name, $whole, "⛔ {$me->email} অন্যজনের নাম দেখলেন।");
            $this->assertStringNotContainsString($theirs['amount'], $whole, "⛔ {$me->email} অন্যজনের অঙ্ক দেখলেন।");
            $this->assertStringNotContainsString(rtrim(rtrim($theirs['amount'], '0'), '.'), $whole, "⛔ {$me->email} অন্যজনের অঙ্ক দেখলেন।");
        }
    }

    public function test_the_header_branch_narrows_the_board(): void
    {
        $this->todaysSales($this->other);

        $all = $this->board($this->owner);
        $this->assertContains('Widget Second Seller', array_column($all['rows'], 'name'), 'প্রস্তুতিটাই ভুল — অন্য শাখার বিক্রি "সব"-এ নেই।');

        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $this->main->id])->save();
        CompanyContext::set($this->company->id, $this->main->id);
        app(DataScope::class)->forget();

        $one = $this->board($this->owner->fresh());
        $this->assertSame($this->main->name(), $one['branch_label'], '⛔ হেডারের শাখার নাম নেই।');
        $this->assertNotContains('Widget Second Seller', array_column($one['rows'], 'name'), '⛔ হেডারে এক শাখা, অথচ অন্য শাখার বিক্রি সারিতে।');
        $this->assertContains($this->first->name, array_column($one['rows'], 'name'), 'প্রস্তুতিটাই ভুল — নিজের শাখার বিক্রিও নেই।');

        $web = $this->webRows($this->owner->fresh());
        $this->assertSame(array_column($web, 'salesperson'), array_column($one['rows'], 'name'), '⛔ শাখা বাছায় উইজেট আর ওয়েব আলাদা।');
    }

    public function test_the_door_gives_the_widget_to_the_owner_and_the_salesmen(): void
    {
        foreach ([$this->owner, $this->first, $this->second] as $user) {
            $widgets = $this->as($user->fresh())->getJson('/api/v1/widgets/summary')->assertOk()->json('widgets');
            $this->assertArrayHasKey('sr_board', $widgets, "⛔ দরজা {$user->email}-কে বোর্ড দিল না।");
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** প্রথমজনের ডিলার রহিম (ডেমোর বাঁধন) মূল শাখায়; দ্বিতীয়জনের নিলয় বড় বিলে, যে শাখা বলা হয় */
    private function todaysSales(Branch $secondsBranch): void
    {
        $rahim = Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail();
        $niloy = Customer::acrossDealers()->where('name_en', 'Niloy Store')->firstOrFail();
        $this->bill('WSR-A', $rahim, $this->main, '2105.25');
        $this->bill('WSR-B', $niloy, $secondsBranch, '9345.50');
    }

    /** @return array<string, mixed> */
    private function board(User $user): array
    {
        $board = $this->as($user)->getJson('/api/v1/widgets/summary?keys=sr_board')->assertOk()->json('widgets.sr_board');
        $this->assertNotNull($board, "⛔ দরজা {$user->email}-কে বোর্ড দিল না।");
        $this->assertArrayHasKey('rows', $board, "⛔ {$user->email} পুরো তালিকা পেলেন না।");

        return $board;
    }

    /** @return list<array<string, mixed>> ওয়েবের রিপোর্টের মানুষের সারি, আজ */
    private function webRows(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
        $today = Carbon::today()->toDateString();

        $rows = collect(app(ReportEngine::class)->run(SalespersonReports::KEY, ['from' => $today, 'to' => $today])->rows)
            ->map(fn ($r) => (array) $r)
            ->filter(fn (array $r) => $r['seller_id'] !== null)
            ->values()
            ->all();

        return array_slice($rows, 0, SrBoardSummary::ROWS);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        app(DealerScope::class)->forget();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }

    private function bill(string $no, Customer $customer, Branch $branch, string $amount): void
    {
        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'financial_year_id' => FinancialYear::query()->where('is_current', true)->firstOrFail()->id,
            'document_no' => $no,
            'customer_id' => $customer->id,
            'trx_date' => Carbon::today()->toDateString(),
            'subtotal' => $amount,
            'tax' => '0',
            'total' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'created_by' => $this->owner->id,
        ]);

        (new SalesInvoiceLine)->forceFill([
            'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'product_id' => Product::query()->firstOrFail()->id,
            'qty' => '1', 'rate' => $amount, 'amount' => $amount, 'tax' => '0',
        ])->save();
    }
}

