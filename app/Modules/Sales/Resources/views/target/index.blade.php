{{--
    লক্ষ্যমাত্রা — বসানো ও মেলানো।

    ── কেন প্রতিটা সারিতেই ঘরটা খোলা থাকে ──────────────────────────────
    মালিক মাসের শুরুতে সবার টার্গেট একসাথে বসান, একজন একজন করে নয়।
    "সম্পাদনা" বোতাম দিয়ে একটা করে খুললে বারোজনের জন্য বারোবার পর্দা
    বদলাতে হত।

    ── কেন অর্জনের সংখ্যাটা ক্লিকযোগ্য ─────────────────────────────────
    নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়। "করিম ৪,২০,০০০" দেখে
    মালিকের পরের প্রশ্নটা সবসময় "কোন বিলগুলো?"
--}}
@php
    use App\Core\Support\Money;

    $canManage = auth()->user()?->can('sales.target.manage') ?? false;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::target.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::target.title')" :subtitle="__('sales::target.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::target.month') }}</span>
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                               class="h-9 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('sales.target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">

        <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead class="border-b border-(--color-border) text-(--color-ink-muted)">
                    <tr>
                        <th class="p-2 text-start font-medium">{{ __('sales::target.who') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('sales::target.target') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('sales::target.achieved') }}</th>
                        <th class="p-2 text-end font-medium">{{ __('sales::target.percent') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-(--color-border) last:border-b-0">
                            <td class="p-2">{{ $row['user']->name }}</td>

                            <td class="p-1 text-end">
                                @if ($canManage)
                                    <input type="number" step="0.01" min="0" inputmode="decimal"
                                           name="amount[{{ $row['user']->id }}]"
                                           value="{{ $row['target'] !== null ? rtrim(rtrim($row['target'], '0'), '.') : '' }}"
                                           class="num h-9 w-32 rounded-(--radius-field) border border-(--color-border)
                                                  bg-(--color-surface-card) px-2 text-end">
                                @else
                                    <span class="tabular">
                                        {{ $row['target'] === null ? '—' : Money::format($row['target']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="tabular p-2 text-end">
                                <a href="{{ route('sales.invoice.index', [
                                        'from' => $month->toDateString(),
                                        'to' => $month->copy()->endOfMonth()->toDateString(),
                                   ]) }}"
                                   class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                    {{ Money::format($row['achieved']) }}
                                </a>
                            </td>

                            {{-- টার্গেট না থাকলে ড্যাশ, শূন্য নয়: "টার্গেট নেই" আর
                                 "০% হয়েছে" দুইটা আলাদা কথা, আর দ্বিতীয়টা অন্যায়। --}}
                            <td @class([
                                'tabular p-2 text-end font-medium',
                                'text-(--color-badge-success-ink)' =>
                                    $row['percent'] !== null && bccomp($row['percent'], '100', 1) >= 0,
                                'text-(--color-badge-warning-ink)' =>
                                    $row['percent'] !== null && bccomp($row['percent'], '100', 1) < 0,
                            ])>
                                {{ $row['percent'] === null ? '—' : $row['percent'].'%' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-(--color-ink-muted)">
                                {{ __('sales::target.nobody_sells') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($canManage && $rows !== [])
            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::target.empty_means_none') }}</p>

            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        @endif
    </form>
</x-layouts.app>
