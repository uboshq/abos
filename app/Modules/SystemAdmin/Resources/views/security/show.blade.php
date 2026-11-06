{{--
    ⭐ লগইন নিরাপত্তা — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৯ ([[LoginSecurityController]])।
    চারটা অংশ: দুই-ধাপ · ভুল লগইন (৭ দিন) · চালু ফোন · আপনাআপনি লগআউট। প্রতিটার বদলের দরজা নিজের পাতায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::security.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::security.title')" :subtitle="__('system_admin::security.subtitle')" />
    </x-slot:header>

    {{-- ⓘ এই পাতা থেকে বদলের দরজায় গিয়ে ফেরত এলে কারণটা এখানে দেখা যায় ([[ARefusalNobodyEverSawTest]]) --}}
    <x-ui.errors />

    @php
        $on = collect($people)->where('two_step', true)->count();
        $when = fn ($at) => $at ? \App\Core\Support\DateFormat::spoken(\Illuminate\Support\Carbon::parse($at)) : '—';
    @endphp

    {{-- ── এক নজরে ── --}}
    <div data-security-summary class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['two-step', __('system_admin::security.two_step_on'), $on.' / '.count($people), $on < count($people)],
            ['failed', __('system_admin::security.failed_week'), (string) $failedCount, $failedCount > 0],
            ['phones', __('system_admin::security.phones'), (string) collect($people)->sum('phones'), false],
            ['logout', __('system_admin::security.auto_logout'), $logoutMinutes > 0 ? __('system_admin::security.minutes', ['n' => $logoutMinutes]) : __('system_admin::security.never'), $logoutMinutes === 0],
        ] as [$key, $label, $value, $warn])
            <div data-security-card="{{ $key }}" class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3"
                 @if ($warn) style="border-inline-start: 4px solid var(--color-warning)" @endif>
                <p class="text-xs font-semibold text-(--color-ink-muted)">{{ $label }}</p>
                <p class="tabular text-xl font-bold text-(--color-ink)">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- ── মানুষ — দুই-ধাপ, শেষ লগইন, ফোন ── --}}
        <section data-boxed data-security-people class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-sm font-semibold">{{ __('system_admin::security.people') }}</h2>
            <div class="table-responsive">
                <table class="ui-list w-full">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('system_admin::field.user_name') }}</th>
                            <th class="text-start">{{ __('system_admin::security.two_step') }}</th>
                            <th class="text-start">{{ __('system_admin::security.last_in') }}</th>
                            <th class="num">{{ __('system_admin::security.phones') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $row)
                            <tr data-security-person="{{ $row['user']->id }}">
                                <td><a href="{{ route('system_admin.user.edit', $row['user']) }}" class="font-medium text-(--color-link) hover:underline">{{ $row['user']->name }}</a>
                                    @unless ($row['user']->is_active)<span class="ms-1 text-2xs text-(--color-ink-muted)">({{ __('core.state.inactive') }})</span>@endunless</td>
                                <td>
                                    <span data-two-step="{{ $row['two_step'] ? 'on' : ($row['required'] ? 'required' : 'off') }}" @class([
                                        'rounded-full px-2 py-0.5 text-2xs font-semibold',
                                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $row['two_step'],
                                        'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => ! $row['two_step'],
                                    ])>{{ $row['two_step'] ? __('system_admin::security.on') : ($row['required'] ? __('system_admin::security.required_not_set') : __('system_admin::security.off')) }}</span>
                                </td>
                                <td class="tabular text-xs">{{ $when($row['last_in']) }}</td>
                                <td class="num">{{ $row['phones'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ── ভুল লগইন, গত সাত দিন ── --}}
        <section data-boxed data-security-failed class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="flex items-baseline justify-between gap-2 border-b border-(--color-border) px-4 py-3 text-sm font-semibold">
                {{ __('system_admin::security.failed_week') }}
                @if (\Illuminate\Support\Facades\Route::has('governance.login.index') && auth()->user()->can('governance.view'))
                    <a href="{{ route('governance.login.index') }}" class="text-xs font-normal text-(--color-link)">{{ __('system_admin::security.all_logins') }} →</a>
                @endif
            </h2>
            @forelse ($failed as $try)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-(--color-border) px-4 py-2 text-sm last:border-b-0">
                    <span class="font-medium">{{ $try->identifier }}</span>
                    <span class="text-xs text-(--color-ink-muted)">{{ __('system_admin::security.reason_'.$try->reason) }}</span>
                    <span class="tabular text-2xs text-(--color-ink-muted)">{{ $try->ip_address }}</span>
                    <span class="tabular ms-auto text-2xs text-(--color-ink-muted)">{{ $when($try->created_at) }}</span>
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-(--color-ink-muted)">{{ __('system_admin::security.no_failed') }}</p>
            @endforelse
        </section>
    </div>

    {{-- ── আপনাআপনি লগআউট — বদলানো কন্ট্রোল প্যানেলে ── --}}
    <p data-security-logout class="mt-4 text-sm text-(--color-ink-muted)">
        {{ __('system_admin::settings.auto_logout_minutes_hint') }}
        <a href="{{ route('system_admin.control-panel', ['find' => __('system_admin::settings.auto_logout_minutes')]) }}" class="font-semibold text-(--color-link)">{{ __('system_admin::security.change') }} →</a>
    </p>
</x-layouts.app>
