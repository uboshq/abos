@props(['company', 'branch' => null])

{{--
    কোম্পানি সুইচার — সেকশন ১৫.১৫।

    Phase 1-এই, পেছানো হয়নি। DMS-এ এটা পরে বসাতে গিয়ে দুইটা আলাদা ফিক্স
    লেগেছিল: প্রথমে সুইচ কাজই করত না, তারপর পাতা রিলোড করলে মুছে যেত।

    পছন্দটা ডাটাবেজে লেখা হয়, সেশনে নয় — তাই রিলোডেও টেকে, অন্য ডিভাইসেও।
--}}
@php
    $others = auth()->user()?->companies()->where('companies.id', '!=', $company->id)->get() ?? collect();
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            @class([
                'flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-field) px-2 transition-colors',
                'hover:bg-(--color-surface-hover)' => $others->isNotEmpty(),
                'cursor-default' => $others->isEmpty(),
            ])
            @if ($others->isEmpty()) disabled @endif>

        <span class="min-w-0 text-start">
            <span class="block max-w-40 truncate text-sm font-medium text-(--color-ink)">
                {{ $company->name() }}
            </span>
            @if ($branch)
                <span class="block max-w-40 truncate text-2xs text-(--color-ink-muted)">
                    {{ $branch->name() }}
                </span>
            @endif
        </span>

        @if ($others->isNotEmpty())
            <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-(--color-ink-muted)" aria-hidden="true">
                <path d="m7 10 5 5 5-5H7Z"/>
            </svg>
        @endif
    </button>

    @if ($others->isNotEmpty())
        <div x-show="open"
             x-cloak
             @click.outside="open = false"
             role="menu"
             class="absolute end-0 top-full z-40 mt-1 w-64 rounded-(--radius-field) border
                    border-(--color-border) bg-(--color-surface-card) py-1 shadow-(--shadow-overlay)">

            <p class="px-3 py-1 text-2xs font-semibold uppercase text-(--color-ink-muted)">
                {{ __('core.company.switch') }}
            </p>

            @foreach ($others as $other)
                <form method="POST" action="{{ route('company.switch') }}">
                    @csrf
                    <input type="hidden" name="company_id" value="{{ $other->id }}">
                    <button type="submit" role="menuitem"
                            class="flex min-h-(--spacing-touch) w-full items-center px-3 text-start text-sm
                                   transition-colors hover:bg-(--color-surface-hover)">
                        {{ $other->name() }}
                    </button>
                </form>
            @endforeach
        </div>
    @endif
</div>
