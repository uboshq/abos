{{-- ⭐ কেন দেখা যায় না — মানুষ, শাখা আর পর্দা বাছলে ধাপগুলো ([[WhyHiddenController]]); কেবল পড়ে --}}
@php($title = __('system_admin::module_board.why'))
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:header>
        <x-ui.page-header :title="$title" />
    </x-slot:header>

    <form method="GET" action="{{ route('system_admin.module.why') }}" class="mb-4 grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4" data-no-swap>
        <x-ui.select name="person" :label="__('system_admin::module_board.why_person')" :options="$people->pluck('name', 'id')->all()" :selected="$person" required />
        <x-ui.select name="branch" :label="__('core.company.branch')" :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])->all()" :selected="$branch" required />
        <x-ui.select name="screen" :label="__('system_admin::module_board.why_screen')" :options="$screens" :selected="$screen" required />
        <x-ui.button type="submit" tone="primary">{{ __('system_admin::module_board.why_check') }}</x-ui.button>
    </form>

    @if ($steps !== [])
        <div data-boxed data-why class= + CARD + r>
            <ul class="divide-y divide-(--color-border)">
                @foreach ($steps as $step)
                    <li class="flex items-center gap-3 px-4 py-3 text-sm" data-why-step="{{ $step['ok'] ? 'ok' : 'stop' }}">
                        <b aria-hidden="true">{{ $step['ok'] ? '✓' : '✕' }}</b>
                        <span class="flex-1">{{ $step['label'] }}</span>
                        @unless ($step['ok'])
                            <a href="{{ $step['url'] }}" class="text-(--color-link) hover:underline">{{ __('system_admin::module_board.why_go') }}</a>
                        @endunless
                    </li>
                @endforeach
            </ul>
            @if (collect($steps)->every('ok'))
                <p class="border-t border-(--color-border) px-4 py-3 text-sm">{{ __('system_admin::module_board.why_yes') }}</p>
            @endif
        </div>
    @endif
</x-layouts.app>
