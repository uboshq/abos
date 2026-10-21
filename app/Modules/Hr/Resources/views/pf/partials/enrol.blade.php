{{-- ⭐ তহবিলে যোগ — একজন, পাতার তারিখ থেকে; বেতন বদলানোর চাবিতে (BLA ধারা ২৬৪; [[ProvidentFund::enrol()]]) --}}
@can('hr.salary.manage')
    <form method="POST" action="{{ route('hr.pf.enrol', $employee->id) }}">
        @csrf
        <input type="hidden" name="effective_from" value="{{ $on->toDateString() }}">
        <x-ui.button type="submit">{{ __('hr::action.pf_enrol') }}</x-ui.button>
    </form>
@else
    —
@endcan
