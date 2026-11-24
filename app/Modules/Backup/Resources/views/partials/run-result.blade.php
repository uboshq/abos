<span @class(['text-2xs', 'font-semibold text-(--color-badge-danger-ink)' => $run->status === 'failed'])>
    {{ __('backup::screen.status_'.$run->status) }}
    @if ($run->error)
        {{-- ⛔ কাঁচা কারণ কেবল গোটা-ডাটাবেসের মানুষের জন্য (১ অক্টোবর ২০২৬) --}}
        <span class="mt-0.5 block font-mono text-2xs break-all">{{ $seesRawErrors ? $run->error : __('backup::message.failed_detail_hidden') }}</span>
    @endif
</span>
