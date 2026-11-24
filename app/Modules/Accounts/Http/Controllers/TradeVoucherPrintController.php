<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\BranchSettings;
use App\Core\Services\PaperTrail;
use App\Core\Support\AmountInWords;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Models\TradeVoucherLine;
use App\Modules\Accounts\Support\PaperTaxFacts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ স্টক ছাড়া ভাউচারের ছাপা — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।
 *
 *   SV  (বিক্রয় ভাউচার) — মূসক-৬.৩ কর চালানপত্রের ধাঁচে: কোম্পানি + BIN, গ্রাহক + BIN, নম্বর, তারিখ ও সময়, রেফ/পিও, শোধের শেষ
 *       তারিখ, সারি ভ্যাটসহ, মোট, কথায়, সই। ⛔ কোম্পানির BIN খালি হলে ছাপা থামে না — BIN-এর ঘরে সতর্কবার্তা।
 *   PUV (ক্রয় ভাউচার) — সাধারণ কাগজ: সরবরাহকারী, তাঁর বিল নম্বর, মূসক-৬.৩ নম্বর/তারিখ, সারি, ভ্যাট, উৎসে কর, দেনা।
 *
 * ⓘ ছাঁচ [[NotePrintController]]-এর হুবহু — [[PrintEngine]], মাপ শাখার সেটিং (`accounts.print.paper.voucher`) বা `?paper=`,
 * শাখার সেটিংয়ে ছাপা, [[PaperTrail]]-এ গোনা আর দ্বিতীয়বার থেকে DUPLICATE, বাতিলে জলছাপ।
 */
final class TradeVoucherPrintController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,
        private readonly BranchSettings $branch,
        private readonly PaperTrail $trail,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report')];
    }

    public function __invoke(Request $request, string $kind, TradeVoucher $voucher): Response
    {
        // ⛔ ঠিকানার ধরন আর কাগজের ধরন এক — নাহলে SV-র নম্বরে PUV-র ঠিকানা খুলত
        abort_unless($voucher->kind === $kind, 404);

        return $this->branch->during($voucher->branch_id, function () use ($request, $voucher) {
            $paper = PaperSize::chosen(PaperSize::fromQuery($request), $this->branch->get('accounts.print.paper.voucher'));
            $asFile = $request->boolean('download');

            $printed = $this->trail->countsFor('accounts_trade_voucher', (int) $voucher->id)[DocumentDelivery::PRINTED] ?? 0;

            $notice = match (true) {
                $voucher->isCancelled() => (string) __('accounts::print.cancelled'),
                $printed > 0 && ! $asFile => (string) __('core.print.duplicate_notice', ['n' => $printed + 1]),
                default => null,
            };

            $docName = (string) __('accounts::trade.doc_'.$voucher->kind);

            $pdf = $this->print->render(
                template: 'print.trade-voucher',
                data: [
                    'title' => $docName.' '.$voucher->document_no,
                    'voucher' => $this->paper($voucher),
                    'notice' => $notice,
                    'signatures' => $voucher->isSale()
                        ? [
                            (string) __('accounts::print.prepared_by'),
                            (string) __('accounts::note_print.officer'),
                            (string) __('accounts::note_print.recipient_sign'),
                        ]
                        : [
                            (string) __('accounts::print.prepared_by'),
                            (string) __('accounts::print.approved_by'),
                            (string) __('accounts::print.received_by'),
                        ],
                    'footnote' => '',
                ],
                paper: $paper,
                watermark: $voucher->isCancelled() ? (string) __('core.print.cancelled_watermark') : null,
            );

            $this->trail->record(
                'accounts_trade_voucher', (int) $voucher->id, $paper,
                $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
                (string) $voucher->document_no,
            );

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$voucher->document_no.'.pdf"',
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function paper(TradeVoucher $voucher): array
    {
        $taxFacts = app(PaperTaxFacts::class);
        $party = $taxFacts->party((string) $voucher->party_type, (int) $voucher->party_id);
        $company = $taxFacts->company((int) $voucher->company_id);
        $lines = $voucher->lines()->with('account')->get();
        $tds = (string) ($voucher->tds_amount ?? '0');

        return [
            'kind' => (string) $voucher->kind,
            'is_sale' => $voucher->isSale(),
            'doc_name' => (string) __('accounts::trade.doc_'.$voucher->kind),
            'document_no' => (string) $voucher->document_no,
            'date' => DateFormat::format($voucher->trx_date),
            'issued_at' => PaperTaxFacts::issuedAt($voucher),
            'due_on' => DateFormat::format($voucher->due_on),
            'ref_no' => (string) ($voucher->ref_no ?? ''),
            'mushak_no' => (string) ($voucher->mushak_no ?? ''),
            'mushak_date' => DateFormat::format($voucher->mushak_date),
            'party_name' => $party['name'],
            'party_code' => $party['code'],
            'party_address' => $party['address'],
            'party_phone' => $party['phone'],
            'party_bin' => $party['bin'],
            'company_bin' => $company['bin'],
            'narration' => (string) ($voucher->narration ?? ''),
            'lines' => $lines->map(fn (TradeVoucherLine $l) => PaperTaxFacts::line($l, $voucher->isSale() || \App\Modules\Accounts\Support\TradeCatalog::seesCost()))->all(),
            'amount' => Money::format($voucher->amount),
            'tax' => Money::format($voucher->tax_amount),
            'total' => Money::format($voucher->total),
            'tds' => bccomp($tds, '0', 4) > 0 ? Money::format($tds) : '',
            'party_total' => Money::format($voucher->party_total),
            // ⓘ কথায় — SV-তে বিলের মোট (গ্রাহক যা দেবেন), PUV-তে দেনা (উৎসে কর বাদে)
            'in_words' => AmountInWords::of((string) ($voucher->isSale() ? $voucher->total : $voucher->party_total), app()->getLocale()),
        ];
    }
}
