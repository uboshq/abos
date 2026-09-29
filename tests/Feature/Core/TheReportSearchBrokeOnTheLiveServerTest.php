<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * রিপোর্টের খোঁজ লাইভে ২৭টা রিপোর্ট ভাঙত — খাতা, ক্যাশ বই, ব্যাংক বই সহ (গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * [[ReportEngine::applySearch()]] খোঁজের শব্দটা `HAVING`-এ বসাত, `GROUP BY` থাকুক বা না থাকুক।
 * লাইভের MariaDB (`ONLY_FULL_GROUP_BY`) `GROUP BY` ছাড়া `HAVING` নেয় না: *1463 Non-grouping field
 * … is used in HAVING clause*। ⓘ সমন্বয়কারী লাইভে মেপেছেন: ৬৭টার ২৭টা ভাঙে। লোকালের MySQL ৮.৪
 * এটা মেনে নেয়, তাই কোনো টেস্ট লাল হয়নি — আর ঠিক এই ধরনটাই রিপো আগেও একবার লাইভে পেয়েছিল
 * ([[OrderTracking]], ২০ সেপ্টেম্বর)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * `GROUP BY` থাকলে আগের মতো `HAVING` (লাইভে চলে)। না থাকলে কোয়েরিটা মোড়া হয়, আর ছাঁকা হয় বাইরের
 * `WHERE`-এ; ভিতরের ক্রম `ROW_NUMBER()` দিয়ে বাইরে বয়ে আসে, তাই খুঁজলে সারির ক্রম বদলায় না।
 * ⚠️ দাবিটা কাঠামোর — লোকাল ইঞ্জিন ভুলটা ধরে না, তাই তৈরি হওয়া SQL-টাই মাপা হয়।
 */
final class TheReportSearchBrokeOnTheLiveServerTest extends TestCase
{
    use RefreshDatabase;

    /** সমন্বয়কারীর লাইভ মাপে যে ২৭টা ভেঙেছিল — প্রতিটা সত্যিই চালানো হয়েছে কি না দেখার জন্য। */
    private const BROKE_ON_LIVE = [
        'accounts.day_book', 'accounts.cash_book', 'accounts.bank_book', 'accounts.ledger', 'accounts.inflow',
        'accounts.project_ledger', 'approval.pending', 'approval.approved', 'approval.rejected', 'customer.no_limit',
        'inventory.stock_ledger', 'inventory.replenishment', 'inventory.adjustments', 'supplier.payment_schedule',
        'sales.pending_orders', 'sales.uninvoiced', 'sales.margin', 'purchase.pending_orders', 'purchase.uninvoiced',
        'purchase.match_exceptions', 'purchase.price_history', 'promotion.register', 'promotion.active',
        'promotion.expired', 'promotion.cancelled_offers', 'system_admin.notice_register',
        'system_admin.notice_signatures',
    ];

    private ReportEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->engine = app(ReportEngine::class);
    }

    /**
     * ⛔→⭐ প্রতিটা নিবন্ধিত রিপোর্ট খোঁজ দিয়ে চলে, আর কোনো কোয়েরিতে `GROUP BY` ছাড়া `HAVING` নেই।
     */
    public function test_no_report_searches_with_having_and_no_group_by(): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = strtolower($query->sql);
        });

        $ran = [];
        $offenders = [];

        foreach ($this->engine->keys() as $key) {
            $sql = [];

            try {
                $this->engine->run($key, ['q' => 'a']);
            } catch (\Throwable $e) {
                $offenders[] = "{$key}: চলেনি — ".$e->getMessage();

                continue;
            }

            $ran[] = $key;

            foreach ($sql as $statement) {
                if (str_contains($statement, ' having ') && ! str_contains($statement, ' group by ')) {
                    $offenders[] = "{$key}: {$statement}";
                    break;
                }
            }
        }

        $this->assertSame([], array_values(array_diff(self::BROKE_ON_LIVE, $ran)),
            'লাইভে ভাঙা রিপোর্টগুলোর কিছু এখানে চালানোই হয়নি — দাবিটা অন্ধ।');
        $this->assertSame([], $offenders, "⛔ GROUP BY ছাড়া HAVING — লাইভের MariaDB-তে ভাঙবে:\n".implode("\n", $offenders));
    }

    /** ⭐ `GROUP BY` ছাড়া রিপোর্ট: খুঁজলে ঠিক মেলা সারিগুলোই আসে, আর আগের ক্রমেই। */
    public function test_a_flat_report_filters_and_keeps_its_order(): void
    {
        $this->assertFiltersInOrder(['accounts.day_book', 'sales.margin', 'inventory.stock_ledger', 'accounts.ledger'], grouped: false);
    }

    /** ⭐ `GROUP BY`-সহ রিপোর্ট: খোঁজ আগের মতোই ছাঁকে। */
    public function test_a_grouped_report_still_filters(): void
    {
        $this->assertFiltersInOrder(['sales.by_customer', 'sales.by_product', 'customer.due_list', 'accounts.trial_balance', 'inventory.stock_summary', 'inventory.stock_value'], grouped: true);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ⓘ রিপোর্ট আগে থেকে বাছা হয় না — তালিকার যেটায় ডেমো সারি আছে, আর এমন একটা শব্দ যা কিছু সারিতে মেলে,
     * সবগুলোতে নয় ([[TheReportsHadNoWayToFindARowTest]]-এর শিক্ষা: নাম ধরে বাছলে একদিন খালি ডাটায় সবুজ হত)।
     *
     * @param  list<string>  $candidates
     */
    private function assertFiltersInOrder(array $candidates, bool $grouped): void
    {
        $window = ['from' => '2000-01-01', 'to' => '2030-12-31'];

        foreach ($candidates as $key) {
            $columns = $this->engine->get($key)->searchableColumns();
            $all = $this->engine->run($key, $window, perPage: 1000)->rows;

            if (count($all) < 2) {
                continue;
            }

            $needle = $this->needleFor($all, $columns);

            if ($needle === null) {
                continue;
            }

            $expected = array_values(array_filter($all, fn (array $row) => $this->rowMatches($row, $columns, $needle)));

            $sql = [];
            DB::listen(function ($query) use (&$sql) {
                $sql[] = strtolower($query->sql);
            });

            $found = $this->engine->run($key, [...$window, 'q' => $needle], perPage: 1000)->rows;

            $this->assertSame($grouped, collect($sql)->contains(fn (string $s) => str_contains($s, ' group by ')),
                "প্রস্তুতিটাই ভুল — {$key}-এর কোয়েরিতে GROUP BY থাকা/না-থাকা প্রত্যাশামতো নয়।");

            // ⓘ পুরো সারি, কেবল খোঁজার ঘর নয় — ঐ ঘরগুলো একরকম হলে উল্টো ক্রমও একই দেখাত
            // (উল্টো ক্রমের মিউট্যান্ট একবার ঠিক এভাবে বেঁচে গিয়েছিল)
            $this->assertGreaterThan(1, count(array_unique(array_map('serialize', $expected))),
                "প্রস্তুতিটাই ভুল — {$key}: মেলা সারিগুলো সব হুবহু এক, ক্রম মাপা যেত না।");

            $this->assertSame($expected, $found, "⛔ {$key}: \"{$needle}\" খুঁজে সারিগুলো বা তাদের ক্রম মেলেনি।");

            return;
        }

        $this->fail('প্রস্তুতিটাই ভুল — তালিকার কোনো রিপোর্টে খোঁজার মতো ডেমো সারি নেই: '.implode(', ', $candidates));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function needleFor(array $rows, array $columns): ?string
    {
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = trim((string) ($row[$column] ?? ''));

                if (mb_strlen($value) < 3) {
                    continue;
                }

                $needle = mb_substr($value, 0, 6);
                $hits = count(array_filter($rows, fn (array $r) => $this->rowMatches($r, $columns, $needle)));

                // ⓘ অন্তত দুইটা মেলে — নাহলে ক্রমের দাবি কিছুই মাপত না (উল্টো ক্রমের মিউট্যান্ট বেঁচে গিয়েছিল)
                if ($hits >= 2 && $hits < count($rows)) {
                    return $needle;
                }
            }
        }

        return null;
    }

    /** @param  list<string>  $columns */
    private function rowMatches(array $row, array $columns, string $needle): bool
    {
        foreach ($columns as $column) {
            if (mb_stripos((string) ($row[$column] ?? ''), $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
