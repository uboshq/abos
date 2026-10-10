{{-- মেয়াদের তারিখ (§১২) — পেরিয়ে গেলে লাল আর পাশে "মেয়াদ শেষ"; ফাঁকা হলে দাগ --}}
@if ($document->expiry_date === null)
    <span class="text-(--color-ink-muted)">—</span>
@elseif ($document->isExpired())
    <span class="text-(--color-danger)">
        {{ \App\Core\Support\DateFormat::format($document->expiry_date) }}
        <span class="text-2xs">· {{ __('documents::catalog.expiry.expired') }}</span>
    </span>
@else
    {{ \App\Core\Support\DateFormat::format($document->expiry_date) }}
@endif
