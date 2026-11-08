<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Print\PrintEngine;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\DailySummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ প্রতিদিনের সারাংশ — পর্দা আর A4 আড়াআড়ি PDF (মালিক, ৭ নভেম্বর ২০২৬, নমুনা "ok"; [[DailySummary]])।
 * ⓘ `?date=` দিন বাছা (ডিফল্ট আজ, ভবিষ্যৎ নয়); `?pdf=1` ছাপার কাগজ — রাতের ইমেইলের সংযুক্তিও এই একই কাগজ ([[DailySummaryMail]])।
 */
final class DailySummaryController extends Controller
{
    public function show(Request $request, DailySummary $summary, PrintEngine $print): Response
    {
        $day = $this->day((string) $request->query('date', ''));
        $data = $summary->for($day);

        if ($request->boolean('pdf')) {
            $pdf = $print->render(template: 'print.daily-summary', data: ['s' => $data], paper: \App\Core\Engines\Print\PaperSize::A4_LANDSCAPE);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="daily-summary-'.$day->toDateString().'.pdf"',
            ]);
        }

        return response()->view('executive::daily-summary', [
            'menu' => app(\App\Core\Services\MenuBuilder::class)->forUser($request->user()),
            's' => $data,
            'prevUrl' => route('executive.daily_summary', ['date' => $day->copy()->subDay()->toDateString()]),
            'nextUrl' => $day->isToday() ? null : route('executive.daily_summary', ['date' => $day->copy()->addDay()->toDateString()]),
            'pdfUrl' => route('executive.daily_summary', ['date' => $day->toDateString(), 'pdf' => 1]),
        ]);
    }

    /** বাছা দিন — ভুল বা ভবিষ্যতের তারিখে আজ */
    private function day(string $asked): Carbon
    {
        try {
            $day = $asked !== '' ? Carbon::parse($asked)->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            $day = Carbon::today();
        }

        return $day->isFuture() ? Carbon::today() : $day;
    }
}
