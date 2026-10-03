<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Support\InvoiceDesigns;
use App\Modules\Sales\Support\PaperDesigns;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * "Set Invoice Information"-এর নমুনা বিল — বসানো সুইচে, বানানো তথ্যে, HTML-এ।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৩ ও ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"zate age sample dekha zay tarpor select kora zay"* — তাই সুইচ বদলে সংরক্ষণের পর কাগজটা
 * কেমন দাঁড়াল তা আসল বিল না খুলেই দেখা যায়।
 *
 * ── ⛔ কারও আসল বিল নয় ───────────────────────────────────────────────
 * ⓘ চাবি কন্ট্রোল প্যানেলের (`settings.manage`), বিক্রয় দেখার নয় — শেষ বিলটা দেখালে এই পাতা
 * গ্রাহকের নাম আর দর দেখার দ্বিতীয় দরজা হত ([[PrintControlController::preview()]]-এর একই
 * কারণ)। ⭐ ছাঁচ কিন্তু আসলটাই ([[sales::print.invoice-classic]]) — যা দেখা যায়, তাই ছাপা হয়।
 */
class InvoiceSampleController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    /**
     * ⓘ `?design=` — ছাপার নিয়ন্ত্রণে প্রতিটা নকশার নিজের নমুনা; না দিলে কোম্পানির বাছা নকশা।
     * ⛔ `standard` বা অচেনা নাম → ক্লাসিক: এই পাতা কেবল তালিকার ছাঁচ দেখায়।
     */
    public function show(Request $request): Response
    {
        /* ⓘ `?size=` — A4 · A5 · থার্মাল, প্রতিটার নিজের তালিকা ([[PaperDesigns]]) */
        $size = in_array($request->query('size'), PaperDesigns::SIZES, true) ? (string) $request->query('size') : 'a4';
        $asked = (string) $request->query('design', (string) $this->settings->get(PaperDesigns::key('invoice', $size)));
        $template = PaperDesigns::template('invoice', $size, $asked)
            ?? PaperDesigns::template('invoice', $size, PaperDesigns::codes('invoice', $size)[0] ?? null)
            ?? InvoiceDesigns::ALL[InvoiceDesigns::FALLBACK];
        $paperSize = match ($size) {
            'a5' => PaperSize::A5,
            'thermal' => PaperSize::THERMAL_80,
            default => PaperSize::A4,
        };

        $s = fn (string $key) => (string) __('sales::print.sample.'.$key, [], 'en');
        $today = DateFormat::format(now());

        $facts = [
            'bill_to' => ['name' => $s('customer'), 'point' => $s('point'), 'phone' => '01700-000000', 'address' => $s('address')],
            'transport' => ['carrier' => $s('carrier'), 'driver_name' => 'Karim', 'driver_phone' => '01800-000000', 'vehicle' => 'Truck DM-TA-11-0000',
                'delivery_date' => $today],
            'bill' => ['bill_date' => $today, 'bill_no' => 'S-0000', 'order_no' => 'SO-0000',
                'type' => __('sales::print.classic.credit', [], 'en'), 'created_by' => $s('creator')],
            'total_items' => '2',
            'total_delivery' => '16',
            'items' => [
                'rows' => [
                    ['name' => $s('item_one'), 'rate' => '1,200.00', 'qty' => '10 Ctn', 'free' => '1 Ctn', 'total_qty' => '11 Ctn', 'amount' => '12,000.00'],
                    ['name' => $s('item_two'), 'rate' => '450.00', 'qty' => '6 Bag', 'free' => '', 'total_qty' => '6 Bag', 'amount' => '2,700.00'],
                ],
                'totals' => ['qty' => '10 Ctn, 6 Bag', 'free' => '1 Ctn', 'total_qty' => '11 Ctn, 6 Bag', 'amount' => '14,700.00'],
            ],
            'sums' => ['grand_total' => '14,700.00', 'discount' => '200.00', 'vat' => '0.00', 'rounding' => '0.00',
                'net_payable' => '14,500.00', 'paid' => '5,000.00', 'invoice_due' => '9,500.00',
                'previous_due' => '3,000.00', 'outstanding' => '12,500.00'],
            'words' => $s('words'),
            // ⓘ লক্ষ্যের বাক্স — নমুনায় সবসময়, যাতে নকশাটা পুরো দেখা যায় ([[SalesPrintController::targetFacts()]])
            'target' => ['month' => now()->format("F'y"), 'target' => '300,000.00', 'achieved' => '170,000.00',
                'remaining' => '130,000.00', 'closes_on' => DateFormat::format(now()->copy()->day(25)), 'bank_days' => '16'],
            'scan_url' => route('sales.scan', '00000000-0000-7000-8000-000000000000'),
        ];

        /* ⓘ DUPLICATE ছাপসহ — সুইচটা বন্ধ করলে নমুনায় সেটা মিলিয়ে দেখা যায় */
        $doc = new PrintableDocument(
            title: __('sales::doc.invoice'),
            notice: __('core.print.duplicate_notice', ['n' => 2]),
            payments: [[
                'no' => 1, 'ref' => 'RV-0000', 'date' => $today, 'method' => $s('method'),
                'narration' => '', 'amount' => '5,000.00',
            ]],
        );

        /*
         * ⭐ `?pdf=1` — আসল ছাপা, যে PDF কাগজে যায় (মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"clic korle popup e real print
         * a4 size er ber hobe"*)। ⓘ না দিলে HTML — কার্ডের ছোট ছবির জন্য, যেটা দ্রুত আঁকে।
         */
        if ($request->boolean('pdf')) {
            $pdf = $this->print->render(
                template: $template,
                data: ['doc' => $doc, 'facts' => $facts],
                paper: $paperSize,
                profile: 'invoice',
            );

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="sample.pdf"',
            ]);
        }

        $html = $this->print->preview(
            template: $template,
            data: ['doc' => $doc, 'facts' => $facts],
            paper: $paperSize,
            profile: PrintProfile::for('invoice', $this->settings),
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
