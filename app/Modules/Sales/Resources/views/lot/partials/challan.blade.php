{{-- চালানের নম্বরটা ক্লিকযোগ্য — নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে
     নিয়ে যায়। --}}
<a href="{{ route('sales.challan.show', ['challan' => $row->challan_id]) }}"
   class="text-(--color-brand-700) hover:underline">{{ $row->document_no }}</a>
