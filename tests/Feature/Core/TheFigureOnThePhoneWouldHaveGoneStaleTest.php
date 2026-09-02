<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ফোনে বসে থাকা সংখ্যাটা নীরবে পুরনো হয়ে যেত।
 *
 * ── কোন ভুলটা এই পরীক্ষাটা ঠেকায় ────────────────────────────────────
 * ডেল্টা-সিঙ্কের স্বাভাবিক লেখাটা হলো `where('updated_at', '>', $since)`।
 * সেটা তখনই কাজ করে যখন সংখ্যাটা **ওই সারিতেই লেখা থাকে**।
 *
 * বকেয়া লেখা থাকে না। ওটা খতিয়ান থেকে গোনা হয়
 * ([[Customer::outstanding()]]), তাই একটা বিল কাটলে বা টাকা জমা পড়লে
 * **বকেয়া বদলায় অথচ `customers.updated_at` নড়েও না**।
 *
 * ফল হত: ফোন প্রথম সিঙ্কে বকেয়া পেত, তারপর দোকানটা মাসের পর মাস মাল
 * নিত ও টাকা দিত, আর ফোনের সংখ্যাটা **প্রথম দিনেরটাই** থেকে যেত।
 * সেলসম্যান "৫,০০০ বাকি" দেখে অর্ডার নিতেন, বাস্তবে বাকি ৮০,০০০ —
 * আর কোনো পর্দা, কোনো পাহারা, কোনো টেস্ট লাল হত না। **সংখ্যাটা ভুল,
 * অথচ সবকিছু কাজ করছে বলে মনে হত।**
 *
 * মজুদেও হুবহু একই ফাঁদ, একই কারণে — মজুদ `products`-এ নেই, ওটা
 * `stock_movements`-এর যোগফল।
 */
class TheFigureOnThePhoneWouldHaveGoneStaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->salesman);

        app(StandardChart::class)->install();
    }

    /**
     * ⚠️ এটাই আসল পরীক্ষা।
     *
     * গ্রাহকের সারিতে **একটা অক্ষরও বদলায় না** — শুধু খতিয়ানে একটা
     * সারি বসে। তবু বকেয়াটা আবার সিঙ্ক হতে হবে।
     */
    public function test_a_due_resyncs_when_the_ledger_moves_though_the_customer_row_does_not(): void
    {
        $shop = Customer::query()->firstOrFail();

        // প্রথম সিঙ্ক — সব বকেয়া নেমে গেল, ওয়াটারমার্ক এগোল
        $this->sync()->pull($this->salesman, 'phone-a', 'customer', 500);

        /*
         * ⚠️ ঘড়ি এগোয় `pull-complete`-এর **আগে**, পরে নয়।
         *
         * ওয়াটারমার্ক বসে "এখন"-এ, আর কার্সার তার এক মিনিট আগে থেকে পড়ে
         * ([[SyncService::cursorFor]])। ডেমোর সারিগুলো এইমাত্র তৈরি, তাই
         * ওয়াটারমার্ক এখনই বসালে ওগুলো ওভারল্যাপের ভেতরেই থেকে যেত আর
         * প্রতিবার ফিরে আসত।
         *
         * পরে এগোলে লাভ নেই — ওয়াটারমার্ক অতীতে বসা থাকে, ঘড়ি এগোলেও
         * সে নড়ে না। প্রথমবার এটাই ভুল লিখেছিলাম।
         */
        $this->travel(2)->minutes();
        $this->sync()->recordSuccessfulPull('phone-a', 'customer');

        $quiet = $this->dueRecords();
        $this->assertSame([], $quiet, 'কিছু না বদলালে দ্বিতীয় সিঙ্কে কিছু আসার কথা নয়।');

        $rowTouchedAt = $shop->fresh()->updated_at;

        // দোকানটা মাল নিল — খতিয়ানে একটা সারি, গ্রাহকের সারিতে কিছু নয়
        $this->putOnTheLedger($shop, '7500.0000');

        $this->assertEquals(
            $rowTouchedAt,
            $shop->fresh()->updated_at,
            'পরীক্ষাটাই ভুল হয়ে যেত যদি গ্রাহকের সারি নড়ত — তখন সরল updated_at দিয়েও ধরা পড়ত।',
        );

        $again = $this->dueRecords();

        $this->assertContains(
            (string) $shop->public_id,
            array_column($again, 'entityId'),
            'খতিয়ান নড়েছে অথচ বকেয়া আবার সিঙ্ক হয়নি — ফোনে সংখ্যাটা চিরকাল পুরনো থেকে যেত।',
        );
    }

    /**
     * ক্রেডিট সীমা বদলালেও যেতে হবে — ওটা গ্রাহকের সারিতে, খতিয়ানে নয়।
     *
     * কেবল খতিয়ান দেখলে সীমা বাড়ানোর খবরটা ফোনে কোনোদিন পৌঁছাত না,
     * আর ঠিক ওই সীমাটাই সেলসম্যান দেখে অর্ডার নেন।
     */
    public function test_a_changed_credit_limit_also_resyncs(): void
    {
        $shop = Customer::query()->firstOrFail();

        $this->sync()->pull($this->salesman, 'phone-a', 'customer', 500);
        $this->travel(2)->minutes();
        $this->sync()->recordSuccessfulPull('phone-a', 'customer');

        $this->assertSame([], $this->dueRecords());

        $shop->forceFill(['credit_limit' => '99999.0000'])->save();

        $this->assertContains(
            (string) $shop->public_id,
            array_column($this->dueRecords(), 'entityId'),
        );
    }

    /**
     * ⚠️ ক্রয়মূল্য অনুমতি ছাড়া তারে ওঠে না।
     *
     * পর্দায় তালাটা ২ সেপ্টেম্বর ২০২৬-এ বসেছে ([[FieldSecurity]]),
     * কিন্তু **JSON-এ `@can` বলে কিছু নেই** — API-টাই হত ওই তালার
     * চারপাশ দিয়ে যাওয়ার পথ। ঘরটা বাদ দেওয়া হয়, mask পাঠানো হয় না।
     *
     * ── কেন বিক্রয়কর্মীকে হাতে অনুমতিটা দেওয়া হয় ───────────────────
     * প্রথমে ধরে নিয়েছিলাম তাঁর পণ্য দেখার অনুমতি আছে, আর টেস্ট লাল
     * হয়েছিল কারণ **তালিকা খালি আসছিল**। মেপে দেখা গেল ধারণাটাই ভুল:
     * ডেমোর `salesman` রোল পায় কেবল `sales.%` আর `customer.%` — তাই
     * খালি তালিকাটা আসলে **সঠিক আচরণ**, বাগ নয়।
     *
     * তাই এখানে ঠিক একটাই চাবি হাতে দেওয়া হয় — পণ্য দেখার — আর
     * খরচের চাবিটা **নয়**। ওটাই এই পরীক্ষার আসল প্রশ্ন: যিনি পণ্য
     * দেখতে পান কিন্তু খরচ নয়, তিনি কী পান।
     *
     * ⚠️ আর এটা একটা ব্যবসায়িক প্রশ্নও তুলেছে, যেটা মালিককে জানানো
     * হয়েছে: **মাঠের বিক্রয়কর্মী পণ্যের তালিকা না পেলে অফলাইনে অর্ডারই
     * লিখতে পারবেন না।** রোলটা বদলাবে কি না, সেটা তাঁর সিদ্ধান্ত।
     */
    public function test_the_cost_price_never_reaches_a_phone_without_the_key(): void
    {
        $this->salesman->givePermissionTo('inventory.product.view');
        $this->salesman->forgetCachedPermissions();

        $withoutCostKey = $this->productPayloads($this->salesman->fresh());

        $this->assertNotEmpty($withoutCostKey, 'পণ্য দেখার চাবি দেওয়া হয়েছে — তালিকা আসার কথা।');

        foreach ($withoutCostKey as $payload) {
            $this->assertArrayNotHasKey('purchasePrice', $payload);
            $this->assertArrayHasKey('salePrice', $payload, 'বিক্রয়মূল্য বাদ পড়ার কথা নয় — অফলাইনে দর ওটাই।');
        }

        // খরচের চাবিটা দিলে ঘরটা ফিরে আসে — পরীক্ষাটা অন্ধ নয় তার প্রমাণ
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        Cache::flush();

        $withCostKey = $this->productPayloads($owner);

        $this->assertNotEmpty($withCostKey);
        $this->assertArrayHasKey(
            'purchasePrice',
            $withCostKey[0],
            'মালিকের inventory.cost.view আছে — ঘরটা না এলে বোঝা যেত পরীক্ষাটা সবসময়ই পাশ করত।',
        );
    }

    /**
     * ⚠️ যাঁর পণ্য দেখার চাবিই নেই, তিনি কিছুই পান না।
     *
     * ডেমোর বিক্রয়কর্মীর অবস্থা ঠিক এটাই, আর সেটা দুর্ঘটনা নয় — রোলটা
     * `sales.%` আর `customer.%` ছাড়া কিছু দেয় না।
     */
    public function test_a_role_without_the_product_key_gets_no_products(): void
    {
        $this->assertSame([], $this->productPayloads($this->salesman));
    }

    /**
     * এই ডিভাইস এখনো যা পায়নি — বকেয়ার রেকর্ডগুলো।
     *
     * @return list<array<string, mixed>>
     */
    private function dueRecords(): array
    {
        $batch = $this->sync()->pull($this->salesman, 'phone-a', 'customer', 500);

        return array_values(array_filter(
            $batch['records'],
            fn (array $record) => $record['entityType'] === 'CustomerDue',
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productPayloads(?User $as = null): array
    {
        $batch = $this->sync()->pull($as ?? $this->salesman, 'phone-p'.spl_object_id($as ?? $this->salesman), 'inventory', 500);

        $payloads = [];

        foreach ($batch['records'] as $record) {
            if ($record['entityType'] !== 'Product') {
                continue;
            }

            $payloads[] = json_decode($record['payloadJson'], true);
        }

        return $payloads;
    }

    /**
     * খতিয়ানে একটা সারি, গ্রাহকের নামে — গ্রাহকের সারি না ছুঁয়ে।
     *
     * সরাসরি [[LedgerEntry]] বসানো হয়, [[PostingEngine]] দিয়ে নয়: এখানে
     * পরীক্ষার বিষয় **ওয়াটারমার্ক**, পোস্টিংয়ের নিয়ম নয়। ইঞ্জিন দিয়ে
     * গেলে একটা সম্পূর্ণ ডকুমেন্ট বানাতে হত, আর তখন গ্রাহকের সারিও
     * নড়ে যেতে পারত — অর্থাৎ পরীক্ষাটা যা প্রমাণ করতে চায় ঠিক সেটাই
     * প্রমাণ করতে পারত না।
     */
    private function putOnTheLedger(Customer $shop, string $debit): void
    {
        LedgerEntry::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $this->financialYearId(),
            'account_id' => Account::query()->where('is_group', false)->firstOrFail()->id,
            'party_type' => Customer::drillSourceType(),
            'party_id' => $shop->id,
            'trx_date' => now()->toDateString(),
            'debit' => $debit,
            'credit' => '0',
            'source_type' => 'sync_test',
            'source_id' => $shop->id,
            'narration' => 'ওয়াটারমার্কের পরীক্ষা',
            'created_by' => $this->salesman->id,
        ]);
    }

    /**
     * চলতি অর্থবছর — কনসোল/টেস্টে `CompanyContext` ওটা বসায় না।
     *
     * প্রথমে `CompanyContext::financialYearId()` ব্যবহার করেছিলাম আর সেটা
     * `null` দিত, তাই `ledger_entries`-এ insert ভাঙত। কলামটা `not null`,
     * আর সেটাই ঠিক: অর্থবছর ছাড়া একটা দাখিলা কোন বইয়ের তা বলা যায় না।
     */
    private function financialYearId(): int
    {
        return (int) FinancialYear::query()->orderBy('id')->firstOrFail()->id;
    }

    private function sync(): SyncService
    {
        return app(SyncService::class);
    }
}
