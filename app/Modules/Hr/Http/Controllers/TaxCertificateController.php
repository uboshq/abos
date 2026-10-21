<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Services\PaperTrail;
use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\SalaryTax;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ বছরের কর-সনদ — বেতন থেকে উৎসে কাটা করের সনদ, আয়ের বছর (জুলাই–জুন) ধরে, মাসে মাসে বেতন আর কর (আয়কর আইন ২০২৩ ধারা ১৪৫-এর
 * সনদ; a6, ২১ অক্টোবর ২০২৬; [[SalaryTax::certificate()]])।
 *
 * ⛔ টাকার কাগজ — বেতনের চাবি আর কর্মীর নাগাল (`viewSalary`)। e-TIN পরিচয়ের চাবি ছাড়া ঢাকা ([[FieldSecurity::show()]])।
 * ⓘ অঙ্ক বেতনের স্লিপ থেকে, তাই সনদের যোগ = সেই বছরের স্লিপগুলোর যোগ।
 */
class TaxCertificateController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SalaryTax $tax,
        private readonly PrintEngine $print,
        private readonly SettingsService $settings,
        private readonly PaperTrail $trail,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:hr.salary.view')];
    }

    public function show(Request $request, Employee $employee): Response
    {
        $this->authorize('viewSalary', $employee);

        $start = preg_match('/^\d{4}$/', (string) $request->query('year'))
            ? (int) $request->query('year')
            : SalaryTax::incomeYearOf(Carbon::today()->subMonthNoOverflow());

        $document = $this->documentFor($employee, $start);
        $documentNo = 'TDS-'.$employee->code.'-'.$start.'-'.($start + 1);

        app(\App\Modules\Hr\Services\SensitiveViews::class)->seen($employee, 'salary_viewed', $employee);

        $paper = PaperSize::chosen(PaperSize::fromQuery($request), $this->settings->get('hr.print.paper.payslip'));
        $pdf = $this->print->render(
            template: 'hr::print.payslips',
            data: ['documents' => [$document], 'title' => __('hr::doc.tax_certificate').' '.$documentNo],
            paper: $paper,
        );

        $asFile = $request->boolean('download');
        $this->trail->record('hr_tax_certificate', (int) $employee->id, $paper,
            $asFile ? DocumentDelivery::DOWNLOADED : DocumentDelivery::PRINTED, $documentNo);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="'.$documentNo.'.pdf"',
        ]);
    }

    /** ⓘ সারি = একটা রানের স্লিপ: "মাস · রান — বেতন", অঙ্কের ঘরে উৎসে কাটা কর */
    private function documentFor(Employee $employee, int $start): PrintableDocument
    {
        $data = $this->tax->certificate($employee, $start);
        $lines = [];

        foreach ($data['rows'] as $row) {
            $lines[] = [
                'name' => $row['month']->locale(app()->getLocale())->translatedFormat('F Y').' · '.$row['run_no']
                    .' — '.__('hr::field.gross').' '.Money::format($row['gross']),
                'qty' => '',
                'unit' => '',
                'rate' => '',
                'amount' => Money::format($row['tds']),
            ];
        }

        return new PrintableDocument(
            title: __('hr::doc.tax_certificate'),
            meta: array_filter([
                __('hr::field.code') => (string) $employee->code,
                __('hr::field.name') => (string) $employee->name(),
                __('hr::field.e_tin') => blank($employee->e_tin) ? '' : (string) FieldSecurity::show($employee, 'e_tin', (string) $employee->e_tin),
                __('hr::field.income_year') => $start.'-'.($start + 1),
            ], fn (string $value) => $value !== ''),
            lines: $lines,
            totals: [
                __('hr::field.gross') => Money::format($data['gross']),
                __('hr::field.tds_total') => Money::format($data['tds']),
            ],
            signatures: [__('hr::field.employer_signature')],
        );
    }
}
