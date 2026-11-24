{{-- কী কী চিহ্ন লেখা যায় তা দেখানো হয়। না দেখালে ব্যবহারকারী
     অনুমান করতেন, আর ভুল চিহ্ন হুবহু নম্বরে বসে যেত। --}}
<div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
            bg-(--color-surface-card) p-4">
    @unless ($abosHead)
    <p class="mb-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
        {{ __('master_data::message.series_placeholders') }}
    </p>
    @endunless

    <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($placeholders as $token => $label)
            <div class="flex items-baseline gap-2">
                <dt class="num text-2xs font-medium">{{ $token }}</dt>
                <dd class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dd>
            </div>
        @endforeach
    </dl>
</div>
