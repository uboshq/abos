{{--
    খোলার কারণটা এখানেই চাওয়া হয়, আলাদা পাতায় নয় — এক ক্লিকে খোলা
    যাওয়াটাই আসল বিপদ, আর একটা ঘর ভরা সেটার সবচেয়ে সস্তা প্রতিরোধ।
--}}
@if ($month['lock'])
    @can('accounts.period.reopen')
        <form method="POST" action="{{ route('accounts.period.reopen', $month['lock']) }}"
              class="flex flex-wrap items-center justify-end gap-2">
            @csrf
            <input type="text" name="reason" required minlength="3"
                   placeholder="{{ __('accounts::field.reopen_reason') }}"
                   class="h-(--spacing-field) w-56 rounded-(--radius-field)
                          border border-(--color-border) bg-(--color-surface-app) px-2">
            <x-ui.button type="submit" tone="secondary">{{ __('accounts::action.reopen') }}</x-ui.button>
        </form>
    @endcan
@else
    <form method="POST" action="{{ route('accounts.period.close') }}"
          class="flex flex-wrap items-center justify-end gap-2">
        @csrf
        <input type="hidden" name="year" value="{{ $month['year'] }}">
        <input type="hidden" name="month" value="{{ $month['month'] }}">
        <input type="text" name="reason" placeholder="{{ __('accounts::field.reason') }}"
               class="h-(--spacing-field) w-56 rounded-(--radius-field)
                      border border-(--color-border) bg-(--color-surface-app) px-2">
        <x-ui.button type="submit" tone="primary">{{ __('accounts::action.close_month') }}</x-ui.button>
    </form>
@endif
