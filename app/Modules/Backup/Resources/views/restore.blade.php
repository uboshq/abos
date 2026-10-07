{{--
    ফিরিয়ে আনা — কোন ফাইল থেকে, আর সেটা যাচাই-করা কি না।

    ⛔ বোতাম নেই, ইচ্ছাকৃত: ফেরানো মানে এর পরের সব কাজ মুছে যাওয়া। ⓘ
    পর্দা দেয় দুইটা জিনিস যা দরকারের দিন মনে করতে হয় না — কোন ফাইলটা
    একবার ফিরিয়ে এনে দেখা হয়েছে, আর হুবহু কমান্ডটা। কমান্ডটা ফেরানোর
    আগে নিজেই একটা নিরাপত্তা-ডাম্প নেয় (`abos:restore`, `--no-safety` ছাড়া)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.restore_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.restore_title')"
                          :subtitle="__('backup::screen.restore_subtitle')" />
    </x-slot:header>

    <p class="mb-3 max-w-(--spacing-prose-max) rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
        {{ __('backup::screen.restore_why_no_button') }}
    </p>

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
</x-layouts.app>
