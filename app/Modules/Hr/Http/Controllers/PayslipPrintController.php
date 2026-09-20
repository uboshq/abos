<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * বেতনশিট ছাপা — একজনের, বা পুরো রানের।
 *
 * ── কেন পুরো রানেরটাও একটা ঠিকানায় ──────────────────────────────────
 * বিশ জন কর্মীর শিট এক-এক করে ছাপতে বিশবার ক্লিক লাগত, আর একটা বাদ
 * পড়লে সেটা কেউ ধরত না। এক ফাইলে সব — প্রতিটা শিট নিজের পাতায়।
 */
class PayslipPrintController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PrintEngine $print,

        // কোন কাগজে ছাপা হবে — মালিকের বসানো মাপ
        private readonly SettingsService $settings,

        // ছাপা · নামানো · পাঠানো · খোলা — সব কাগজের এক হিসাব
        private readonly PaperTrail $trail,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:hr.payroll.view')];
    }

    public function one(Request $request, Payslip $payslip): Response
    {
        // ⓘ পাতাটা যায় তার রানের শাখা ধরে — [[PayslipPolicy]]
        $this->authorize('view', $payslip);

        $payslip->load(['employee.department', 'employee.designation', 'lines', 'run']);

        return $this->pdf(
            $request,
            [[$this->documentFor($payslip), (string) $payslip->net]],
            $payslip->run->document_no.'-'.$payslip->employee?->code,
        );
    }

    public function all(Request $request, PayrollRun $run): Response
    {
        $run->load(['payslips.employee.department', 'payslips.employee.designation', 'payslips.lines']);

        $documents = $run->payslips
            ->sortBy(fn (Payslip $slip) => $slip->employee?->code)
            ->map(fn (Payslip $slip) => [$this->documentFor($slip), (string) $slip->net])
            ->values()
            ->all();

        abort_if($documents === [], 404);

        return $this->pdf($request, $documents, $run->document_no);
    }

    /**
     * একটা শিটকে ছাপার আকারে অনুবাদ।
     *
     * অঙ্কগুলো শিট থেকেই আসে, কাঠামো থেকে নয়: কাগজটা যা দেওয়া হয়েছিল
     * তা-ই দেখাবে, আজ কাঠামো যা বলছে তা নয়।
     */
    private function documentFor(Payslip $slip): PrintableDocument
    {
        $employee = $slip->employee;
        $run = $slip->run;

        $lines = [];

        foreach ($slip->lines as $line) {
            $lines[] = [
                'name' => $line->headName().($line->isEarning() ? '' : ' ('.__('hr::kind.deduction').')'),
                'qty' => '',
                'unit' => '',
                'rate' => '',
                'amount' => Money::format($line->amount),
            ];
        }

        return new PrintableDocument(
            title: __('hr::doc.payslip'),
            meta: array_filter([
                __('hr::field.code') => (string) $employee?->code,
                __('hr::field.name') => (string) $employee?->name(),
                __('hr::field.designation') => (string) $employee?->designation?->name(),
                __('hr::field.department') => (string) $employee?->department?->name(),
                __('hr::field.month') => $run->month->format('F Y'),
                __('hr::field.payment_method') => __('hr::kind.'.$slip->payment_method),
                __('hr::field.bank_account_no') => (string) $slip->bank_account_no,
            ], fn (string $value) => $value !== ''),
            totals: [
                __('hr::field.gross') => Money::format($slip->gross),
                __('hr::field.deductions') => Money::format($slip->deductions),
                __('hr::field.net') => Money::format($slip->net),
            ],

            /*
             * দুইটা সই — যিনি দিলেন আর যিনি পেলেন।
             *
             * নগদে বেতন দেওয়ার দিনে এই কাগজটাই প্রমাণ, তাই প্রাপকের সই
             * ছাড়া কাগজটা অসম্পূর্ণ।
             */
            signatures: [__('hr::field.paid_by'), __('hr::field.received_by')],
            lines: $lines,

            // খসড়া রানের শিটে বড় করে লেখা — নাহলে কেউ ওটা নিয়ে টাকা চাইতে পারে
            notice: $run->isDraft() ? __('hr::message.draft_payslip') : null,
        );
    }

    /**
     * @param  list<array{0: PrintableDocument, 1: string}>  $documents  কাগজ ও তার কাঁচা নিট অঙ্ক
     */
    private function pdf(Request $request, array $documents, string $documentNo): Response
    {
        /*
         * ⭐ কাগজের মাপ মালিকের বসানো, হাতে লেখা A4 নয় (২০ সেপ্টেম্বর ২০২৬)।
         * ⓘ ঠিকানায় চাওয়া মাপ আগে, তারপর সেটিং — কারণ [[PaperSize::chosen()]]-এ।
         */
        $paper = PaperSize::chosen($request->query('paper'), $this->settings->get('hr.print.paper.payslip'));

        $locale = app()->getLocale();

        $pdf = $this->print->render(
            template: 'hr::print.payslips',
            data: [
                /*
                 * কথায় বসানোর জন্য কাঁচা অঙ্কটা আলাদা করে বয়ে আনা হয়।
                 *
                 * totals-এর অঙ্কগুলো ছাপার জন্য সাজানো ("২৯,৫০০.০০"), আর
                 * কমাসহ সেই লেখাটা bcmath-এ দিলে সেটা সংখ্যাই নয় — পুরো
                 * কাগজটা ৫০০ দিত।
                 */
                'documents' => array_map(
                    fn (array $pair) => $pair[0]->withWordsFor($pair[1], $locale),
                    $documents,
                ),
                'title' => __('hr::doc.payslip').' '.$documentNo,
            ],
            paper: $paper,
        );

        /*
         * ⭐ কাগজটা বেরোল — ছাপা হয়ে, নাকি ফাইল হয়ে (২০ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ মালিকের চাওয়া: *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল"*। ⚠️ দুইটা
         * আলাদা গোনা হয়, কারণ "ছেপে দিয়েছি" আর "ফাইল পাঠিয়েছি" এক কথা নয়।
         * ⛔ ফাইলটা আলাদা করে আঁকা হয় না — উপরের `$pdf`-ই নামে।
         */
        $asFile = $request->boolean('download');

        $this->trail->record(
            'hr_payslip', (int) $id, $paper,
            $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED,
            $documentNo,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$documentNo.'.pdf"',
        ]);
    }
}
