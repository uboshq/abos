{{--
    ডকুমেন্ট নম্বর সিরিজ।

    "নতুন" বোতাম নেই: সিরিজগুলো মডিউলের ঘোষণা থেকে নিজে থেকে তৈরি হয়।
    হাতে বানাতে দিলে একটা ঘোষিত ডকুমেন্টের সিরিজ বাদ পড়ে থাকতে পারত,
    আর সেটা ধরা পড়ত প্রথম ব্যবহারকারী ওই ফর্মটা খোলার দিন।

    পরের নম্বরের ঘরটাও নেই, আর সেটাও ইচ্ছাকৃত — পিছিয়ে দিলে একই নম্বর
    দুইবার ইস্যু হত, এগিয়ে দিলে অডিটে অব্যাখ্যাত ফাঁক থাকত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('master_data::menu.number_series') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('master_data::menu.number_series')"
                          :subtitle="__('master_data::message.series_note')" />
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
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('master_data::field.doc_type') }}
                        </th>
                        <th scope="col" style="width: 10rem"
                            class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('master_data::field.prefix') }}
                        </th>
                        <th scope="col" style="width: 8rem"
                            class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('master_data::field.next_number') }}
                        </th>
                        <th scope="col" style="width: 12rem"
                            class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('core.print.document_no') }}
                        </th>
                        <th scope="col" style="width: 7rem" class="px-3 py-2"></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($series as $row)
                        <tr class="border-b border-(--color-border)">
                            <form method="POST" action="{{ route('master_data.series.update', $row) }}"
                                  class="contents">
                                @csrf
                                @method('PUT')

                                <td class="px-3 py-2">
                                    <span class="block">
                                        {{ isset($labels[$row->doc_type]) ? __($labels[$row->doc_type]) : $row->doc_type }}
                                    </span>
                                    <span class="block text-2xs text-(--color-ink-muted)">
                                        {{ $row->module }} · {{ $row->doc_type }}
                                    </span>
                                </td>

                                <td class="px-3 py-2">
                                    <input type="text" name="prefix" value="{{ $row->prefix }}"
                                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2">
                                </td>

                                {{-- পরের নম্বরটা দেখানো হয়, বদলানো যায় না --}}
                                <td class="num px-3 py-2 text-(--color-ink-muted)">
                                    {{ number_format($row->next_number) }}
                                </td>

                                <td class="px-3 py-2">
                                    <span class="num text-2xs text-(--color-ink-muted)">
                                        {{ $row->prefix }}-{{ $row->financialYear?->name ?? '…' }}-{{ str_pad((string) $row->next_number, (int) $row->padding, '0', STR_PAD_LEFT) }}
                                    </span>
                                    <input type="hidden" name="padding" value="{{ $row->padding }}">
                                </td>

                                <td class="px-3 py-2 text-end">
                                    <button type="submit"
                                            class="rounded-(--radius-field) px-2 py-1 text-2xs text-(--color-brand-500)
                                                   transition-colors hover:bg-(--color-surface-hover)">
                                        {{ __('core.action.save') }}
                                    </button>
                                </td>
                            </form>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
