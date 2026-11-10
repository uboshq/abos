<?php

declare(strict_types=1);

namespace App\Modules\Sales\Forecasts;

use App\Core\Engines\Forecast\ForecastSource;
use App\Core\Support\CompanyContext;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ⭐ দিনে নিট বিক্রির ইতিহাস — পূর্বাভাসের ইঞ্জিনের জন্য ([[ForecastEngine]])।
 *
 * ⓘ প্রতিটা দিনের সংখ্যা বিক্রয়ের নিজের নিট বিক্রি ([[SalesMetrics::net()]] — VAT আর ফেরত বাদে) — হোম, ড্যাশবোর্ড আর ফোনের একই
 *   সংখ্যা; দ্বিতীয় সংজ্ঞা নেই। ⓘ পেছনের দিন বদলায় কম, তাই এক ঘণ্টা মনে রাখা — মানুষ (দেয়াল), কোম্পানি আর শাখা ধরে।
 */
final class NetSalesHistory implements ForecastSource
{
    public static function key(): string
    {
        return 'sales.net';
    }

    public static function permission(): string
    {
        return 'sales.invoice.view';
    }

    public function history(string $from, string $to, array $args = []): array
    {
        $key = implode(':', ['forecast', self::key(), CompanyContext::id(), (int) CompanyContext::branchId(), (int) auth()->id(), $from, $to]);

        return Cache::remember($key, 3600, function () use ($from, $to): array {
            $out = [];

            for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
                $out[$d->toDateString()] = SalesMetrics::net($d->toDateString(), $d->toDateString());
            }

            return $out;
        });
    }
}
