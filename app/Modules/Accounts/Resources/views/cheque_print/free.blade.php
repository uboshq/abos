{{--
    ⭐ শুধু চেক ছাপা — হিসাব ছাড়া (মালিক, ১০ নভেম্বর ২০২৬: "companyr kono hisab nai … jast print hobe"; নমুনার পাতা ৬)।
    কোনো ভাউচার নয়, খাতায় কিছু নয় — কেবল ছাপা চেকের খাতায় "হিসাব ছাড়া" চিহ্নে সারি ([[ChequePrinter::free()]])।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::cheque_print.free_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::cheque_print.free_title')" />
    </x-slot:header>

    <p data-free-warning class="mb-4 flex flex-wrap items-center gap-2 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
        <span class="rounded-full border border-current px-2 text-xs font-bold">{{ __('accounts::cheque_print.free_badge') }}</span>
        {{ __('accounts::cheque_print.free_warning') }}
    </p>

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:col-span-2">
            @if ($books->isEmpty())
                <p role="alert" data-cheque-no-book class="text-sm">
                    {{ __('accounts::cheque_print.no_book') }}
                    @can('accounts.cheque.layout')
                        <a class="font-semibold underline" href="{{ route('accounts.cheque.print.setup') }}">{{ __('accounts::cheque_print.menu_setup') }}</a>
                    @endcan
                </p>
            @else
                <form method="POST" action="{{ route('accounts.cheque.print.free.store') }}" target="_blank" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.book') }}</span>
                        <select name="book" class="{{ $field }}">
                            @foreach ($books as $book)
                                <option value="{{ $book->id }}">{{ $book->label }} — {{ __('accounts::cheque_print.book_left', ['first' => $book->number($book->first_no), 'last' => $book->number($book->last_no), 'left' => $book->leavesLeft()]) }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-(--color-ink-muted)">{{ __('accounts::cheque_print.book_hint_free') }}</span>
                    </label>
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.cheque_no') }}</span>
                        <span data-cheque-next class="num flex h-(--spacing-field) items-center font-semibold">{{ $books->first()->number($books->first()->next_no) }}</span>
                    </div>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.cheque_date') }}</span>
                        <x-ui.date name="cheque_date" id="cheque_date" :value="old('cheque_date', now()->toDateString())" dense />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.amount') }}</span>
                        <input type="text" inputmode="decimal" name="amount" required value="{{ old('amount') }}" class="{{ $field }} num text-right font-bold">
                    </label>
                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.payee') }}</span>
                        <input type="text" name="payee" required maxlength="160" value="{{ old('payee') }}" class="{{ $field }}">
                    </label>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="hidden" name="crossed" value="0">
                        <input type="checkbox" name="crossed" value="1" @checked(old('crossed', true))>
                        {{ __('accounts::cheque_print.crossed') }}
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.purpose') }}</span>
                        <input type="text" name="purpose" maxlength="200" value="{{ old('purpose') }}" class="{{ $field }}">
                    </label>
                    <p class="text-sm text-(--color-ink-muted) sm:col-span-2">{{ __('accounts::cheque_print.first_time_tip') }}</p>
                    <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                        <button type="submit" name="test" value="1"
                                class="h-(--spacing-command) rounded-(--radius-field) border border-(--color-brand-500) px-4 text-sm font-semibold text-(--color-brand-600)">{{ __('accounts::cheque_print.test') }}</button>
                        <button type="submit" data-cheque-print-button
                                class="h-(--spacing-command) rounded-(--radius-field) bg-(--color-brand-600) px-4 text-sm font-bold text-(--color-brand-ink)">{{ __('accounts::cheque_print.print') }}</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="flex flex-col gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-base font-bold">{{ __('accounts::cheque_print.log_title') }}</h2>
            @forelse ($recent as $p)
                <div class="flex justify-between gap-2 border-t border-(--color-border) pt-2 text-sm">
                    <span class="flex min-w-0 flex-col">
                        <span class="num font-semibold">{{ $p->book?->number($p->cheque_no) }}</span>
                        <span class="text-(--color-ink-muted)">{{ $p->printer?->name }} · {{ $p->created_at?->format('d/m H:i') }}</span>
                    </span>
                    <span class="num whitespace-nowrap font-bold">৳{{ \App\Core\Support\Money::format($p->amount) }}</span>
                </div>
            @empty
                <p class="text-sm text-(--color-ink-muted)">{{ __('accounts::cheque_print.log_none') }}</p>
            @endforelse
        </section>
    </div>
</x-layouts.app>
