<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Validation\ValidationException;

/**
 * হাতের ছাড়ের সীমা — সারিতে আর পুরো বিলে, শতাংশে (মালিক, ৫ অক্টোবর ২০২৬: আন্তর্জাতিক মান)।
 *
 * ⓘ ০ মানে সীমা নেই (ডিফল্ট) — আজকের আচরণ হুবহু। ⛔ সীমার উপরে বিলটাই ফেরে, সই চাওয়ার আগেই: সই দিয়ে সীমা পেরোনো
 * যায় না। ⚠️ সীমার ভিতরের ছাড়েও মালিকের সই আগের মতোই লাগে ([[SalesInvoiceService::assertDiscountApproved()]]) —
 * সীমা সইয়ের বদলে নয়, তার আগের দেয়াল।
 *
 * ⓘ কেবল মানুষের ছাড় মাপা হয় — অফারের ছাড় (`promotion_discount`) নিজের ছকে সই নিয়ে আসে, তাই বাদ।
 * সারির ভিত্তি = পরিমাণ × দর; বিলের ভিত্তি = সব সারির পরিমাণ × দর, আর বিলের ছাড় = সারির হাতের ছাড় + মাথার ছাড়।
 */
final class DiscountCap
{
    public const LINE = 'sales.discount_cap_line_percent';

    public const BILL = 'sales.discount_cap_bill_percent';

    public function __construct(private readonly SettingsService $settings) {}

    /** সারির সীমা, শতাংশে — ০ মানে নেই */
    public function line(): string
    {
        return $this->percent(self::LINE);
    }

    /** বিলের সীমা, শতাংশে — ০ মানে নেই */
    public function bill(): string
    {
        return $this->percent(self::BILL);
    }

    /**
     * ⛔ কোনো সারি বা পুরো বিল সীমা পেরোলে বিল ফেরে, বাংলায় কারণসহ।
     */
    public function assertWithin(SalesInvoice $invoice): void
    {
        $lineCap = $this->line();
        $billCap = $this->bill();

        if (bccomp($lineCap, '0', 4) <= 0 && bccomp($billCap, '0', 4) <= 0) {
            return;
        }

        $gross = '0';
        $human = '0';

        foreach ($invoice->lines()->with('product')->get() as $line) {
            $base = bcmul((string) $line->qty, (string) $line->rate, 4);
            $mine = bcsub((string) ($line->discount ?? '0'), (string) ($line->promotion_discount ?? '0'), 4);

            $gross = bcadd($gross, $base, 4);
            $human = bcadd($human, $mine, 4);

            if (bccomp($lineCap, '0', 4) > 0 && bccomp($base, '0', 4) > 0) {
                $pct = bcdiv(bcmul($mine, '100', 6), $base, 4);

                if (bccomp($pct, $lineCap, 4) > 0) {
                    throw ValidationException::withMessages([
                        'discount_cap' => __('sales::validation.discount_over_line_cap', [
                            'product' => (string) ($line->product?->name() ?? ''),
                            'given' => $this->show($pct),
                            'cap' => $this->show($lineCap),
                        ]),
                    ]);
                }
            }
        }

        if (bccomp($billCap, '0', 4) <= 0 || bccomp($gross, '0', 4) <= 0) {
            return;
        }

        $total = bcadd($human, (string) ($invoice->bill_discount ?? '0'), 4);
        $pct = bcdiv(bcmul($total, '100', 6), $gross, 4);

        if (bccomp($pct, $billCap, 4) > 0) {
            throw ValidationException::withMessages([
                'discount_cap' => __('sales::validation.discount_over_bill_cap', [
                    'given' => $this->show($pct),
                    'cap' => $this->show($billCap),
                ]),
            ]);
        }
    }

    /** কাউন্টারের লেখা — "ছাড়ের সীমা — সারিতে ৫%, পুরো বিলে ৩%"; কোনো সীমা না থাকলে null */
    public function hint(): ?string
    {
        if (bccomp($this->line(), '0', 4) <= 0 && bccomp($this->bill(), '0', 4) <= 0) {
            return null;
        }

        return __('sales::message.discount_cap_hint', ['line' => $this->show($this->line()), 'bill' => $this->show($this->bill())]);
    }

    private function percent(string $key): string
    {
        $value = (string) $this->settings->get($key, 0);

        return is_numeric($value) && bccomp($value, '0', 4) > 0 ? bcadd($value, '0', 4) : '0';
    }

    /** "৫.২৫" — পেছনের শূন্য ছাঁটা, দুই দশমিক পর্যন্ত */
    public function show(string $pct): string
    {
        return rtrim(rtrim(\App\Core\Support\Money::round($pct, 2), '0'), '.');
    }
}
