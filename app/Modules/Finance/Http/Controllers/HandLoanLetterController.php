<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Reports\HandLoanReports;
use App\Modules\MasterData\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ জের নিশ্চিতকরণের চিঠি — অর্থ-মডিউলের পরিকল্পনা ১.৯, ৫ অক্টোবর ২০২৬: *"ব্যক্তিকে ছাপা চিঠি: 'আপনার কাছে ৳… বাকি, ঠিক
 * থাকলে সই দিন'"*।
 *
 * ⓘ অঙ্কটা হাতধারের রিপোর্টগুলোর একই উৎস থেকে ([[HandLoanReports::rows()]]) — চলাচল আর হাতধার খাতে তাঁর নামের সারি,
 * চিঠির তারিখ পর্যন্ত। তাই চিঠি, পাওনা তালিকা আর তাঁর খাতার শেষ জের একই সংখ্যা বলে। ধনাত্মক মানে তিনি দেবেন, ঋণাত্মক
 * মানে আমরা দেব — চিঠির বাক্যও সেই মতো বদলায়। ⓘ কাগজ ঘরের ছাপার যন্ত্রে ([[PrintEngine]]): A4, A5; PDF নামানো যায়।
 */
final class HandLoanLetterController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintEngine $print) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.hand_loan.view')];
    }

    public function __invoke(Request $request, Person $person): Response
    {
        $asOf = Carbon::parse((string) ($request->query('as_of') ?: Carbon::today()->toDateString()))->toDateString();
        $paper = PaperSize::chosen($request->query('paper'), PaperSize::A4);
        $asFile = $request->boolean('download');

        $balance = bcadd((string) HandLoanReports::rows(['company_id' => CompanyContext::id()], wall: false)
            ->where('u.person_id', $person->id)
            ->where('u.trx_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(u.debit), 0) - COALESCE(SUM(u.credit), 0) as n')
            ->value('n'), '0', 4);

        $side = bccomp($balance, '0', 4);
        $amount = $side < 0 ? bcmul($balance, '-1', 4) : $balance;
        $words = ['date' => DateFormat::format($asOf), 'amount' => Money::format($amount)];

        $pdf = $this->print->render(
            template: 'finance::hand-loan.print.letter',
            data: [
                'title' => __('finance::hand_loan_letter.title').' — '.$person->name(),
                'letter' => [
                    'date' => DateFormat::format(Carbon::today()),
                    'name' => $person->name(),
                    'code' => (string) $person->code,
                    'address' => (string) ($person->address ?? ''),
                    'mobile' => (string) ($person->mobile ?? ''),
                    'side' => $side,
                    'amount' => Money::format($amount),
                    'in_words' => AmountInWords::of($amount, app()->getLocale()),
                    'line' => (string) __(match (true) {
                        $side > 0 => 'finance::hand_loan_letter.they_owe',
                        $side < 0 => 'finance::hand_loan_letter.we_owe',
                        default => 'finance::hand_loan_letter.nothing',
                    }, $words),
                ],
            ],
            paper: $paper,
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($asFile ? 'attachment' : 'inline').'; filename="hand-loan-'.$person->code.'-'.$asOf.'.pdf"',
        ]);
    }
}
