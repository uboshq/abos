<?php

declare(strict_types=1);

namespace App\Modules\Sales\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\ProductTargetService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * পণ্যের মাসিক লক্ষ্য একসাথে অনেকের — ঘর: পণ্যের কোড, মাস (2026-12), লক্ষ্যের পরিমাণ, লক্ষ্যের টাকা (যেকোনো একটা থাকলেই চলে)।
 *
 * ⓘ পাতার একই পথ আর একই যাচাই ([[ProductTargetService::setOne()]]) — ইমপোর্টের আলাদা নিয়ম নেই ([[CustomerTargetImporter]]-এর ছাঁচ)।
 */
final class ProductTargetImporter implements \App\Core\Contracts\ImportNeedsKeys, Importer
{
    /** ⛔ এই ইমপোর্টের চাবি — পর্দার একই কাজের */
    public static function requiredPermissions(): array
    {
        return ['sales.target.manage'];
    }

    public function __construct(private readonly ProductTargetService $targets) {}

    public static function label(): string
    {
        return 'sales::product_target.import';
    }

    /** @return array<string, array{label: string, required: bool}> */
    public static function columns(): array
    {
        return [
            'code' => ['label' => 'sales::product_target.code', 'required' => true],
            'month' => ['label' => 'sales::product_target.month', 'required' => true],
            'qty' => ['label' => 'sales::product_target.target_qty', 'required' => false],
            'amount' => ['label' => 'sales::product_target.target_amount', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if ($this->product($row) === null) {
            $errors[] = __('sales::product_target.no_such_product', ['code' => (string) ($row['code'] ?? '')]);
        }

        if ($this->month($row) === null) {
            $errors[] = __('sales::product_target.month_invalid');
        }

        foreach (['qty', 'amount'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '' && (! is_numeric($value) || bccomp($value, '0', 4) < 0)) {
                $errors[] = __('sales::product_target.number_invalid');

                break;
            }
        }

        if (trim((string) ($row['qty'] ?? '')) === '' && trim((string) ($row['amount'] ?? '')) === '') {
            $errors[] = __('sales::product_target.nothing_to_set');
        }

        return $errors;
    }

    /** @param  array<string, string>  $row */
    public function import(array $row): void
    {
        $product = $this->product($row);
        $month = $this->month($row);

        if ($product === null || $month === null) {
            throw ValidationException::withMessages(['code' => __('sales::product_target.no_such_product', ['code' => (string) ($row['code'] ?? '')])]);
        }

        $this->targets->setOne((int) $product->id, $month, $row['qty'] ?? null, $row['amount'] ?? null);
    }

    /** @param  array<string, string>  $row */
    private function product(array $row): ?Product
    {
        $code = trim((string) ($row['code'] ?? ''));

        return $code === '' ? null : Product::query()->where('code', $code)->first();
    }

    /** @param  array<string, string>  $row */
    private function month(array $row): ?Carbon
    {
        $raw = trim((string) ($row['month'] ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse(strlen($raw) === 7 ? $raw.'-01' : $raw)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
