{{--
    ⓘ পপআপ — ব্রাউজারের নিজের `popover`, কোনো স্ক্রিপ্ট নয়। ফেরত আসা ভুল (`open`) হলে পাতার উপরেই খোলা থাকে, যা লেখা ছিল তা সহ;
      তখন "বন্ধ" মানে পাতাটা আবার খোলা।
    $id, $title, $action, $button, $open (bool), $body (view নাম), $data
--}}
<div id="{{ $id }}" data-pop @unless ($open) popover="auto" @endunless role="dialog" aria-labelledby="{{ $id }}-title"
     @class(['fixed inset-0 m-auto h-fit max-h-[90vh] w-[32rem] max-w-[92vw] overflow-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-5 text-(--color-ink) shadow-lg', 'z-50' => $open])>
    <h2 id="{{ $id }}-title" class="mb-3 text-base font-semibold">{{ $title }}</h2>
    @if ($open && $errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ $action }}" class="space-y-3">
        @csrf
        <input type="hidden" name="_pop" value="{{ $id }}">
        @include($body)
        <div class="flex justify-end gap-2 pt-2">
            @if ($open)
                <x-ui.button tone="secondary" :href="url()->full()">{{ __('customer::binding.close') }}</x-ui.button>
            @else
                <button type="button" popovertarget="{{ $id }}" popovertargetaction="hide"
                        class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border border-(--color-border) px-3 text-sm">{{ __('customer::binding.close') }}</button>
            @endif
            <x-ui.button type="submit" tone="primary">{{ $button }}</x-ui.button>
        </div>
    </form>
</div>
