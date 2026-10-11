{{-- ⓘ একটাই ফর্ম — পাতার প্রতিটা চাপা-যায় ঘর এর বোতাম (form="executive-open"), মান বলে কোথায় যাবে ([[Go]]) --}}
<form id="executive-open" method="POST" action="{{ route('executive.open') }}" class="hidden">
    @csrf
</form>
