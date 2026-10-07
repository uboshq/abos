{{-- কোন রেকর্ড — নম্বর বা নাম, আর কোন মডিউলের।

     নম্বরটা ঘটনার সময়ের কপি, তাই রেকর্ডটা মুছে গেলেও সারিটা পড়া যায়। --}}
<a href="{{ route('governance.audit.show', $trail->id) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $trail->title() }}</a>

@if ($trail->moduleLabel() !== null)
    <span class="ms-1 text-2xs text-(--color-ink-muted)">{{ $trail->moduleLabel() }}</span>
@endif
