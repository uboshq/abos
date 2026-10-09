{{--
    মালিকের কেন্দ্র — ভাই-কোম্পানির পক্ষ।

    ⓘ "এই কোম্পানির এই ক্রেতা বা সরবরাহকারী আসলে আমাদের ঐ কোম্পানি।" জোড়া থাকলে গ্রুপের মোট থেকে
    দুইয়ের মাঝের বিক্রি, পাওনা আর দেনা বাদ যায় ([[Eliminations]])। ⛔ নাম মিলিয়ে কিছু আন্দাজ করা হয় না।
--}}
<x-layouts.app :menu="$menu">
    @include('executive::partials.fit')
    <x-slot:title>{{ __('executive::links.title') }}</x-slot:title>

    <div data-executive-links class="flex flex-col gap-3">
        <div data-fit class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: 56px">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::links.title') }}</h1>
            <form method="GET" action="{{ route('executive.links') }}" class="flex flex-wrap items-center gap-2">
                <label for="ln-company" class="text-sm text-(--color-ink-muted)">{{ __('executive::links.company') }}</label>
                <select id="ln-company" name="company" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach ($companies as $company)
                        <option value="{{ $company['id'] }}" @selected($chosen === $company['id'])>{{ $company['name'] }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>
        </div>

        @if (session('saved'))
            <div role="status" class="rounded-(--radius-card) bg-(--color-badge-success-bg) px-4 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
        @endif
        <x-ui.errors />

        <p class="text-sm text-(--color-ink-muted)">{{ __('executive::links.explain') }}</p>

        @if ($chosen !== null && count($companies) > 1)
            <form method="POST" action="{{ route('executive.links.store') }}"
                  class="flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
                @csrf
                <input type="hidden" name="company_id" value="{{ $chosen }}">
                <div>
                    <label for="ln-party" class="block text-2xs text-(--color-ink-muted)">{{ __('executive::links.party') }}</label>
                    <select id="ln-party" name="party" required class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        @foreach ($parties as $type => $list)
                            <optgroup label="{{ __('executive::links.type_'.$type) }}">
                                @foreach ($list as $party)
                                    <option value="{{ $type }}:{{ $party['id'] }}">{{ $party['name'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="ln-sister" class="block text-2xs text-(--color-ink-muted)">{{ __('executive::links.is_sister') }}</label>
                    <select id="ln-sister" name="sister_company_id" required class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        @foreach ($companies as $company)
                            @if ($company['id'] !== $chosen)
                                <option value="{{ $company['id'] }}">{{ $company['name'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" tone="primary">{{ __('executive::links.add') }}</x-ui.button>
            </form>
        @endif

        <section class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-2xs text-(--color-ink-muted)">
                        <th class="px-3 py-2 font-semibold">{{ __('executive::links.company') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('executive::links.party') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('executive::links.is_sister') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($links as $link)
                        <tr data-link="{{ $link['id'] }}" class="border-t border-(--color-border)">
                            <td class="px-3 py-1.5">{{ $link['company'] }}</td>
                            <td class="px-3 py-1.5">{{ $link['party'] }} <span class="text-2xs text-(--color-ink-muted)">· {{ __('executive::links.type_'.$link['type']) }}</span></td>
                            <td class="px-3 py-1.5">{{ $link['sister'] }}</td>
                            <td class="px-3 py-1.5 text-right">
                                <form method="POST" action="{{ route('executive.links.destroy', ['link' => $link['id']]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit">{{ __('executive::links.remove') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-(--color-ink-muted)">{{ __('executive::links.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-ui.list-totals :rows="$links" />
            <x-ui.pager :rows="$links" />
        </section>
    </div>
</x-layouts.app>
