<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের সারির তালিকা — পণ্য, ভ্যাটের কোড, খাত (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ Accounts কারও উপর দাঁড়ায় না (`depends_on` কেবল ব্যাকআপ) — তাই পণ্য আর করের টেবিল সরাসরি পড়া হয়, মডেল দিয়ে নয়
 * ([[DayChecklist]]-এর একই পথ)। মজুদ বা মাস্টার-ডাটা মডিউল না থাকলে টেবিলও নেই — তখন তালিকা খালি, খাতের সারি চলে।
 */
final class TradeCatalog
{
    /** একটা পর্দায় পণ্যের তালিকা যত লম্বা হতে পারে */
    private const PRODUCT_LIMIT = 3000;

    /**
     * এই কোম্পানির চালু পণ্য।
     *
     * @return list<array{id: int, name: string, unit: string, sale_price: string, purchase_price: string, tax_id: int|null, vat_rate: string}>
     */
    public function products(): array
    {
        if (! Schema::hasTable('inv_products')) {
            return [];
        }

        return $this->productQuery()->orderBy('p.name_en')->limit(self::PRODUCT_LIMIT)->get()
            ->map(fn ($row) => $this->productRow($row))->values()->all();
    }

    /** @return array{id: int, name: string, unit: string, sale_price: string, purchase_price: string, tax_id: int|null, vat_rate: string}|null */
    public function product(int $id): ?array
    {
        if ($id <= 0 || ! Schema::hasTable('inv_products')) {
            return null;
        }

        $row = $this->productQuery()->where('p.id', $id)->first();

        return $row === null ? null : $this->productRow($row);
    }

    /**
     * ভ্যাটের কোড — মূসক ১৫%, ১০%, ৭.৫%, ৫%, মুক্ত।
     *
     * @return list<array{id: int, code: string, name: string, rate: string}>
     */
    public function taxes(): array
    {
        if (! Schema::hasTable('mdm_taxes')) {
            return [];
        }

        $bn = app()->getLocale() === 'bn';

        return DB::table('mdm_taxes')
            ->where('company_id', CompanyContext::id())
            ->where('is_active', true)
            ->where('kind', 'vat')
            ->when(Schema::hasColumn('mdm_taxes', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderByDesc('rate')
            ->get(['id', 'code', 'name_en', 'name_bn', 'rate'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'code' => (string) $t->code,
                'name' => (string) ($bn && filled($t->name_bn) ? $t->name_bn : $t->name_en),
                'rate' => self::rate((string) $t->rate),
            ])->values()->all();
    }

    /** ভ্যাটের কোডের হার, শতাংশে — কোড না পেলে `null` */
    public function taxRate(?int $taxId): ?string
    {
        if ($taxId === null || $taxId <= 0) {
            return null;
        }

        $found = collect($this->taxes())->firstWhere('id', $taxId);

        return $found === null ? null : (string) $found['rate'];
    }

    /**
     * খাতের তালিকা — সারির "খাত" ধরনে যা বাছা যায়।
     *
     * @param  list<string>  $types  Account::INCOME, Account::EXPENSE …
     * @param  list<string>  $extraCodes  ধরনের বাইরে যে খাতগুলো চলে (ড্যামেজ দাবি ১১৫১, মজুদ ১১২০ …)
     * @return list<array{id: int, code: string, name: string}>
     */
    public function accounts(array $types, array $extraCodes = []): array
    {
        $chart = app(StandardChart::class);

        return Account::query()->postable()
            ->where(fn ($q) => $q->whereIn('type', $types)->when($extraCodes !== [], fn ($w) => $w->orWhereIn('code', $extraCodes)))
            ->orderBy('code')
            ->get()
            ->filter(fn (Account $a) => ! in_array((string) $a->code, $extraCodes, true) || $chart->holdsItsMeaning($a))
            ->map(fn (Account $a) => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => $a->name()])
            ->values()->all();
    }

    /** ১৫.০০০০ → "15", ৭.৫০০০ → "7.5" */
    public static function rate(string $value): string
    {
        $value = bcadd($value === '' ? '0' : $value, '0', 4);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function productQuery(): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('inv_products as p')
            ->where('p.company_id', CompanyContext::id())
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at');

        if (Schema::hasTable('mdm_units')) {
            $query->leftJoin('mdm_units as u', 'u.id', '=', 'p.unit_id');
        }

        if (Schema::hasTable('mdm_taxes')) {
            $query->leftJoin('mdm_taxes as t', 't.id', '=', 'p.tax_id');
        }

        return $query->select(array_filter([
            'p.id', 'p.name_en', 'p.name_bn', 'p.sale_price', 'p.purchase_price', 'p.tax_id',
            Schema::hasTable('mdm_units') ? 'u.name_en as unit_en' : null,
            Schema::hasTable('mdm_units') ? 'u.name_bn as unit_bn' : null,
            Schema::hasTable('mdm_taxes') ? 't.rate as tax_rate' : null,
        ]));
    }

    /** @return array{id: int, name: string, unit: string, sale_price: string, purchase_price: string, tax_id: int|null, vat_rate: string} */
    private function productRow(object $row): array
    {
        $bn = app()->getLocale() === 'bn';

        return [
            'id' => (int) $row->id,
            'name' => (string) ($bn && filled($row->name_bn ?? null) ? $row->name_bn : $row->name_en),
            'unit' => (string) ($bn && filled($row->unit_bn ?? null) ? $row->unit_bn : ($row->unit_en ?? '')),
            'sale_price' => bcadd((string) ($row->sale_price ?? '0'), '0', 4),
            'purchase_price' => bcadd((string) ($row->purchase_price ?? '0'), '0', 4),
            'tax_id' => $row->tax_id === null ? null : (int) $row->tax_id,
            'vat_rate' => self::rate((string) ($row->tax_rate ?? '0')),
        ];
    }
}
