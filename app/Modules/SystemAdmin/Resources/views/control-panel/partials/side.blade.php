{{--
    ⭐ কন্ট্রোল প্যানেলের কাঠামো — বাঁয়ে দলবদ্ধ তালিকা আর খোঁজা, ডানে পর্দা (সিস্টেম পর্দার নকশা §১, ১০ অক্টোবর ২০২৬:
    *"১৯টা ট্যাবের বদলে বাঁয়ে দলবদ্ধ তালিকা আর উপরে খোঁজার ঘর — কোন সুইচ কোথায়, খুঁজলেই পাওয়া যাবে"*)।

    ⓘ আগে উনিশটা ট্যাব দুই সারিতে ভেঙে পর্দার উপরে বসত। ⓘ সারিগুলো আর ঠিকানা হুবহু আগের ([[ControlPanelTabs::groups()]]) —
    কেবল জায়গা বদলেছে; বুকমার্ক অক্ষত। ছাপার দুই পর্দাও একই কাঠামো পরে, তাই তিনটা পর্দা একই রকম।

    ⓘ খোঁজা সার্ভারে (`?find=`), জাভাস্ক্রিপ্ট ছাড়া — ফল ডানে, প্রতিটার পাশে কোন ট্যাবে আর সেখানে যাওয়ার লিংক।
    ডাকা: component-নির্দেশ দিয়ে, এই ভিউর নাম আর ['tab' => $tab] সহ, পর্দার পুরো অংশ ঘিরে (মন্তব্যে নির্দেশের নাম @ সহ লেখা যায় না —
    Blade মন্তব্য ফেলার আগেই নির্দেশ খোঁজে)।
--}}
@php
    $panel = app(\App\Modules\SystemAdmin\Support\ControlPanelTabs::class);
    $find = trim((string) request()->query('find', ''));
    $found = $panel->find($find);
@endphp

{{-- ⓘ শ্রেণিগুলো বিল্ড করা বান্ডলে আগে থেকেই আছে (AClassTheBundleNeverHeardOfDoesNothingTest) — লাইভে node নেই, নতুন শ্রেণি চুপচাপ কিছুই করত না --}}
<div class="grid items-start gap-4 lg:grid-cols-[16.25rem_minmax(0,1fr)]" data-control-frame>
    <aside class="print-hide">
        {{-- ⓘ action নেই — খোঁজা সবসময় কন্ট্রোল প্যানেলেই যায় (ছাপার পর্দা থেকেও), কিন্তু একই ঠিকানা লিখলে সংরক্ষণের
             ফর্মটা আর "একটাই" থাকত না (TheSalesTabSavedZeroIntoEveryChoiceTest সংরক্ষণের ফর্ম ঠিকানা ধরে খোঁজে) --}}
        <form method="GET" role="search" class="mb-3" data-control-search
              @if (! request()->routeIs('system_admin.control-panel')) action="{{ route('system_admin.control-panel') }}" @endif>
            <input type="search" name="find" value="{{ $find }}" placeholder="{{ __('system_admin::control.find') }}"
                   aria-label="{{ __('system_admin::control.find') }}" data-control-find
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 text-sm">
        </form>

        <nav aria-label="{{ __('system_admin::menu.control_panel') }}" class="space-y-3">
            @foreach ($panel->groups() as $group)
                <div>
                    <p class="mb-1 px-2 text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">{{ $group['label'] }}</p>
                    @foreach ($group['tabs'] as $one)
                        <a href="{{ $one['url'] }}" data-control-tab="{{ $one['key'] }}"
                           @class([
                               'flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm transition-colors',
                               'bg-(--color-surface-selected) font-semibold text-(--color-ink)' => $tab === $one['key'],
                               'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => $tab !== $one['key'],
                           ])
                           @if ($tab === $one['key']) aria-current="page" @endif>
                            {{ $one['label'] }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
    </aside>

    <div class="min-w-0">
        @if ($find !== '')
            <section data-control-found class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @if ($found === [])
                    <p class="text-sm text-(--color-ink-muted)">{{ __('system_admin::control.find_none', ['find' => $find]) }}</p>
                @else
                    <h2 class="mb-2 text-sm font-semibold">{{ __('system_admin::control.find_results', ['find' => $find, 'count' => count($found)]) }}</h2>
                    <ul class="space-y-1">
                        @foreach ($found as $hit)
                            <li class="text-sm">
                                <a href="{{ $hit['url'] }}" class="text-(--color-link) hover:underline">{{ $hit['label'] }}</a>
                                <span class="text-2xs text-(--color-ink-muted)">— {{ __('system_admin::control.find_in', ['place' => $hit['place']]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{ $slot }}
    </div>
</div>
