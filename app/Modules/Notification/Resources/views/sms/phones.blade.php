{{--
    ⭐ কন্ট্রোল প্যানেল → বার্তা → SMS-এর ফোন (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
    ⓘ ওপরে আজকের অবস্থা (সারিতে অপেক্ষায়, আজ গেছে, আজ যায়নি, সবচেয়ে পুরনোটা কতক্ষণ), তারপর ফোনগুলো (সংযুক্ত কি না, শেষ দেখা,
      আজ কতটা / দিনের সীমা, নতুন চাবি, চালু-বন্ধ), নতুন ফোন বসানো, আর শেষ ৫০টা SMS কী হলো।
    🔒 চাবি একবারই দেখানো হয় — এখানে কপি করে ফোনের ABOS অ্যাপে বসাতে হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::sms.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::sms.title')" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::sms.note') }}</p>

        @include('notification::partials.flash')

        @if ($token)
            <div role="status" data-sms-token class="space-y-2 rounded-(--radius-card) border border-(--color-brand-500) bg-(--color-surface-card) p-3">
                <p class="text-sm font-medium">{{ __('notification::sms.token_ready', ['phone' => $token['phone']]) }}</p>
                <input type="text" readonly value="{{ $token['token'] }}" x-data x-on:focus="$el.select()" aria-label="{{ __('notification::sms.token') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-muted) px-3 font-mono text-sm">
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::sms.token_once', ['address' => url('/')]) }}</p>
            </div>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-sms-summary>
            @foreach ([
                ['waiting', $summary['waiting'], $summary['waiting'] > 0 && $summary['oldest']?->lt(now()->subMinutes(\App\Models\SmsOutbox::WARN_MINUTES)) ? 'text-(--color-badge-danger-ink)' : 'text-(--color-ink)'],
                ['today_sent', $summary['today_sent'], 'text-(--color-badge-success-ink)'],
                ['today_failed', $summary['today_failed'], $summary['today_failed'] > 0 ? 'text-(--color-badge-danger-ink)' : 'text-(--color-ink)'],
            ] as [$key, $value, $tone])
                <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" data-sms-figure="{{ $key }}">
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::sms.'.$key) }}</p>
                    <p class="text-xl font-semibold tabular-nums {{ $tone }}">{{ $value }}</p>
                </div>
            @endforeach
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" data-sms-figure="oldest">
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::sms.oldest') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ $summary['oldest'] === null ? '—' : __('notification::sms.minutes', ['n' => (int) $summary['oldest']->diffInMinutes(now())]) }}</p>
            </div>
        </div>

        <section class="space-y-2" aria-labelledby="sms-phones-h">
            <h2 id="sms-phones-h" class="text-sm font-semibold">{{ __('notification::sms.phones') }}</h2>

            @if ($phones->isEmpty())
                <p class="text-sm text-(--color-ink-muted)" data-sms-no-phone>{{ __('notification::sms.no_phone') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('notification::sms.name') }}</th>
                                <th class="text-start">{{ __('notification::sms.sim_number') }}</th>
                                <th class="text-start">{{ __('notification::sms.state') }}</th>
                                <th class="text-start">{{ __('notification::sms.last_seen') }}</th>
                                <th class="num">{{ __('notification::sms.today') }}</th>
                                <th class="num">{{ __('notification::sms.daily_limit') }}</th>
                                <th class="text-start">{{ __('notification::sms.change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($phones as $phone)
                                @php $state = ! $phone->is_active ? 'off' : ($phone->online() ? 'online' : 'offline'); @endphp
                                <tr class="border-t border-(--color-border)" data-sms-phone="{{ $phone->id }}" data-sms-state="{{ $state }}">
                                    <td>{{ $phone->name }}@if ($phone->app_version) <span class="text-2xs text-(--color-ink-muted)">· {{ $phone->app_version }}</span>@endif</td>
                                    <td class="tabular-nums">{{ $phone->sim_number ?? '—' }}</td>
                                    <td class="text-2xs">
                                        <span @class([
                                            'rounded-(--radius-badge) px-2 py-0.5',
                                            'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $state === 'online',
                                            'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $state === 'offline',
                                            'bg-(--color-surface-sunken) text-(--color-ink-muted)' => $state === 'off',
                                        ])>{{ __('notification::sms.states.'.$state) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap text-2xs tabular-nums">{{ $phone->last_seen_at === null ? __('notification::sms.never') : \App\Core\Support\DateFormat::formatWithTime($phone->last_seen_at) }}</td>
                                    <td class="num tabular-nums">{{ $phone->sentToday() }}</td>
                                    <td class="num tabular-nums">{{ $phone->daily_limit }}</td>
                                    <td>
                                        <details>
                                            <summary class="cursor-pointer text-2xs text-(--color-link)">{{ __('notification::sms.edit') }}</summary>
                                            <form method="POST" action="{{ route('notification.sms_phones.update', $phone) }}" class="mt-2 grid gap-2 text-2xs text-(--color-ink-muted)">
                                                @csrf
                                                @method('PUT')
                                                <label class="grid gap-1">{{ __('notification::sms.name') }}
                                                    <input type="text" name="name" required maxlength="120" value="{{ $phone->name }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                                </label>
                                                <label class="grid gap-1">{{ __('notification::sms.sim_number') }}
                                                    <input type="tel" name="sim_number" maxlength="20" value="{{ $phone->sim_number }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                                </label>
                                                <label class="grid gap-1">{{ __('notification::sms.daily_limit') }}
                                                    <input type="number" name="daily_limit" required min="1" max="5000" value="{{ $phone->daily_limit }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                                </label>
                                                <label class="flex items-center gap-2 text-sm text-(--color-ink)">
                                                    <input type="checkbox" name="is_active" value="1" @checked($phone->is_active)> {{ __('notification::sms.active') }}
                                                </label>
                                                <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white">{{ __('notification::sms.save') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('notification.sms_phones.token', $phone) }}" class="mt-2">
                                                @csrf
                                                <button type="submit" class="text-2xs text-(--color-link) hover:underline">{{ __('notification::sms.new_token') }}</button>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <details class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" @if ($phones->isEmpty()) open @endif>
                <summary class="cursor-pointer text-sm font-medium">{{ __('notification::sms.add') }}</summary>
                <form method="POST" action="{{ route('notification.sms_phones.store') }}" class="mt-3 grid gap-3 text-2xs text-(--color-ink-muted) md:grid-cols-4" data-sms-add>
                    @csrf
                    <label class="grid gap-1">{{ __('notification::sms.name') }}
                        <input type="text" name="name" required maxlength="120" value="{{ old('name', __('notification::sms.default_name')) }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>
                    <label class="grid gap-1">{{ __('notification::sms.sim_number') }}
                        <input type="tel" name="sim_number" maxlength="20" value="{{ old('sim_number') }}" placeholder="01XXXXXXXXX" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>
                    <label class="grid gap-1">{{ __('notification::sms.daily_limit') }}
                        <input type="number" name="daily_limit" required min="1" max="5000" value="{{ old('daily_limit', 200) }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>
                    <div class="flex items-end">
                        <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white">{{ __('notification::sms.add_button') }}</button>
                    </div>
                    <p class="md:col-span-4">{{ __('notification::sms.add_note') }}</p>
                </form>
            </details>
        </section>

        <section class="space-y-2" aria-labelledby="sms-recent-h">
            <h2 id="sms-recent-h" class="text-sm font-semibold">{{ __('notification::sms.recent') }}</h2>

            @if ($recent->isEmpty())
                <p class="text-sm text-(--color-ink-muted)">{{ __('notification::sms.recent_empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('notification::sms.when') }}</th>
                                <th class="text-start">{{ __('notification::sms.to') }}</th>
                                <th class="text-start">{{ __('notification::sms.source') }}</th>
                                <th class="text-start">{{ __('notification::sms.text') }}</th>
                                <th class="num">{{ __('notification::sms.parts') }}</th>
                                <th class="text-start">{{ __('notification::sms.state') }}</th>
                                <th class="text-start">{{ __('notification::sms.phone') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $row)
                                <tr class="border-t border-(--color-border)" data-sms-row="{{ $row->status }}">
                                    <td class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime($row->created_at) }}</td>
                                    <td class="whitespace-nowrap text-2xs tabular-nums">+{{ $row->phone }}</td>
                                    <td class="text-2xs">{{ __('notification::sms.sources.'.$row->source) }}</td>
                                    <td class="max-w-md truncate text-2xs" title="{{ $row->body }}">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', (string) $row->body), 90) }}</td>
                                    <td class="num text-2xs tabular-nums">{{ \App\Models\SmsOutbox::parts((string) $row->body) }}</td>
                                    <td class="text-2xs">
                                        {{ __('notification::sms.statuses.'.($row->delivered_at !== null ? 'delivered' : $row->status)) }}
                                        @if ($row->error) <span class="block text-(--color-badge-danger-ink)">{{ $row->error }}</span> @endif
                                    </td>
                                    <td class="text-2xs">{{ $row->gatewayPhone?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
