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

    $previous = \App\Core\Engines\Report\ReportEngine::COMPARE_PREVIOUS;
    $lastYear = \App\Core\Engines\Report\ReportEngine::COMPARE_LAST_YEAR;

    /*
        কলাম আসে ব্যবহারকারী ধরে, সংজ্ঞা থেকে সরাসরি নয়।

        ক্রয়মূল্য ও মুনাফার কলামগুলো অনুমতির পেছনে (`permission` ঘরে
        লেখা)। এখানে একবার ছেঁকে নিলে পর্দা, রপ্তানি ও ছাপা তিনটাই একই
        তালিকা পায় — রপ্তানি এই টেবিলটার কলামই ধরে নেয়। তিন জায়গায়
        আলাদা করে লিখলে একদিন একটায় লেখা হত আর বাকি দুইটায় নয়।
    */
    $columns = $result->columnsFor(auth()->user());

    /*
        রপ্তানি — ঠিক এই কলামগুলো নিয়েই।

        তালিকার পর্দাগুলোয় কাজটা x-ui.table নিজে করে; এই পর্দাটা নিজের
        টেবিল আঁকে, তাই জমাটা এখানে হাতে দিতে হয়। এতদিন হয়নি, আর
        "Export CSV"-তে ক্লিক করলে একই HTML পাতাই ফিরে আসত।

        উপরের ছাঁকা তালিকাটাই পাঠানো হয়, নতুন করে বাছা হয় না — নাহলে
        পর্দায় ঢাকা একটা সংখ্যা ফাইলে বেরিয়ে যেত।
    */
    \App\Core\Engines\Report\ReportExport::capture($result, $columns);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($report->title) }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__($report->title)" :count="trans_choice('accounts::message.row_count', $result->totalRows, ['count' => $result->totalRows])" :search="false">
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
                            <x-ui.date name="from"
                                       value="{{ $filters['from'] ?? '' }}"
                                       class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                                       </label>
                                       @endunless

                    <label class="flex items-center gap-2 text-sm">
                        @if ($report->isAsOfDate())
                            <span class="text-(--color-ink-muted)">{{ __('accounts::field.as_on') }}</span>
                        @endif
                        <span class="sr-only">{{ __('accounts::field.to_date') }}</span>
                        <x-ui.date name="to"
                                   value="{{ $filters['to'] ?? '' }}"
                                   class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                                   </label>
                                   @endif

                @if ($accounts->isNotEmpty())
                    <label class="min-w-0 flex-1 sm:max-w-xs">
                        <span class="sr-only">{{ __('core.print.account') }}</span>
                        <select name="account_id"
                                class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
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
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
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

                {{--
                    পক্ষের ধরন — "ট্রান্সপোর্টারদের কত দিতে হবে"।

                    ── কেন ছয়টা আলাদা খতিয়ান নয় ─────────────────────
                    পরিকল্পনায় লেখা ছিল ডিপোর ছয়টা বিশেষ খতিয়ান — ভাড়া
                    গাড়ি, ট্রান্সপোর্ট ভেন্ডর, শ্রমিক ঠিকাদার, দালাল…
                    কিন্তু ওরা সবাই **পক্ষ**, আর পক্ষের ধরন আগে থেকেই
                    একটা খোলা তালিকা।

                    ছয়টা আলাদা পর্দা বানালে সপ্তম ধরনটার দিন আবার কোড
                    লিখতে হত। ছাঁকনি হলে কোম্পানি নিজে একটা ধরন যোগ
                    করলেই তার খতিয়ান পেয়ে যায় — কোড ছোঁয়া ছাড়াই।
                --}}
                @if ($partyTypes->isNotEmpty())
                    <label>
                        <span class="sr-only">{{ __('master_data::menu.party_types') }}</span>
                        <select name="party_type_id"
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="">{{ __('master_data::menu.party_types') }}</option>
                            @foreach ($partyTypes as $type)
                                <option value="{{ $type->id }}"
                                        @selected(($filters['party_type_id'] ?? null) == $type->id)>
                                    {{ $type->name() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                {{--
                    তুলনা ও "উপরের কয়টা" — কেবল যেসব রিপোর্টে প্রশ্নটার
                    মানে আছে।

                    দুইটাই `rankBy` থাকলে তবেই: "সবচেয়ে বড়" বলে কিছু না
                    থাকলে উপরের দশটা মানে কিছুই নয়, আর জোড়া বাঁধার চাবি
                    ছাড়া তুলনাও হয় না। ডে বুকে ঘর দুইটা তাই দেখাই যায় না।
                --}}
                @if ($report->rankBy !== null)
                    <label>
                        <span class="sr-only">{{ __('core.report.compare') }}</span>
                        <select name="compare"
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="">{{ __('core.report.compare_none') }}</option>
                            @foreach ([$previous, $lastYear] as $option)
                                <option value="{{ $option }}"
                                        @selected(($filters['compare'] ?? null) === $option)>
                                    {{ __('core.report.compare_'.$option) }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span class="sr-only">{{ __('core.report.top') }}</span>
                        <select name="top"
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 text-sm">
                            <option value="">{{ __('core.report.top_all') }}</option>
                            @foreach ([5, 10, 20, 50] as $n)
                                <option value="{{ $n }}" @selected((int) ($filters['top'] ?? 0) === $n)>
                                    {{ __('core.report.top_n', ['count' => $n]) }}
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

        {{--
            কতগুলো সারি বাদ পড়ল, সেটা লেখা থাকে।

            চুপচাপ দশটা দেখালে তালিকাটা পড়ে মনে হত ওইটুকুই সব — আর
            "২৪০-এর মধ্যে ১০" আর "১০টা সারি" দুইটা সম্পূর্ণ আলাদা কথা।
            যোগফলের সারিটা কিন্তু পুরো ২৪০-এরই, তাই না লিখলে সংখ্যা দুইটা
            মেলাতে গিয়ে কেউ ভাবত হিসাব ভুল।
        --}}
        @if ($result->isTopOnly())
            <p class="border-b border-(--color-border) bg-(--color-surface-app) px-4 py-2 text-2xs
                      text-(--color-ink-muted)">
                {{ __('core.report.showing_top', [
                    'count' => $result->totalRows,
                    'total' => $result->fullRowCount,
                ]) }}
            </p>
        @endif

        @if ($result->rows === [])
            <x-ui.empty-state :message="__('accounts::message.nothing_in_range')" />
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col"
                                    @class([
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
                            <tr class="transition-colors hover:bg-(--color-surface-hover)">
                                @foreach ($columns as $column)
                                    <td @class([
                                        'align-middle',
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
                        <tr>
                            @foreach ($columns as $index => $column)
                                <td @class(['num' => in_array($column->type, [$money], true)])>
                                    @if ($index === 0)
                                        {{ __('core.print.total') }}
                                    @elseif ($column->total && isset($result->totals[$column->key]))
                                        {{ \App\Core\Support\Money::format($result->totals[$column->key]) }}
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
