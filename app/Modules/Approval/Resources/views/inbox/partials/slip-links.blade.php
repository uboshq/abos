{{-- জমার স্লিপ — প্রতিটা একটা লিংক; খোলার অনুমতি download-এর দরজাই দেখে ([[AttachmentController::download()]]) --}}
@forelse ($slips as $slip)
    <a href="{{ $slip['url'] }}" class="block truncate text-(--color-brand-500) underline-offset-2 hover:underline">{{ $slip['name'] }}</a>
@empty
    <span class="text-(--color-ink-muted)">—</span>
@endforelse
