{{--
    একজন ক্রেতার পয়েন্টের খাতা — নতুন আগে (স্পেক §৭-ঠ, §১৩)।

    ⓘ উপরে আজকের খরচযোগ্য ব্যালান্স আর তার টাকার মূল্য; নিচে প্রতিটা সারি,
    পাশে *"তখনকার ব্যালান্স"* — [[LoyaltyLedger::balance()]]-কে সারির মুহূর্ত
    দিয়ে জিজ্ঞেস করা।

    ⛔ কোনো সংখ্যা এই পাতায় গোনা হয় না — `$balance`, `$running`, `$points`
    সব কন্ট্রোলার থেকে তৈরি। ⚠️ এই পাতায় কোনো `@php` ব্লক নেই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::loyalty_screen.title') }} · {{ $customer->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$customer->code.' · '.$customer->name()" />
    </x-slot:header>

    <div class="space-y-4">
        <a class="text-sm underline" href="{{ route('promotion.loyalty.index') }}">{{ __('promotion::loyalty_screen.back') }}</a>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.balance') }}</dt>
                    <dd @class(['text-xl font-semibold tabular-nums', 'text-(--color-danger)' => str_starts_with($balance, '-')])>{{ $balance }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.worth') }}</dt>
                    <dd class="text-xl font-semibold tabular-nums">{{ \App\Core\Support\Money::format($worth) }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-sm text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.balance_note') }}</p>
        </section>

        @if ($entries->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.no_entries') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('promotion::loyalty_screen.when') }}</th>
                            <th class="text-start">{{ __('promotion::loyalty_screen.kind') }}</th>
                            <th class="text-end">{{ __('promotion::loyalty_screen.points') }}</th>
                            <th class="text-start">{{ __('promotion::loyalty_screen.expires_on') }}</th>
                            <th class="text-start">{{ __('promotion::loyalty_screen.source') }}</th>
                            <th class="text-end">{{ __('promotion::loyalty_screen.running') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr class="border-t border-(--color-border)">
                                <td>{{ $entry->occurred_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $entry->kind?->label() ?? '—' }}</td>
                                <td class="num tabular-nums">{{ $points[$entry->id] ?? '0' }}</td>
                                {{-- ⓘ মেয়াদ কেবল পয়েন্ট-আনা সারির; খরচ বা মেয়াদ-শেষের সারিতে ঘরটা ফাঁকা --}}
                                <td>
                                    @if ($entry->expires_on)
                                        {{ $entry->expires_on->format('d/m/Y') }}
                                    @elseif (str_starts_with($points[$entry->id] ?? '0', '-') || ($points[$entry->id] ?? '0') === '0')
                                        —
                                    @else
                                        {{ __('promotion::loyalty_screen.never') }}
                                    @endif
                                </td>
                                <td>{{ $entry->source_type }} #{{ $entry->source_id }}</td>
                                <td @class(['num tabular-nums', 'text-(--color-danger)' => str_starts_with($running[$entry->id] ?? '0', '-')])>
                                    {{ $running[$entry->id] ?? '0' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$entries" />
        <x-ui.list-totals :rows="$entries" />
    </div>
</x-layouts.app>
