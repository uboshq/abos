@props(['menu' => []])

{{--
    সাইডবার — সেকশন ১৫.২ ও ২০.২।

    মোবাইলে একেবারে লুকানো (নিচে bottom nav থাকে), ট্যাবলেটে আইকন-only ৬৪px,
    ল্যাপটপে পূর্ণ ২২০px, বড় মনিটরে ২৬০px। একই মার্কআপ, শুধু CSS বদলায়।
--}}
<aside class="hidden shrink-0 flex-col bg-(--color-sidebar) text-(--color-sidebar-icon)
              md:flex md:w-(--spacing-sidebar-icon)
              lg:w-(--spacing-sidebar)
              xl:w-(--spacing-sidebar-wide)">

    {{-- লোগো: সংকুচিত অবস্থায় শুধু মনোগ্রাম, খোলা অবস্থায় লকআপ (সেকশন ১৭.২) --}}
    <div class="flex h-(--spacing-header) items-center justify-center border-b border-(--color-sidebar-border) px-3 lg:justify-start">
        <img src="{{ asset('brand/abos-icon-64.png') }}" alt=""
             class="h-(--spacing-logo-sidebar) w-(--spacing-logo-sidebar) shrink-0" aria-hidden="true">
        <span class="ms-2 hidden min-w-0 lg:block">
            <span class="block truncate font-semibold text-(--color-ink-inverse)">ABOS</span>
            <span class="block truncate text-2xs text-(--color-sidebar-icon)">
                {{ __('core.brand.full_name') }}
            </span>
        </span>
    </div>

    <nav class="flex-1 overflow-y-auto py-2" aria-label="{{ __('core.a11y.main_navigation') }}">
        @foreach ($menu as $module)
            <div class="mb-1">
                {{-- মডিউলের নাম — সংকুচিত সাইডবারে লুকানো, কারণ ৬৪px-এ
                     লেখা ধরে না আর কেটে গেলে পড়া যায় না --}}
                <p class="hidden px-3 pt-3 pb-1 text-2xs font-semibold tracking-wide
                          text-(--color-sidebar-icon)/70 uppercase lg:block">
                    {{ $module['label'] }}
                </p>

                @foreach ($module['groups'] as $group => $items)
                    @foreach ($items as $item)
                        <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                           @class([
                               'flex min-h-(--spacing-touch) items-center gap-3 px-3 text-sm transition-colors',
                               'justify-center lg:justify-start',
                               'bg-(--color-sidebar-active) text-(--color-ink-inverse)' => $item['active'],
                               'hover:bg-(--color-sidebar-hover)' => ! $item['active'] && $item['url'],
                               'cursor-not-allowed opacity-40' => ! $item['url'],
                           ])
                           @if (! $item['url']) aria-disabled="true" @endif
                           @if ($item['active']) aria-current="page" @endif
                           title="{{ $item['label'] }}">

                            <x-shell.module-icon :module="$module['code']" :group="$group" />

                            <span class="hidden min-w-0 truncate lg:block">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="border-t border-(--color-sidebar-border) px-3 py-2 text-2xs text-(--color-sidebar-icon)/70">
        <span class="hidden lg:block">{{ __('core.brand.powered_by') }}</span>
    </div>
</aside>
