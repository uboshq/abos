<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Support\VoucherDesigns;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ভাউচারের নকশার নমুনা — ছাপার নিয়ন্ত্রণের কার্ড আর পপআপ; বানানো তথ্যে, DB ছোঁয় না।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: কার্ডে চাপলে পপআপে আসল A4 ছাপা। ⓘ বিক্রয়ের নমুনার ([[PaperSampleController]])
 * সেই একই ধাঁচ — `?design=`, `?size=a4|a5|thermal`, `?pdf=1` — আর নিজের `?type=receipt|payment|expense|journal`।
 * ⚠️ Accounts কারও উপর দাঁড়ায় না, তাই বিক্রয়ের ভাগের ক্লাস এখানে আসে না; নমুনার লেখা নিজের `voucher_sample`-এ।
 *
 * ⓘ ডেটার ঘর [[VoucherPrintController::paper()]]-এর সেই একই, আর সইয়ের ঘর তার `signatures()`-এর নিয়মে —
 * আদায়ে "দিলেন", পরিশোধ-খরচে "পেলেন", জাবেদায় কেবল প্রস্তুতকারী আর অনুমোদনকারী।
 */
class VoucherSampleController extends Controller implements HasMiddleware
{
    public const TYPES = [Voucher::RECEIPT, Voucher::PAYMENT, Voucher::EXPENSE, Voucher::JOURNAL];

    public function __construct(
        private readonly PrintEngine $print,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function show(Request $request): Response
    {
        $size = in_array($request->query('size'), VoucherDesigns::SIZES, true) ? (string) $request->query('size') : 'a4';
        $type = in_array($request->query('type'), self::TYPES, true) ? (string) $request->query('type') : Voucher::RECEIPT;
        $asked = (string) $request->query('design', (string) $this->settings->get(VoucherDesigns::key($size)));

        // ⓘ অচেনা নকশা হলে তালিকার প্রথমটা
        $template = VoucherDesigns::template($size, $asked)
            ?? VoucherDesigns::template($size, VoucherDesigns::codes($size)[0] ?? null);
        abort_if($template === null, 404);

        $paperSize = match ($size) {
            'a5' => PaperSize::A5,
            'thermal' => PaperSize::THERMAL_80,
            default => PaperSize::A4,
        };
        $data = $this->sample($type);

        if ($request->boolean('pdf')) {
            $pdf = $this->print->render(template: $template, data: $data, paper: $paperSize, profile: 'voucher');

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="sample.pdf"',
            ]);
        }

        $html = $this->print->preview(
            template: $template,
            data: $data,
            paper: $paperSize,
            profile: PrintProfile::for('voucher', $this->settings),
        );

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function sample(string $type): array
    {
        $t = fn (string $key) => (string) __('accounts::voucher_sample.'.$key);

        // [খাত, বিবরণ, ডেবিট, ক্রেডিট] — প্রতিটা ধরনের চেনা জোড়া
        [$amount, $rows] = match ($type) {
            Voucher::PAYMENT => ['35000', [[$t('payable'), '35,000.00', '0.00'], [$t('bank'), '0.00', '35,000.00']]],
            Voucher::EXPENSE => ['4000', [[$t('fuel'), '2,400.00', '0.00'], [$t('labour'), '1,600.00', '0.00'], [$t('cash'), '0.00', '4,000.00']]],
            Voucher::JOURNAL => ['12000', [[$t('depreciation'), '12,000.00', '0.00'], [$t('misc'), '0.00', '12,000.00']]],
            default => ['2000', [[$t('cash'), '2,000.00', '0.00'], [$t('receivable'), '0.00', '2,000.00']]],
        };
        $money = Money::format($amount);
        $label = (new Voucher(['type' => $type]))->typeLabel();

        $prepared = __('accounts::print.prepared_by');
        $approved = __('accounts::print.approved_by');

        return [
            'title' => $label.' '.strtoupper(substr($type, 0, 3)).'-0000',
            'voucher' => [
                'document_no' => strtoupper(substr($type, 0, 3)).'-0000',
                'date' => DateFormat::format(now()),
                'party' => '',
                'branch' => $t('branch'),
                'narration' => $t($type),
                'lines' => array_map(fn (array $r) => ['account' => $r[0], 'narration' => '', 'debit' => $r[1], 'credit' => $r[2]], $rows),
                'total_debit' => $money,
                'total_credit' => $money,
                'amount_in_words' => AmountInWords::of($amount, app()->getLocale()),
                'type' => $type,
                'type_label' => mb_strtoupper($label),
            ],
            'signatures' => match ($type) {
                Voucher::RECEIPT => [__('accounts::print.paid_by'), $prepared, $approved],
                Voucher::PAYMENT, Voucher::EXPENSE => [__('accounts::print.received_by'), $prepared, $approved],
                default => [$prepared, $approved],
            },
            'notice' => null,
        ];
    }
}
