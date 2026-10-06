<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * পক্ষের খাতা খুললে নতুন লেনদেন চোখের সামনে — মালিক, ৬ অক্টোবর ২০২৬: তারিখের ক্রমে (আন্তর্জাতিক খাতা), খুললে শেষ পাতা।
 *
 * ⛔ ধরা পড়েছিল বিক্রয় ধারার পরীক্ষায়: গ্রাহকের খাতার প্রথম পাতায় সবচেয়ে পুরনো ৫০টা সারি উল্টো ক্রমে, আর আজকের বিল আর
 * আদায় দ্বিতীয় পাতায় লুকানো; পাতা বদলের লেখা ইংরেজিতে ("Previous / Next / Showing … results")।
 *
 * দাবি, ৫৫টা সারির খাতায় — গ্রাহকের পাতা আর ব্যক্তির খাতা (রিপোর্ট) দুটোতেই:
 *  - `?page=` ছাড়া খুললে শেষ পাতা, তারিখের ক্রমে, সবচেয়ে নতুন সারি সবার শেষে, আর তার জের = গোটা খাতার জের;
 *  - `?page=1` দিলে প্রথম পাতা (পাতা বদল কাজ করে);
 *  - পাতা বদলের লেখা বাংলায়, ইংরেজি নয়।
 */
final class APartysLedgerOpensOnItsLatestPageTest extends TestCase
{
    use RefreshDatabase;

    private const ROWS = 55;

    private const PERSON_ROWS = 110;

    public function test_the_customer_and_the_person_ledgers_open_on_their_latest_page(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'LEDG-1',
            'name_en' => 'Ledger Shop', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $person = Person::query()->firstOrCreate(['company_id' => $company->id, 'name_en' => 'Ledger Person'], ['code' => 'P-LEDG', 'mobile' => '01711000099']);

        // ⓘ ৫৫ দিনে ৫৫টা সারি — প্রতিটা আগের দিনের পরের, শেষেরটা আজ; প্রতিটার অঙ্ক আলাদা (দিন × ১০)
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $handLoan = StandardChart::find('1170')->id;
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL)->id;
        // ⓘ গ্রাহকের পাতা ৫০টায় ভাগ, রিপোর্ট ১০০-তে — তাই ব্যক্তির ১১০টা সারি
        for ($i = 1; $i <= self::PERSON_ROWS; $i++) {
            // ⓘ দিনে দুইটা সারি — চলতি অর্থবছরের ভেতরেই থাকে (ডেমোর বছর ১ জুলাই থেকে)
            $day = Carbon::today()->subDays(intdiv(self::PERSON_ROWS - $i, 2));
            $amount = (string) ($i * 10);
            $parties = [['person', $person->id, $handLoan]];
            if ($i > self::PERSON_ROWS - self::ROWS) {
                $parties[] = ['customer', $customer->id, $receivable];
            }
            foreach ($parties as [$type, $id, $account]) {
                app(PostingEngine::class)->post(sourceType: 'test:ledger-'.$type, sourceId: $i, trxDate: $day, branchId: $company->defaultBranch()?->id, lines: [
                    ['account_id' => $account, 'debit' => $amount, 'party_type' => $type === 'customer' ? Customer::drillSourceType() : 'person', 'party_id' => $id],
                    ['account_id' => $capital, 'credit' => $amount],
                ]);
            }
        }
        // ⓘ গ্রাহকের সারি ৫৬তম থেকে ১১০তম দিনের অঙ্ক — (৫৬ + ১১০) × ৫৫ / ২ × ১০
        $total = (string) ((self::PERSON_ROWS - self::ROWS + 1 + self::PERSON_ROWS) * self::ROWS / 2 * 10);

        // ── গ্রাহকের পাতা ──
        $response = $this->get(route('customer.show', $customer))->assertOk();
        $rows = collect($response->viewData('entries')->items());
        $this->assertSame(2, $response->viewData('entries')->currentPage(), '⛔ খুললে শেষ পাতা নয়।');
        $this->assertSame(Carbon::today()->toDateString(), Carbon::parse($rows->last()->trx_date)->toDateString(), '⛔ আজকের সারি পাতার শেষে নেই।');
        $this->assertLessThanOrEqual($rows->last()->trx_date, $rows->first()->trx_date, '⛔ পাতা তারিখের ক্রমে নয়।');
        $this->assertSame(0, bccomp((string) $rows->last()->net_balance, $total, 4), '⛔ শেষ সারির জের গোটা খাতার জের নয়।');
        $html = (string) $response->getContent();
        $this->assertStringContainsString(__('pager.previous'), $html, 'পাতা বদলের বাংলা তীর নেই।');
        $this->assertStringNotContainsString('Showing', $html, '⛔ পাতা বদলের লেখা ইংরেজিতে।');
        $this->assertStringNotContainsString('&laquo; Previous', $html, '⛔ পাতা বদলের লেখা ইংরেজিতে।');

        $first = $this->get(route('customer.show', $customer).'?page=1')->assertOk()->viewData('entries');
        $this->assertSame(1, $first->currentPage(), '⛔ প্রথম পাতায় যাওয়া যায় না।');

        // ── ব্যক্তির খাতা (রিপোর্ট) ──
        $report = $this->get(route('accounts.report.show', ['slug' => 'person-ledger', 'person_id' => $person->id, 'from' => Carbon::today()->subDays(self::PERSON_ROWS + 5)->toDateString()]))->assertOk()->viewData('result');
        $this->assertSame($report->lastPage(), $report->page, '⛔ ব্যক্তির খাতা খুললে শেষ পাতা নয়।');
        $this->assertGreaterThan(1, $report->lastPage(), 'খাতা এক পাতার — দাবিটা শেষ পাতা মাপছে না।');
        $page1 = $this->get(route('accounts.report.show', ['slug' => 'person-ledger', 'person_id' => $person->id, 'from' => Carbon::today()->subDays(self::PERSON_ROWS + 5)->toDateString(), 'page' => 1]))->assertOk()->viewData('result');
        $this->assertSame(1, $page1->page);
    }
}
