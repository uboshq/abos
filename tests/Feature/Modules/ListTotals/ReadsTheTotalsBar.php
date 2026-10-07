<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\ListTotals;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\View\Components\Ui\Table;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * তালিকার নিচের যোগফলের পট্টি পড়া — মালিক, ৫ অক্টোবর ২০২৬: *"৩,০৯০টি সারি · এই পাতার মোট ৳… · আদায় ৳… · বাকি ৳…"*
 * প্রতিটা তালিকায় ([[x-ui.list-totals]])।
 *
 * ⓘ পট্টিটা আঁকে কেবল `navy` রূপ (`listfoot=totals`), তাই মালিককে ওই রূপে বসানো হয়। দাবিগুলো সবসময় ডাটাবেজের
 * **সরাসরি** গোনা/যোগের সাথে — পর্দার কোড যে কোয়েরি বানায় তার সাথে নয় (নিজের নাম নিজে দিলে ধরা পড়ে না)।
 */
trait ReadsTheTotalsBar
{
    protected User $owner;

    protected function sitTheOwnerInTheNavyLook(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'navy'])->save();
        $this->actingAs($this->owner);
    }

    /** পট্টির লেখা — ট্যাগ বাদে, ফাঁকা গুটিয়ে */
    protected function bar(string $html, string $where): string
    {
        $this->assertSame(1, preg_match('/<div data-look-listfoot\b[^>]*>(.*?)<\/div>/su', $html, $m),
            "⛔ {$where}: তালিকার নিচে যোগফলের পট্টি নেই।");

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($m[1])));
    }

    /** সারির সংখ্যা পট্টির ভাষায় — "৫১টি সারি" */
    protected function rowsText(int $count): string
    {
        return __('core.list.rows', ['count' => number_format($count)]);
    }

    protected function moneyText(string $amount): string
    {
        return Table::format($amount, 'money');
    }

    protected function quantityText(string $amount): string
    {
        return Table::format($amount, 'quantity');
    }

    /**
     * একটা তালিকা খুলে পট্টি পড়ে দাবি: সারির সংখ্যা আর প্রতিটা `লেবেল => অঙ্ক` ঠিক ডাটাবেজের সরাসরি গোনা।
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $money  পট্টির লেবেল => প্রত্যাশিত টাকা (ডাটাবেজের সরাসরি যোগ)
     */
    protected function assertBar(string $route, array $query, int $rows, array $money = [], array $quantity = []): void
    {
        $where = $route.'?'.http_build_query($query);
        $html = (string) $this->get(route($route, $query))->assertOk()->getContent();
        $bar = $this->bar($html, $where);

        $this->assertStringContainsString($this->rowsText($rows), $bar, "⛔ {$where}: পট্টির সারির সংখ্যা ডাটাবেজের {$rows} নয় — «{$bar}»");

        foreach ($money as $label => $amount) {
            $this->assertStringContainsString($label.' '.$this->moneyText($amount), $bar,
                "⛔ {$where}: পট্টির «{$label}» ডাটাবেজের সরাসরি যোগ {$amount} নয় — «{$bar}»");
        }

        foreach ($quantity as $label => $amount) {
            $this->assertStringContainsString($label.' '.$this->quantityText($amount), $bar,
                "⛔ {$where}: পট্টির «{$label}» ডাটাবেজের সরাসরি যোগ {$amount} নয় — «{$bar}»");
        }
    }

    /**
     * এক ধরনের কাগজ বসানো — `$prefix-001` …, প্রতিটায় একই অঙ্ক।
     *
     * @param  array<string, mixed>  $extra
     */
    protected function papers(string $table, string $prefix, int $count, array $extra): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table($table)->insert([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => sprintf('%s-%03d', $prefix, $i),
                'trx_date' => now()->toDateString(),
                'status' => 'confirmed',
                'created_at' => now(),
                'updated_at' => now(),
                ...$extra,
            ]);
        }
    }

    /** ডাটাবেজের সরাসরি যোগ — এই কোম্পানির, এই উপসর্গের */
    protected function dbSum(string $table, string $column, string $prefix): string
    {
        return (string) DB::table($table)->where('company_id', CompanyContext::id())
            ->where('document_no', 'like', $prefix.'-%')->sum($column);
    }

    protected function dbCount(string $table, string $prefix): int
    {
        return DB::table($table)->where('company_id', CompanyContext::id())
            ->where('document_no', 'like', $prefix.'-%')->count();
    }
}
