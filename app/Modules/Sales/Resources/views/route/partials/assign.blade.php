{{--
    ছকে বসানো — একজন মানুষ, এক বা একাধিক বার, একটা সময়।

    ⓘ বারগুলো একসাথে টিক দেওয়া যায়: SR সাধারণত সপ্তাহে দুই-তিন দিন
    একই রুটে যান, আর প্রতিটা বারের জন্য আলাদা ফর্ম জমা দিতে হলে ছক বসাতে
    সকাল লেগে যেত।
--}}
@php
    use App\Modules\Sales\Models\RouteVisit;

    $picked = array_map('intval', (array) old('weekdays', []));
@endphp

<form method="POST" action="{{ route('sales.route.visit.assign', $route) }}"
      class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
    @csrf

    <h3 class="mb-3 font-semibold">{{ __('sales::route.assign') }}</h3>

    <div class="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.select name="user_id" :label="__('sales::route.who')" required
                     :options="$candidates->pluck('name', 'id')->all()"
                     :selected="old('user_id')"
                     :placeholder="__('sales::route.pick_user')" />

        <x-ui.field name="effective_from" type="date" required
                    :label="__('sales::route.from')"
                    :value="old('effective_from', $today->toDateString())" />

        <x-ui.field name="effective_to" type="date"
                    :label="__('sales::route.to')"
                    :value="old('effective_to')" />

        <x-ui.field name="narration" :label="__('sales::route.narration')" :value="old('narration')" />
    </div>

    <fieldset class="mb-3">
        <legend class="mb-1 text-sm text-(--color-ink-muted)">{{ __('sales::route.weekday') }}</legend>
        <div class="flex flex-wrap gap-3">
            @foreach (RouteVisit::WEEK as $day)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="weekdays[]" value="{{ $day }}" @checked(in_array($day, $picked, true)) class="size-4">
                    {{ __('sales::route.weekdays.'.$day) }}
                </label>
            @endforeach
        </div>
    </fieldset>

    <x-ui.button type="submit" tone="primary">{{ __('sales::route.assign') }}</x-ui.button>
</form>
