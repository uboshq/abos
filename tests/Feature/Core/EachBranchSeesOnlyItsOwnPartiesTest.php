<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সব শাখার গ্রাহক-সরবরাহকারী-টিল এক জায়গায় দেখাত — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ মালিকের প্রশ্ন ───────────────────────────────────────────────────
 * *"সব শাখার পার্টি এক জায়গায় কেন দেখায়?"* UNIVER BANGLADESH-এর সাত শাখা সাতটা আলাদা
 * ব্যবসা। কাগজের তালিকা বাছা শাখা মানত (20069448), খাতার পর্দাও (1847bb6c) — কিন্তু
 * গ্রাহক, সরবরাহকারী আর টিলের তালিকা, আর বিক্রি-কেনার ফর্মের পিকার তখনো সবার দেখাত।
 *
 * ── ⭐ মাপ: মালিক যেভাবে মাপেন ────────────────────────────────────────────
 * একই মালিক, হেডারে ময়মনসিংহ → নেত্রকোনা → সব শাখা। প্রতিটা তালিকা আর পিকারে কেবল
 * সেই শাখার পক্ষ; শাখাহীন কেবল "সব শাখা"-য়।
 *
 * ⚠️ টাকা পাঠানোর "কোন টিলে" সব শাখার টিল দেখায় কেবল "সব শাখা"-য় — শাখা পেরোনো হস্তান্তর ওখানেই বসে
 * (১ অক্টোবর ২০২৬, [[EachBranchIsFullySeparateTest]])।
 */
final class EachBranchSeesOnlyItsOwnPartiesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** @var array<string, int> */
    private array $customer = [];

    /** @var array<string, int> */
    private array $supplier = [];

    /** @var array<string, int> */
    private array $till = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        $places = ['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'none' => null];

        // ⓘ ডেমোতে টিল একটাই — তিন জায়গার জন্য আরও দুটো, আসল পথে (খাতসহ)
        foreach (['PTA', 'PTB'] as $code) {
            app(CashTillService::class)->create(['code' => $code, 'name_en' => 'Probe till '.$code]);
        }

        foreach ([Customer::class => 'customer', Supplier::class => 'supplier', CashTill::class => 'till'] as $model => $bag) {
            $rows = $model::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
                ->where('is_active', true)->orderBy('id')->take(3)->get();
            $this->assertCount(3, $rows, "প্রস্তুতিটাই ভুল — ডেমোতে তিনটা সক্রিয় {$bag} নেই।");

            foreach (array_keys($places) as $i => $where) {
                $rows[$i]->forceFill(['branch_id' => $places[$where]])->saveQuietly();
                $this->{$bag}[$where] = (int) $rows[$i]->id;
            }
        }
    }

    public function test_each_list_and_picker_shows_only_the_picked_branchs_parties(): void
    {
        $screens = [
            'গ্রাহকের তালিকা' => [route('customer.index'), 'customers', 'customer'],
            'বিক্রয় অর্ডারের গ্রাহক-পিকার' => [route('sales.order.create'), 'customers', 'customer'],
            'সরাসরি বিক্রির গ্রাহক-পিকার' => [route('sales.direct.create'), 'customers', 'customer'],
            'সরবরাহকারীর তালিকা' => [route('supplier.index'), 'suppliers', 'supplier'],
            'ক্রয়-বিলের সরবরাহকারী-পিকার' => [route('purchase.bill.create'), 'suppliers', 'supplier'],
            'টাকা গোনার টিল-পিকার' => [route('accounts.count.create'), 'tills', 'till'],
            'হস্তান্তরের "কোন টিল থেকে"' => [route('accounts.transfer.create'), 'fromTills', 'till'],
        ];

        $expect = ['mms' => ['mms'], 'ntk' => ['ntk'], 'all' => ['mms', 'ntk', 'none']];

        foreach (['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'all' => 'all'] as $pick => $branch) {
            $this->choose($branch);

            foreach ($screens as $name => [$url, $key, $bag]) {
                $ids = $this->idsOn($url, $key);

                foreach (['mms', 'ntk', 'none'] as $where) {
                    $seen = in_array($this->{$bag}[$where], $ids, true);
                    $should = in_array($where, $expect[$pick], true);

                    $this->assertSame($should, $seen, sprintf(
                        '⛔ %s — "%s" বেছে %s-এর সারি %s।',
                        $name, $pick, $where, $should ? 'দেখা যায়নি' : 'দেখা গেল',
                    ));
                }
            }
        }
    }

    public function test_the_transfer_destination_opens_to_every_branch_only_under_all_branches(): void
    {
        /*
         * ⓘ ১ অক্টোবর ২০২৬ মালিক নিয়মটা বদলালেন: *"প্রতিটা শাখা পুরোপুরি আলাদা"*। শাখা পেরোনো
         * হস্তান্তর এখন কেবল "সব শাখা"-য় বসে; এক শাখার দেখায় "কোন টিলে"-তে কেবল নিজের টিল।
         */
        $this->choose($this->mymensingh->id);
        $one = $this->idsOn(route('accounts.transfer.create'), 'tills');

        $this->choose('all');
        $every = $this->idsOn(route('accounts.transfer.create'), 'tills');

        foreach (['mms', 'ntk', 'none'] as $where) {
            $this->assertSame($where === 'mms', in_array($this->till[$where], $one, true), "⛔ ময়মনসিংহ বেছে \"কোন টিলে\"-তে {$where}-এর টিল ভুল জায়গায়।");
            $this->assertContains($this->till[$where], $every, "⛔ \"সব শাখা\"-য় \"কোন টিলে\" থেকে {$where}-এর টিল হারাল।");
        }
    }

    public function test_a_document_still_finds_its_party_from_another_branch(): void
    {
        /*
         * ⚠️ দেয়ালটা কেবল তালিকা আর পিকারে — মডেলে নয়। নেত্রকোনা বেছেও ময়মনসিংহের
         * গ্রাহককে নাম ধরে খোঁজা যায়, নাহলে কাউন্টারের "নগদ গ্রাহক" বা পোস্টিং খালি পেত।
         */
        $this->choose($this->netrakona->id);

        $this->assertNotNull(Customer::query()->find($this->customer['mms']));
        $this->assertNotNull(Supplier::query()->find($this->supplier['mms']));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return list<int> */
    private function idsOn(string $url, string $key): array
    {
        $response = $this->actingAs($this->owner)->get($url)->assertOk();
        $rows = $response->viewData($key);
        $items = method_exists($rows, 'items') ? $rows->items() : $rows;

        return collect($items)->map(fn ($r) => (int) $r->id)->values()->all();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
