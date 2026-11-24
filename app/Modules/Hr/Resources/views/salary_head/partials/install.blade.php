{{-- খালি তালিকা — এখান থেকেই শুরু। এই খাতগুলো ছাড়া কারও বেতনই
     বসানো যায় না, তাই "নিজে বানান" বলাটা কাজ ঠেলে দেওয়া। --}}
<div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
            bg-(--color-surface-card) p-8 text-center">
    <h2 class="text-lg font-semibold">{{ __('hr::message.empty_heads') }}</h2>

    @unless (\App\Core\Support\AbosHead::on())
    <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
        {{ __('hr::message.empty_heads_note') }}
    </p>
    @endunless

    <form method="POST" action="{{ route('hr.salary_head.install') }}" class="mt-4">
        @csrf
        <x-ui.button type="submit" tone="primary" :title="\App\Core\Support\AbosHead::on() ? __('hr::message.empty_heads_note') : null">
            {{ __('hr::action.install_defaults') }}
        </x-ui.button>
    </form>
</div>
