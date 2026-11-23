{{--
    ⭐ বার্তার পথ — মালিকের "ok", ২৩ নভেম্বর ২০২৬ (নমুনা HUXqpfhxng8823eEFrvH54, ট্যাব ঘ) ([[PartyRoadsController]])।
    ⓘ মাথায় এক সারি: নাম · ট্যাব (পথ / SMS-এর ফোন / খরচ) · সংরক্ষণ। নিচে তিন পথের তিন কার্ড — বোতাম চাপলে (wa.me), নিজে থেকে
      (Meta Cloud API), অফিসের ফোনের সিমে — তারপর "কোন পথ আগে" (স্থির ক্রম) আর শুরুর ভাষা।
    ⛔ চাবি (token) আর App secret কখনো পাতায় ফেরত আসে না — বসানো থাকলে কেবল বিন্দু, ফাঁকা রাখলে আগেরটাই থাকে।
    ⓘ App secret আর webhook-এর ঠিকানা/verify token নমুনায় আঁকা নেই, কিন্তু webhook-এর পাহারার জন্য লাগে — Cloud কার্ডে চাবির নিচে।
--}}
@php
    $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
    $label = 'grid gap-1 text-2xs font-medium text-(--color-ink-muted)';
    $card = 'grid content-start gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3';
    $dl = 'grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party_roads.title') }}</x-slot:title>

    <div class="space-y-4" data-party-roads data-tab="{{ $tab }}">
        <x-ui.list-tabs :tabs="$tabs" :label="__('notification::party_roads.title')" />

        <x-ui.toolbar :title="__('notification::party_roads.title')" :search="false" :export="false" :share="false" :print="false" :refresh="false">
            @if ($tab === 'roads')
                <x-slot:actions>
                    <button type="submit" form="party-roads-form" data-party-roads-save
                            class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm font-medium text-white">{{ __('notification::party_roads.save') }}</button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        @include('notification::partials.flash')

        @if ($tab === 'roads')
            <form id="party-roads-check" method="POST" action="{{ route('notification.party_roads.check') }}" class="hidden">@csrf</form>

            <form id="party-roads-form" method="POST" action="{{ route('notification.party_roads.save') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid gap-3 lg:grid-cols-3">
                    {{-- ── ১. WhatsApp — বোতাম চাপলে ── --}}
                    <section class="{{ $card }}" data-road="wame" aria-labelledby="road-wame-h">
                        <div class="flex items-center gap-2">
                            <x-ui.icon name="chat" :size="20" class="text-(--color-badge-success-ink)" />
                            <h2 id="road-wame-h" class="text-sm font-semibold">{{ __('notification::party_roads.wame.title') }}</h2>
                            <span class="flex-1"></span>
                            @include('notification::party.partials.road-switch', ['name' => 'wame', 'on' => $wameOn, 'label' => __('notification::party_roads.wame.switch')])
                        </div>
                        <dl class="{{ $dl }}">
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.how') }}</dt><dd>{{ __('notification::party_roads.wame.how') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.cost') }}</dt><dd class="font-semibold">{{ __('notification::party_roads.wame.cost') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.from') }}</dt><dd>{{ __('notification::party_roads.wame.from') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.status') }}</dt><dd>{{ __('notification::party_roads.wame.status') }}</dd>
                        </dl>
                    </section>

                    {{-- ── ২. WhatsApp — নিজে থেকে (Meta Cloud API) ── --}}
                    <section class="{{ $card }}" data-road="cloud" data-cloud-state="{{ $cloudState }}" aria-labelledby="road-cloud-h">
                        <div class="flex items-center gap-2">
                            <x-ui.icon name="send" :size="20" class="text-(--color-badge-success-ink)" />
                            <h2 id="road-cloud-h" class="text-sm font-semibold">{{ __('notification::party_roads.cloud.title') }}</h2>
                            <span class="flex-1"></span>
                            @include('notification::party.partials.road-switch', ['name' => 'cloud', 'on' => $cloudOn, 'label' => __('notification::party_roads.cloud.switch')])
                        </div>

                        <label class="{{ $label }}">{{ __('notification::party_roads.cloud.number') }}
                            <input type="tel" name="sender_id" maxlength="20" value="{{ old('sender_id', $cloud?->sender_id) }}" placeholder="01XXXXXXXXX" class="{{ $field }} tabular-nums">
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="{{ $label }}">{{ __('notification::party_roads.cloud.phone_number_id') }}
                                <input type="text" name="phone_number_id" inputmode="numeric" maxlength="40" value="{{ old('phone_number_id', $cloud?->secret('phone_number_id')) }}"
                                       placeholder="{{ __('notification::party_roads.cloud.from_meta') }}" class="{{ $field }} tabular-nums">
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_roads.cloud.waba_id') }}
                                <input type="text" name="waba_id" inputmode="numeric" maxlength="40" value="{{ old('waba_id', $cloud?->secret('waba_id')) }}"
                                       placeholder="{{ __('notification::party_roads.cloud.from_meta') }}" class="{{ $field }} tabular-nums">
                            </label>
                        </div>
                        {{-- ⛔ মান কখনো ফেরত নয় — বসানো থাকলে কেবল বিন্দু (placeholder), ফাঁকা পাঠালে আগেরটাই থাকে --}}
                        <label class="{{ $label }}">{{ __('notification::party_roads.cloud.token') }}
                            <input type="password" name="token" maxlength="1000" autocomplete="new-password" value="" data-has-token="{{ $hasToken ? '1' : '0' }}"
                                   placeholder="{{ $hasToken ? '••••••••••••••••' : '' }}" class="{{ $field }}">
                        </label>
                        <label class="{{ $label }}">{{ __('notification::party_roads.cloud.app_secret') }}
                            <input type="password" name="app_secret" maxlength="200" autocomplete="new-password" value="" data-has-secret="{{ $hasSecret ? '1' : '0' }}"
                                   placeholder="{{ $hasSecret ? '••••••••••••••••' : '' }}" class="{{ $field }}">
                        </label>
                        <div class="{{ $label }}" data-webhook>
                            <span>{{ __('notification::party_roads.cloud.webhook') }}</span>
                            @if ($webhookUrl !== null && $verifyToken !== null)
                                <div class="flex items-center gap-2" x-data="shareMenu({ url: @js($webhookUrl) })">
                                    <input type="text" readonly value="{{ $webhookUrl }}" aria-label="{{ __('notification::party_roads.cloud.webhook') }}"
                                           class="{{ $field }} min-w-0 flex-1 bg-(--color-surface-muted) font-mono text-2xs">
                                    <button type="button" @click="copy()" class="shrink-0 text-2xs text-(--color-link) hover:underline">
                                        <span x-show="! copied">{{ __('notification::party_roads.cloud.copy') }}</span><span x-show="copied" x-cloak>{{ __('notification::party_roads.cloud.copied') }}</span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2" x-data="shareMenu({ url: @js($verifyToken) })">
                                    <input type="text" readonly value="{{ $verifyToken }}" aria-label="verify token"
                                           class="{{ $field }} min-w-0 flex-1 bg-(--color-surface-muted) font-mono text-2xs">
                                    <button type="button" @click="copy()" class="shrink-0 text-2xs text-(--color-link) hover:underline">
                                        <span x-show="! copied">{{ __('notification::party_roads.cloud.copy') }}</span><span x-show="copied" x-cloak>{{ __('notification::party_roads.cloud.copied') }}</span>
                                    </button>
                                </div>
                            @else
                                <span class="text-sm font-normal text-(--color-ink)">{{ __('notification::party_roads.cloud.webhook_after_save') }}</span>
                            @endif
                        </div>

                        <dl class="{{ $dl }}">
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.how') }}</dt><dd>{{ __('notification::party_roads.cloud.how') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.cost') }}</dt>
                            <dd data-cloud-rate>{{ __('notification::party_roads.cloud.cost') }} — {{ $rate === '' ? __('notification::party_roads.cloud.cost_unset') : __('notification::party_roads.cloud.cost_set', ['rate' => $rate]) }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.template') }}</dt><dd>{{ __('notification::party_roads.cloud.template') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.status') }}</dt><dd>{{ __('notification::party_roads.cloud.status') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.needs') }}</dt><dd>{{ __('notification::party_roads.cloud.needs') }}</dd>
                        </dl>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="['on' => 'success', 'waiting' => 'pending', 'error' => 'danger'][$cloudState]" data-cloud-pill>{{ __('notification::party_roads.cloud.states.'.$cloudState) }}</x-ui.badge>
                            @if ($cloudState === 'error' && filled($cloud?->last_error))
                                <span class="text-2xs text-(--color-badge-danger-ink)" data-cloud-error>{{ $cloud->last_error }}</span>
                            @endif
                            <span class="flex-1"></span>
                            <button type="submit" form="party-roads-check" data-cloud-check
                                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 text-sm font-medium">{{ __('notification::party_roads.cloud.check') }}</button>
                        </div>
                    </section>

                    {{-- ── ৩. SMS — অফিসের ফোনের সিমে ── --}}
                    <section class="{{ $card }}" data-road="sms" aria-labelledby="road-sms-h">
                        <div class="flex items-center gap-2">
                            <x-ui.icon name="phone" :size="20" class="text-(--color-link)" />
                            <h2 id="road-sms-h" class="text-sm font-semibold">{{ __('notification::party_roads.sms.title') }}</h2>
                            <span class="flex-1"></span>
                            @include('notification::party.partials.road-switch', ['name' => 'sms', 'on' => $smsOn, 'label' => __('notification::party_roads.sms.switch')])
                        </div>
                        <dl class="{{ $dl }}">
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.how') }}</dt><dd>{{ __('notification::party_roads.sms.how') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.cost') }}</dt><dd>{{ __('notification::party_roads.sms.cost') }}</dd>
                            <dt class="text-(--color-ink-muted)">{{ __('notification::party_roads.facts.status') }}</dt><dd>{{ __('notification::party_roads.sms.status') }}</dd>
                        </dl>
                        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)">
                            <table class="ui-grid">
                                <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                                    <tr>
                                        <th class="text-start">{{ __('notification::party_roads.sms.phone') }}</th>
                                        <th class="text-start">{{ __('notification::party_roads.sms.last_seen') }}</th>
                                        <th class="num">{{ __('notification::party_roads.sms.today') }}</th>
                                        <th class="text-start">{{ __('notification::party_roads.sms.state') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($phones as $phone)
                                        @php
                                            $state = ! $phone->is_active ? 'off' : ($phone->online() ? 'online' : 'offline');
                                            $digits = strtr((string) $phone->sim_number, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
                                            $digits = substr((string) preg_replace('/\D+/', '', $digits), -11);
                                            $operator = ['013' => 'GP', '017' => 'GP', '018' => 'Robi', '016' => 'Airtel', '019' => 'Banglalink', '014' => 'Banglalink', '015' => 'Teletalk'][substr($digits, 0, 3)] ?? null;
                                            $seen = $phone->last_seen_at;
                                            $minutes = $seen === null ? null : (int) max(0, $seen->diffInMinutes(now()));
                                        @endphp
                                        <tr class="border-t border-(--color-border)" data-road-phone="{{ $phone->id }}" data-sms-state="{{ $state }}">
                                            <td>{{ $phone->name }}@if ($operator) · {{ $operator }}@endif</td>
                                            <td class="whitespace-nowrap tabular-nums">
                                                @if ($minutes === null)
                                                    {{ __('notification::party_roads.sms.never') }}
                                                @elseif ($minutes < 1)
                                                    {{ __('notification::party_roads.sms.just_now') }}
                                                @elseif ($minutes < 60)
                                                    {{ __('notification::party_roads.sms.minutes_ago', ['n' => $minutes]) }}
                                                @elseif ($minutes < 1440)
                                                    {{ __('notification::party_roads.sms.hours_ago', ['n' => intdiv($minutes, 60)]) }}
                                                @else
                                                    {{ \App\Core\Support\DateFormat::formatWithTime($seen) }}
                                                @endif
                                            </td>
                                            <td class="num tabular-nums">{{ $phone->sentToday() }}</td>
                                            <td>
                                                <x-ui.badge :tone="['online' => 'success', 'offline' => 'danger', 'off' => 'draft'][$state]">{{ __('notification::party_roads.sms.states.'.$state) }}</x-ui.badge>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-sm text-(--color-ink-muted)" data-road-no-phone>{{ __('notification::party_roads.sms.no_phone') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                {{-- ── কোন পথ আগে — স্থির ক্রম ([[PartyMessageDispatcher::deliver()]]), আর শুরুর ভাষা ── --}}
                <section class="{{ $card }}" data-road-order aria-labelledby="road-order-h">
                    <h2 id="road-order-h" class="text-sm font-semibold">{{ __('notification::party_roads.order.title') }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <x-ui.badge tone="info">{{ __('notification::party_roads.order.cloud') }}</x-ui.badge>
                        <span class="text-(--color-ink-muted)">{{ __('notification::party_roads.order.then') }}</span>
                        <x-ui.badge tone="info">{{ __('notification::party_roads.order.sms') }}</x-ui.badge>
                        <span class="text-(--color-ink-muted)">{{ __('notification::party_roads.order.then') }}</span>
                        <x-ui.badge tone="danger">{{ __('notification::party_roads.order.failed') }}</x-ui.badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span>{{ __('notification::party_roads.order.locale') }}</span>
                        <div role="radiogroup" aria-label="{{ __('notification::party_roads.order.locale_label') }}"
                             class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                            @foreach (['bn', 'en'] as $code)
                                <label class="cursor-pointer">
                                    <input type="radio" name="default_locale" value="{{ $code }}" @checked(old('default_locale', $defaultLocale) === $code) class="peer sr-only">
                                    <span class="block px-3 py-1 font-medium peer-checked:bg-(--color-brand-500) peer-checked:text-white
                                                 peer-focus-visible:outline-2 peer-focus-visible:outline-(--color-brand-500)">{{ __('notification::party_roads.order.'.$code) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>
            </form>
        @else
            {{-- ── খরচ — এই মাসে কোন পথে কয়টা; হার বসানো থাকলে Cloud-এর গোনা × হার ── --}}
            <section class="{{ $card }}" data-road-cost aria-labelledby="road-cost-h">
                <h2 id="road-cost-h" class="text-sm font-semibold tabular-nums">{{ __('notification::party_roads.cost.month', ['from' => \App\Core\Support\DateFormat::format($cost['from'])]) }}</h2>
                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('notification::party_roads.cost.road') }}</th>
                                <th class="num">{{ __('notification::party_roads.cost.count') }}</th>
                                <th class="num">{{ __('notification::party_roads.cost.failed') }}</th>
                                <th class="num">{{ __('notification::party_roads.cost.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cost['rows'] as $row)
                                <tr class="border-t border-(--color-border)" data-cost-road="{{ $row['road'] }}" data-cost-count="{{ $row['count'] }}">
                                    <td>{{ __('notification::party_roads.cost.roads.'.$row['road']) }}</td>
                                    <td class="num tabular-nums">{{ number_format($row['count']) }}</td>
                                    <td class="num tabular-nums">{{ number_format($row['failed']) }}</td>
                                    <td class="num tabular-nums">
                                        @if ($row['road'] === 'cloud')
                                            @if ($cost['cloud_cost'] === null)
                                                <span class="text-(--color-ink-muted)" data-cost-unset>{{ __('notification::party_roads.cost.rate_unset') }}</span>
                                            @else
                                                <span data-cost-cloud>{{ number_format($cost['cloud_cost'], 2) }}</span>
                                            @endif
                                        @elseif ($row['road'] === 'wame')
                                            <span class="text-(--color-ink-muted)">{{ __('notification::party_roads.cost.free') }}</span>
                                        @else
                                            <span class="text-(--color-ink-muted)">{{ __('notification::party_roads.cost.sim') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
