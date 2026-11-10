{{-- ⭐ অর্থের ড্যাশবোর্ডের মাথার সারি — তহবিলের ঘর আর পাশে "আজকের খবর" (মালিকের অনুমোদিত নমুনা, ১০ নভেম্বর ২০২৬); অঙ্ক একবারই গোনা --}}
@include('finance::dashboard.fund', ['fund' => $fund])
@include('finance::dashboard.fund-news', ['news' => $news])
