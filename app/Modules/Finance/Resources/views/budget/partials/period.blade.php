{{-- সময়: গোটা বছর, বছরের শুরু থেকে এখন, বা এক মাস --}}
<x-ui.select name="month" :label="__('finance::budget.period')"
             :options="collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => __('finance::budget.month_long.'.$m)])"
             :selected="$month ?: null" :placeholder="__('finance::budget.scope_'.$scope)" />

<input type="hidden" name="scope" value="{{ $scope }}">

{{-- ⭐ অর্থবছর — জুলাই থেকে এ মাস, বছর পেরিয়ে (অর্থ M29, ১০ অক্টোবর ২০২৬) --}}
<a href="{{ request()->fullUrlWithQuery(['scope' => $scope === 'fy' ? 'ytd' : 'fy', 'month' => null]) }}" data-scope-fy
   class="text-sm {{ $scope === 'fy' ? 'font-semibold text-(--color-ink)' : 'text-(--color-ink-muted) hover:text-(--color-ink)' }}">
    {{ __('finance::budget.scope_fy') }}{{ $scope === 'fy' ? ' ✕' : '' }}
</a>

@if ($isReport)
    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
        <input type="checkbox" name="by_center" value="1" @checked($byCenter) class="size-4">
        {{ __('finance::budget.by_center') }}
    </label>
@endif
