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

    {{-- কী কী চিহ্ন লেখা যায় তা দেখানো হয়। না দেখালে ব্যবহারকারী
         অনুমান করতেন, আর ভুল চিহ্ন হুবহু নম্বরে বসে যেত। --}}
    <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-4">
        <p class="mb-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
            {{ __('master_data::message.series_placeholders') }}
        </p>

        <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($placeholders as $token => $label)
                <div class="flex items-baseline gap-2">
                    <dt class="num text-2xs font-medium">{{ $token }}</dt>
                    <dd class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead>
                    <tr>
                        <th scope="col">
                            {{ __('master_data::field.doc_type') }}
                        </th>
                        <th scope="col" style="width: 8rem"
>
                            {{ __('master_data::field.prefix') }}
                        </th>
                        <th scope="col" style="width: 7rem"
>
                            {{ __('master_data::field.suffix') }}
                        </th>
                        <th scope="col" style="width: 16rem"
>
                            {{ __('master_data::field.format') }}
                        </th>
                        <th scope="col" style="width: 6rem"
                            class="num">
                            {{ __('master_data::field.padding') }}
                        </th>
                        <th scope="col" style="width: 8rem"
>
                            {{ __('master_data::field.reset_yearly') }}
                        </th>
                        <th scope="col" style="width: 13rem"
>
                            {{ __('master_data::field.sample') }}
                        </th>
                        <th scope="col" style="width: 7rem"></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($series as $row)
                        <tr>
                            <form method="POST" action="{{ route('master_data.series.update', $row) }}"
                                  class="contents">
                                @csrf
                                @method('PUT')

                                <td>
                                    <span class="block">
                                        {{ isset($labels[$row->doc_type]) ? __($labels[$row->doc_type]) : $row->doc_type }}
                                    </span>
                                    <span class="block text-2xs text-(--color-ink-muted)">
                                        {{ $row->module }} · {{ $row->doc_type }}
                                    </span>
                                </td>

                                <td>
                                    <span class="sr-only">{{ __('master_data::field.prefix') }}</span>
                                    <input type="text" name="prefix" value="{{ $row->prefix }}"
                                           aria-label="{{ __('master_data::field.prefix') }}"
                                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2">
                                </td>

                                <td>
                                    <input type="text" name="suffix" value="{{ $row->suffix }}"
                                           aria-label="{{ __('master_data::field.suffix') }}"
                                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2">
                                </td>

                                <td>
                                    <input type="text" name="format" value="{{ $row->format }}"
                                           aria-label="{{ __('master_data::field.format') }}"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
                                </td>

                                <td>
                                    <input type="number" name="padding" value="{{ $row->padding }}"
                                           min="1" max="12" inputmode="numeric"
                                           aria-label="{{ __('master_data::field.padding') }}"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td>
                                    <label class="flex min-h-(--spacing-touch) items-center gap-2">
                                        <input type="checkbox" name="reset_yearly" value="1"
                                               @checked($row->reset_yearly) class="size-4">
                                        <span class="text-2xs text-(--color-ink-muted)">
                                            {{ $row->reset_yearly ? __('core.yes') : __('core.no') }}
                                        </span>
                                    </label>
                                </td>

                                {{-- নমুনাটা ইঞ্জিনের নিজের কোড থেকে আসে।

                                     আগে এখানে "{prefix}-{বছর}-{ক্রম}" হাতে জোড়া
                                     হত, অথচ ছকটা অন্য কিছু হলে নমুনাটা মিথ্যা
                                     বলত — ব্যবহারকারী একটা দেখে সেভ করতেন, আর
                                     ডকুমেন্টে বসত অন্যটা। --}}
                                <td>
                                    <span class="num text-2xs text-(--color-ink-muted)">
                                        {{ $engine->preview($row) }}
                                    </span>
                                </td>

                                <td class="text-end">
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
