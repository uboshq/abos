<?php

declare(strict_types=1);

namespace App\Modules\Sales\Imports;

use App\Core\Contracts\Importer;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CustomerTargetService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ডিলারের মাসিক লক্ষ্য একসাথে অনেকের — ঘর: কোড, মাস (2026-10), লক্ষ্য, শেষ তারিখ (ফাঁকা হলে মাসের শেষ দিন)।
 *
 * ⓘ পাতার একই পথ আর একই যাচাই ([[CustomerTargetService::setOne()]]) — ইমপোর্টের আলাদা নিয়ম নেই।
 */
final class CustomerTargetImporter implements \App\Core\Contracts\ImportNeedsKeys, Importer
{
    /** ⛔ এই ইমপোর্টের চাবি — পর্দার একই কাজের (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, SystemAdmin ⛔৫; [[ImportNeedsKeys]]) */
    public static function requiredPermissions(): array
    {
        return ['sales.customer_target.manage'];
    }

    public function __construct(private readonly CustomerTargetService $targets) {}

    public static function label(): string
    {
        return 'sales::customer_target.import';
    }

    /** @return array<string, array{label: string, required: bool}> */
    public static function columns(): array
    {
        return [
            'code' => ['label' => 'sales::customer_target.code', 'required' => true],
            'month' => ['label' => 'sales::customer_target.month', 'required' => true],
            'amount' => ['label' => 'sales::customer_target.target', 'required' => true],
            'closes_on' => ['label' => 'sales::customer_target.closes_on', 'required' => false],
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function check(array $row): array
    {
        $errors = [];

        if ($this->customer($row) === null) {
            $errors[] = __('sales::customer_target.no_such_dealer', ['code' => (string) ($row['code'] ?? '')]);
        }

        $month = $this->month($row);

        if ($month === null) {
            $errors[] = __('sales::customer_target.month_invalid');
        }

        $amount = trim((string) ($row['amount'] ?? ''));

        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 4) < 0) {
            $errors[] = __('sales::customer_target.amount_invalid');
        }

        $closes = trim((string) ($row['closes_on'] ?? ''));

        if ($closes !== '' && $month !== null) {
            try {
                if (! Carbon::parse($closes)->isSameMonth($month)) {
                    $errors[] = __('sales::customer_target.closes_in_month');
                }
            } catch (\Throwable) {
                $errors[] = __('sales::customer_target.closes_in_month');
            }
        }

        return $errors;
    }

    /** @param  array<string, string>  $row */
    public function import(array $row): void
    {
        $customer = $this->customer($row);
        $month = $this->month($row);

        if ($customer === null || $month === null) {
            throw ValidationException::withMessages(['code' => __('sales::customer_target.no_such_dealer', ['code' => (string) ($row['code'] ?? '')])]);
        }

        $this->targets->setOne((int) $customer->id, $month, $row['amount'] ?? null, $row['closes_on'] ?? null);
    }

    /** @param  array<string, string>  $row */
    private function customer(array $row): ?Customer
    {
        $code = trim((string) ($row['code'] ?? ''));

        return $code === '' ? null : Customer::query()->where('code', $code)->first();
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
