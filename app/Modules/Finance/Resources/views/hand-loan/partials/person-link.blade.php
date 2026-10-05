{{--
    ⭐ নাম চাপলে তাঁর হাতধারের খাতা — গ্রাহকের খাতার মতো (মালিক, ৫ অক্টোবর ২০২৬; [[FinanceReportController]]),
    শুরু থেকে আজ পর্যন্ত। ⓘ যাঁর কোনো হাতধার নেই তাঁর খাতাও খোলে — খোলা জের শূন্য, আর সেখান থেকেই প্রথম দেওয়া/নেওয়া।
--}}
<a href="{{ route('finance.report.show', ['slug' => 'hand-loan-book', 'person_id' => $row['person']->id, 'from' => \App\Core\Engines\Report\ReportEngine::ALL_TIME]) }}"
   data-hand-loan-book="{{ $row['person']->id }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $row['person']->name() }}</a>
