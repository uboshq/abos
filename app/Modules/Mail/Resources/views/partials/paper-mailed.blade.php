{{--
    ⓘ এই কাগজ কবে কাকে মেইলে গেছে — পাঠানো মেইলের খাতা থেকে ([[MailSends::forPaper]])।
    @param string $kind
    @param int    $id
--}}
@php
    $mailed = app(\App\Modules\Mail\Services\MailSends::class)->forPaper($kind, (int) $id);
    $mailedLine = $mailed->take(3)->map(fn ($m) => $m->sent_at?->format('d-m-Y H:i').' → '.$m->to.' ('.($m->sender?->name ?? '').')')->implode(' · ');
@endphp
@if ($mailed->isNotEmpty())
    <div data-mailed class="mb-3 rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-2 text-xs text-(--color-badge-info-ink) tabular">
        {{ __('mail::field.mailed') }}: {{ $mailedLine }}
    </div>
@endif
