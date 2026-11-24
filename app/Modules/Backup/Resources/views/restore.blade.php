{{--
    ফিরিয়ে আনা — কোন ফাইল থেকে, আর সেটা যাচাই-করা কি না।

    ⛔ বোতাম নেই, ইচ্ছাকৃত: ফেরানো মানে এর পরের সব কাজ মুছে যাওয়া। ⓘ
    পর্দা দেয় দুইটা জিনিস যা দরকারের দিন মনে করতে হয় না — কোন ফাইলটা
    একবার ফিরিয়ে এনে দেখা হয়েছে, আর হুবহু কমান্ডটা। কমান্ডটা ফেরানোর
    আগে নিজেই একটা নিরাপত্তা-ডাম্প নেয় (`abos:restore`, `--no-safety` ছাড়া)।
--}}
@php
    $abosHead = \App\Core\Support\AbosHead::on();
    $columns = [
        ['key' => 'file', 'label' => __('backup::screen.col_file'), 'wrap' => true, 'render' => fn ($f) => new \Illuminate\Support\HtmlString('<span class="font-mono text-2xs">'.e($f['name']).'</span>')],
        ['key' => 'when', 'label' => __('backup::screen.col_when'), 'width' => '11rem', 'render' => fn ($f) => \App\Core\Support\DateFormat::formatWithTime($f['at'])],
        ['key' => 'check', 'label' => __('backup::screen.col_check'), 'render' => fn ($f) => view('backup::partials.file-check', ['file' => $f])],
        ['key' => 'command', 'label' => __('backup::screen.command'), 'wrap' => true, 'render' => fn ($f) => new \Illuminate\Support\HtmlString('<code class="font-mono text-2xs break-all">php artisan abos:restore '.e($f['name']).'</code>')],
    ];
    $facetPanel = \App\Core\ListFilter\Facets::panel($abosHead ? [\App\Core\ListFilter\Facets::pick('check', __('backup::screen.col_check'),
        ['ok' => __('backup::screen.checked'), 'none' => __('backup::screen.unchecked'), 'bad' => __('backup::screen.check_broke')])] : []);
    $find = mb_strtolower(request()->string('q')->trim()->value());
    $shown = $files
        ->filter(fn ($f) => $find === '' || str_contains(mb_strtolower($f['name']), $find))
        ->filter(fn ($f) => match (request('check')) {
            'ok' => $f['check'] !== null && $f['check']->sawSomething(),
            'none' => $f['check'] === null,
            'bad' => $f['check'] !== null && ! $f['check']->sawSomething(),
            default => true,
        });
    $shown = (request('sort') === 'oldest' ? $shown->reverse() : $shown)->values();
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.restore_title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.restore_title')"
                          :subtitle="__('backup::screen.restore_subtitle')" />
    </x-slot:header>
    @endunless

    @unless ($abosHead)
        @include('backup::partials.restore-why')
    @endunless

    @if ($abosHead)
        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('backup::screen.restore_title')" :info="__('backup::screen.restore_subtitle').' '.__('backup::screen.prefer_checked')"
                              :search-placeholder="__('backup::screen.col_file')" :export="false" :print="false" :share="false" :columns="$columns" :facets="$facetPanel"
                              :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest')]" />
            </form>
            {{-- ⭐ আগে মাথা, বাক্স তার নিচে (মালিকের নিয়ম; তিন ABOS রূপে) --}}
            <div class="px-3 pt-3">@include('backup::partials.restore-why')</div>
            <x-ui.table :rows="$shown" :columns="$columns" :empty="$files->isEmpty() ? __('backup::screen.no_files') : __('core.empty.no_results')" />
        </div>
        </x-ui.filter-layout>
    @else
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        @if ($files->isEmpty())
            <p class="text-sm text-(--color-badge-danger-ink)">{{ __('backup::screen.no_files') }}</p>
        @else
            <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('backup::screen.prefer_checked') }}</p>

            <div class="overflow-x-auto">
                <table class="ui-list w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-start text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            <th class="text-start">{{ __('backup::screen.col_file') }}</th>
                            <th class="text-start">{{ __('backup::screen.col_when') }}</th>
                            <th class="text-start">{{ __('backup::screen.col_check') }}</th>
                            <th class="text-start">{{ __('backup::screen.command') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr class="border-b border-(--color-border)/50">
                                <td class="font-mono text-2xs">{{ $file['name'] }}</td>
                                <td class="num whitespace-nowrap text-2xs">
                                    {{ \App\Core\Support\DateFormat::formatWithTime($file['at']) }}
                                </td>
                                <td class="text-2xs">
                                    @if ($file['check'] === null)
                                        <span class="text-(--color-ink-muted)">{{ __('backup::screen.unchecked') }}</span>
                                    @elseif ($file['check']->sawSomething())
                                        <span class="font-semibold text-(--color-badge-success-ink)">{{ __('backup::screen.checked') }}</span>
                                    @else
                                        <span class="font-semibold text-(--color-badge-danger-ink)">{{ __('backup::screen.check_broke') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <code class="font-mono text-2xs break-all">php artisan abos:restore {{ $file['name'] }}</code>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    @endif
</x-layouts.app>
