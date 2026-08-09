{{--
    আটটা রিপোর্টের একটাই পর্দা।

    কলাম, ফিল্টার ও যোগফল সবই সংজ্ঞা থেকে আসে, তাই নবম রিপোর্ট যোগ
    করতে এই ফাইলটা ছুঁতে হয় না — সেটাই Report engine থাকার কারণ
    (সেকশন ২.২)।

    যোগফল পুরো ফলের উপর, এই পাতার নয়। পাতাভিত্তিক যোগফল ভুল উত্তর দেয়,
    আর সেটা ভুল বলে চেনাও যায় না — তাই engine আলাদা কোয়েরিতে গোনে।
--}}
@php
    $money = \App\Core\Engines\Report\ReportColumn::MONEY;
    $date = \App\Core\Engines\Report\ReportColumn::DATE;
    $document = \App\Core\Engines\Report\ReportColumn::DOCUMENT;

    $filters = $result->filters;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($report->title) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__($report->title)"
            :subtitle="trans_choice('accounts::message.row_count', $result->totalRows, ['count' => $result->totalRows])" />
    </x-slot:header>

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :search="false">
                @if ($report->hasFilter('date_range'))
                    {{--
                        জেরের রিপোর্টে "কবে থেকে" বলে কিছু নেই।

                        রেওয়ামিল আর ব্যালেন্স শিট একটা তারিখ **পর্যন্ত** জের
                        দেখায় — শুরুর তারিখ ওখানে অর্থহীন। তবু ঘরটা দেখানো
                        হত, আর ডিফল্টে চলতি মাসের ১ তারিখ বসানো থাকত।

                        রেওয়ামিলে সেটা একসময় সত্যিই ব্যালেন্স কেটে দিত (৮.৪
                        লাখের খোলা মজুদ ৩,৪০০ দেখাত); ওই অঙ্কের ভুলটা সারানো
                        হয়েছে। কিন্তু ঘরটা রয়ে গিয়েছিল, আর একটা নিষ্ক্রিয়
                        ঘর তারিখ দেখিয়ে বসে থাকা মানে ব্যবহারকারী ভাববেন
                        সংখ্যাটা ওই তারিখ থেকে গোনা — অর্থাৎ পর্দা একটা
                        মিথ্যা বলছে, নীরবে।
                    --}}
                    @unless ($report->isAsOfDate())
                        <label class="flex items-center gap-2 text-sm">
                            <span class="sr-only">{{ __('accounts::field.from_date') }}</span>
                            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                                   class="h-9 rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 text-sm">
                        </label>
                    @endunless

                    <label class="flex items-center gap-2 text-sm">
                        @if ($report->isAsOfDate())
                            <span class="text-(--color-ink-muted)">{{ __('accounts::field.as_on') }}</span>
                        @endif
                        <span class="sr-only">{{ __('accounts::field.to_date') }}</span>
                        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                               class="h-9 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-sm">
                    </label>
                @endif

                @if ($accounts->isNotEmpty())
                    <label class="min-w-0 flex-1 sm:max-w-xs">
                        <span class="sr-only">{{ __('core.print.account') }}</span>
                        <select name="account_id"
                                class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="">{{ __('core.print.account') }} — {{ __('core.action.more') }}</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}"
                                        @selected(($filters['account_id'] ?? null) == $account->id)>
                                    {{ $account->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if ($branches->isNotEmpty())
                    <label>
                        <span class="sr-only">{{ __('core.company.branch') }}</span>
                        <select name="branch_id"
                                class="h-9 rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="">{{ __('core.company.branch') }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}"
                                        @selected(($filters['branch_id'] ?? null) == $branch->id)>
                                    {{ $branch->name() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                {{-- জমা দেওয়ার বোতামটা এখানে ছিল, এখন টুলবারের ফিল্টার
                     প্যানেলের নিজেরই একটা আছে — দুইটা থাকলে একই সারিতে
                     দুইবার "খুঁজুন" দেখাত --}}
            </x-ui.toolbar>
        </form>

        @if ($result->rows === [])
            <x-ui.empty-state :message="__('accounts::message.nothing_in_range')" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                            @foreach ($report->columns as $column)
                                <th scope="col"
                                    @class([
                                        'px-3 py-2 text-start font-medium text-(--color-ink-muted)',
                                        'num' => in_array($column->type, [$money], true),
                                        'whitespace-nowrap' => $column->type === $date,
                                    ])
                                    @if ($column->width) style="width: {{ $column->width }}" @endif>
                                    {{ __($column->label) }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($result->rows as $row)
                            <tr class="border-b border-(--color-border) transition-colors
                                       hover:bg-(--color-surface-hover)">
                                @foreach ($report->columns as $column)
                                    <td @class([
                                        'px-3 py-2 align-middle',
                                        'num' => in_array($column->type, [$money], true),
                                        'whitespace-nowrap' => $column->type === $date,
                                    ])>
                                        @include('accounts::report.partials.cell', [
                                            'column' => $column,
                                            'row' => $row,
                                        ])
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr class="bg-(--color-surface-app) font-semibold">
                            @foreach ($report->columns as $index => $column)
                                <td @class(['px-3 py-2', 'num' => in_array($column->type, [$money], true)])>
                                    @if ($index === 0)
                                        {{ __('core.print.total') }}
                                    @elseif ($column->total && isset($result->totals[$column->key]))
                                        {{ number_format((float) $result->totals[$column->key], 2) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($result->totalRows > $result->perPage)
                {{-- পাতা ভাগ করা হলে সেটা বলা দরকার, নাহলে ব্যবহারকারী
                     ভাবত যোগফলটাও শুধু এই পাতার — অথচ ওটা পুরো ফলের --}}
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-(--color-border)
                            px-3 py-2 text-sm">
                    <span class="text-(--color-ink-muted)">
                        {{ __('accounts::message.page_of', [
                            'page' => $result->page,
                            'pages' => (int) ceil($result->totalRows / $result->perPage),
                        ]) }}
                    </span>

                    <span class="flex gap-2">
                        @if ($result->page > 1)
                            <a href="{{ request()->fullUrlWithQuery(['page' => $result->page - 1]) }}"
                               class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                ← {{ __('core.action.more') }}
                            </a>
                        @endif

                        @if ($result->page * $result->perPage < $result->totalRows)
                            <a href="{{ request()->fullUrlWithQuery(['page' => $result->page + 1]) }}"
                               class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                {{ __('core.action.more') }} →
                            </a>
                        @endif
                    </span>
                </div>
            @endif
        @endif
    </div>
</x-layouts.app>
