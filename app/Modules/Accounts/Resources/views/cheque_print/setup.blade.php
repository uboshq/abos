{{--
    ⭐ চেক বই ও মাপ — মালিকের নমুনার পাতা ৪ (১০ নভেম্বর ২০২৬)। প্রতিটা ব্যাংকের পাতার মাপ mm-এ (ঘর ধরে বাঁ, ওপর, চওড়া, অক্ষর),
    পুরো ছাপা সরানোর ডান-নিচ; আর চেক বই — কোম্পানির (ব্যাংক হিসাবের) বা হিসাব ছাড়া (নিজের)। পরীক্ষার ছাপ ছাপার পাতা থেকে।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2';
    $small = 'h-(--spacing-field) w-full min-w-0 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 num text-right';
    $names = ['date' => 'field_date', 'payee' => 'field_payee', 'words' => 'field_words', 'figures' => 'field_figures', 'crossing' => 'field_crossing'];
    $keys = ['x' => 'x', 'y' => 'y', 'w' => 'w', 'size' => 'size', 'box' => 'box', 'angle' => 'angle', 'line' => 'line'];
    $forms = $layouts->map(fn ($l) => ['layout' => $l, 'fields' => collect(array_keys($names))->mapWithKeys(fn ($n) => [$n => $l->field($n)])->all()])
        ->push(['layout' => null, 'fields' => $standard['fields']]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::cheque_print.setup_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::cheque_print.setup_title')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col gap-4">
        @foreach ($forms as $form)
            @php($l = $form['layout'])
            <form method="POST" action="{{ route('accounts.cheque.print.setup.layout.store') }}" data-cheque-layout="{{ $l?->id ?? 'new' }}"
                  class="flex flex-col gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf
                <h2 class="text-base font-bold">{{ $l?->name ?? __('accounts::cheque_print.new_layout') }}</h2>
                @if ($l)<input type="hidden" name="id" value="{{ $l->id }}">@endif
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.layout_name') }}</span>
                        <input type="text" name="name" required maxlength="120" value="{{ $l?->name }}" class="{{ $field }}">
                    </label>
                    @foreach (['width_mm' => 'width', 'height_mm' => 'height', 'offset_x' => 'offset_x', 'offset_y' => 'offset_y'] as $col => $label)
                        <label class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{ __('accounts::cheque_print.'.$label) }}</span>
                            <input type="text" inputmode="decimal" name="{{ $col }}" required value="{{ $l ? $l->{$col} : ($standard[$col] ?? '0') }}" class="{{ $small }}">
                        </label>
                    @endforeach
                </div>
                <div class="overflow-x-auto">
                    <div class="grid min-w-[50rem] grid-cols-8 gap-2 text-sm">
                        <span class="font-semibold"></span>
                        @foreach ($keys as $key)
                            <span class="text-xs font-semibold text-(--color-ink-muted)">{{ __('accounts::cheque_print.'.$key) }}</span>
                        @endforeach
                        @foreach ($names as $name => $label)
                            <span class="flex items-center font-semibold">{{ __('accounts::cheque_print.'.$label) }}</span>
                            @foreach ($keys as $key)
                                @if (array_key_exists($key, $standard['fields'][$name]))
                                    <input type="text" inputmode="decimal" name="fields[{{ $name }}][{{ $key }}]" required
                                           aria-label="{{ __('accounts::cheque_print.'.$label) }} — {{ __('accounts::cheque_print.'.$key) }}"
                                           value="{{ $form['fields'][$name][$key] ?? $standard['fields'][$name][$key] }}" class="{{ $small }}">
                                @else
                                    <span></span>
                                @endif
                            @endforeach
                        @endforeach
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="h-(--spacing-command) rounded-(--radius-field) bg-(--color-brand-600) px-4 text-sm font-bold text-(--color-brand-ink)">{{ __('accounts::cheque_print.save') }}</button>
                </div>
            </form>
        @endforeach

        <section class="flex flex-col gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-base font-bold">{{ __('accounts::cheque_print.book') }}</h2>
            @foreach ($books->push(null) as $book)
                <form method="POST" action="{{ route('accounts.cheque.print.setup.book.store') }}" data-cheque-book="{{ $book?->id ?? 'new' }}"
                      class="grid gap-2 border-t border-(--color-border) pt-3 sm:grid-cols-4 lg:grid-cols-8">
                    @csrf
                    @if ($book)<input type="hidden" name="id" value="{{ $book->id }}">@endif
                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium">{{ $book ? __('accounts::cheque_print.book_label') : __('accounts::cheque_print.new_book') }}</span>
                        <input type="text" name="label" required maxlength="160" value="{{ $book?->label }}" class="{{ $field }}">
                    </label>
                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium">{{ __('accounts::cheque_print.bank_account') }}</span>
                        <select name="bank_account_id" class="{{ $field }}">
                            <option value="">—</option>
                            @foreach ($banks as $bank)
                                <option value="{{ $bank->id }}" @selected($book?->bank_account_id === $bank->id)>{{ $bank->code }} · {{ $bank->name() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">{{ __('accounts::cheque_print.first_no') }}</span>
                        <input type="text" inputmode="numeric" name="first_no" required value="{{ $book?->first_no }}" class="{{ $small }}">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">{{ __('accounts::cheque_print.last_no') }}</span>
                        <input type="text" inputmode="numeric" name="last_no" required value="{{ $book?->last_no }}" class="{{ $small }}">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">{{ __('accounts::cheque_print.digits') }}</span>
                        <input type="text" inputmode="numeric" name="digits" required value="{{ $book?->digits ?? 7 }}" class="{{ $small }}">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">{{ __('accounts::cheque_print.layout') }}</span>
                        <select name="layout_id" required class="{{ $field }}">
                            @foreach ($layouts as $l)
                                <option value="{{ $l->id }}" @selected($book?->layout_id === $l->id)>{{ $l->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm sm:col-span-3">
                        <input type="hidden" name="off_books" value="0">
                        <input type="checkbox" name="off_books" value="1" @checked($book?->off_books)>
                        {{ __('accounts::cheque_print.off_books') }}
                    </label>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($book?->is_active ?? true)>
                        {{ __('accounts::cheque_print.active') }}
                    </label>
                    <div class="flex items-center justify-end gap-3 sm:col-span-4">
                        @if ($book)
                            <span class="num text-xs text-(--color-ink-muted)">{{ __('accounts::cheque_print.book_left', ['first' => $book->number($book->first_no), 'last' => $book->number($book->last_no), 'left' => $book->leavesLeft()]) }}</span>
                        @endif
                        <button type="submit" @disabled($layouts->isEmpty()) class="h-(--spacing-command) rounded-(--radius-field) bg-(--color-brand-600) px-4 text-sm font-bold text-(--color-brand-ink)">{{ __('accounts::cheque_print.save') }}</button>
                    </div>
                </form>
            @endforeach
        </section>
    </div>
</x-layouts.app>
