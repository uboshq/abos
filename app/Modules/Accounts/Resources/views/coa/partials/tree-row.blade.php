{{--
    গাছের একটা সারি, আর তার নিচেরগুলো।

    ইন্ডেন্ট padding দিয়ে, নেস্টেড টেবিল দিয়ে নয়: নেস্টেড টেবিলে কলামগুলো
    প্রতিটা স্তরে আলাদা প্রস্থ পেত, আর ব্যালেন্সের সংখ্যাগুলো এক লাইনে
    থাকত না। এক টেবিল, এক কলাম-প্রস্থ, শুধু নামের ঘরে ধাপ।

    গভীরতার সীমা নেই কারণ ছক নিজেই তিন-চার স্তরের; AccountService চক্র
    তৈরি হতে দেয় না, তাই অসীম পুনরাবৃত্তির পথ নেই।
--}}
@php
    $indent = $depth * 1.25;
    $balance = $balances[$account->id] ?? '0';
@endphp

<tr class="border-b border-(--color-border) transition-colors hover:bg-(--color-surface-hover)">
    <td>
        <span class="flex items-center gap-2" style="padding-inline-start: {{ $indent }}rem">
            {{-- গ্রুপ আর সাধারণ খাত এক নজরে আলাদা: গ্রুপ মোটা হরফে, আর
                 তার আগে একটা রেখা — নাহলে ইন্ডেন্ট গুনে বুঝতে হত --}}
            @if ($account->is_group)
                <span aria-hidden="true" class="text-(--color-ink-placeholder)">▾</span>
            @else
                <span aria-hidden="true" class="w-3"></span>
            @endif

            <a href="{{ route('accounts.coa.show', $account) }}"
               @class([
                   'min-w-0 truncate underline-offset-2 hover:underline',
                   'font-semibold' => $account->is_group,
                   'text-(--color-ink-muted)' => ! $account->is_active,
               ])>
                <span class="num text-(--color-ink-muted)">{{ $account->code }}</span>
                {{ $account->name() }}
            </a>

            @if ($account->is_system)
                <span title="{{ __('accounts::message.system_account') }}"
                      class="shrink-0 text-2xs text-(--color-ink-placeholder)" aria-hidden="true">🔒</span>
            @endif

            @unless ($account->is_active)
                <x-ui.badge tone="neutral">{{ __('customer::state.inactive') }}</x-ui.badge>
            @endunless
        </span>
    </td>

    <td class="hidden whitespace-nowrap text-(--color-ink-muted) sm:table-cell">
        {{ __('accounts::type.' . $account->type) }}
    </td>

    {{-- গ্রুপের ব্যালেন্সও দেখানো হয় — সেটাই তার নিচের সবার যোগফল, আর
         না দেখালে "চলতি সম্পদ কত" প্রশ্নের উত্তর পর্দায় থাকত না --}}
    <td @class(['num', 'font-semibold' => $account->is_group])>
        {{-- অঙ্কটাই লিংক — নিয়ম ১। খাতের পাতায় ঠিক সেই এন্ট্রিগুলো
             আছে যেগুলো যোগ হয়ে এই সংখ্যাটা হয়েছে। --}}
        <x-ui.amount :value="$balance" :href="route('accounts.coa.show', $account)" />
    </td>

    <td class="text-end">
        @if ($account->is_group)
            @can('create', \App\Modules\Accounts\Models\Account::class)
                {{-- এই মাথার নিচেই নতুন খাত — বাবা আগে থেকে বাছা থাকে --}}
                {{-- নামটা aria-label-এ, ভেতরে sr-only স্প্যানে নয়।

                     sr-only মানে position: absolute, আর positioned পূর্বপুরুষ
                     না থাকলে সেটা স্ক্রল-কনটেইনারের ক্লিপিং এড়িয়ে পাতার
                     স্থানাঙ্কে গিয়ে বসে — ৩৭৫px-এ পাতাটা ১৯px উপচে পড়ছিল
                     ঠিক এই স্প্যানগুলোর কারণে। aria-label-এ কোনো বাক্স নেই। --}}
                <a href="{{ route('accounts.coa.create', ['parent' => $account->id]) }}"
                   class="inline-flex size-8 items-center justify-center rounded-(--radius-field)
                          text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)"
                   aria-label="{{ __('accounts::action.new_account') }} — {{ $account->name() }}"
                   title="{{ __('accounts::action.new_account') }}">
                    <span aria-hidden="true">+</span>
                </a>
            @endcan
        @endif
    </td>
</tr>

@foreach ($account->children as $child)
    @include('accounts::coa.partials.tree-row', ['account' => $child, 'depth' => $depth + 1])
@endforeach
