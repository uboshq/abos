{{-- ডকুমেন্টের নম্বর, তার নিজের পাতায় ক্লিকযোগ্য — নিয়ম ১। --}}
<a href="{{ route($route, $document) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ $document->document_no }}
</a>
