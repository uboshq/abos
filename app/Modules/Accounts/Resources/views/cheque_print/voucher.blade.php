{{--
    ⭐ একটা কাগজের চেক ছাপা — মালিকের অনুমোদিত নমুনা (W63BXTDuqM65tDaKRDnvwR, ১০ নভেম্বর ২০২৬; পাতা ২–৩)।
    কাগজ দুই রকম, পাতা একটাই ([[ChequePrintController::paperView()]]): পরিশোধ ভাউচার, বা চেকের খাতার দেওয়া চেক (সরবরাহকারীর পরিশোধ)।
    টাকা কাগজের — হাতে বদলায় না; নাম বদলানো যায়। ছাপা আর পরীক্ষার ছাপ নতুন ট্যাবে PDF।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::cheque_print.print') }} · {{ $paper['no'] }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::cheque_print.print').' · '.$paper['no']" />
    </x-slot:header>

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
        <section data-cheque-print-form class="flex flex-col gap-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:col-span-2">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-(--color-ink-muted)">{{ __('accounts::cheque_print.cheque_date') }}</dt>
                    <dd class="font-semibold">{{ $paper['date']?->format('d/m/Y') }}</dd></div>
                <div><dt class="text-(--color-ink-muted)">{{ __('accounts::cheque_print.amount') }}</dt>
                    <dd data-cheque-amount class="num text-lg font-bold">{{ $paper['amount'] === null ? '—' : '৳'.\App\Core\Support\Money::format($paper['amount']) }}</dd></div>
                <div><dt class="text-(--color-ink-muted)">{{ __('accounts::cheque_print.words') }}</dt>
                    <dd class="font-semibold">{{ $paper['amount'] === null ? '—' : \App\Core\Support\AmountInWords::cheque($paper['amount']) }}</dd></div>
            </dl>

            @if ($paper['not_ready'] !== null)
                <p role="alert" data-cheque-not-ready class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">{{ $paper['not_ready'] }}</p>
            @elseif ($books->isEmpty())
                <p role="alert" data-cheque-no-book class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
                    {{ __('accounts::cheque_print.no_book') }}
                    @can('accounts.cheque.layout')
                        <a class="font-semibold underline" href="{{ route('accounts.cheque.print.setup') }}">{{ __('accounts::cheque_print.menu_setup') }}</a>
                    @endcan
                </p>
            @else
                <form method="POST" action="{{ $paper['store'] }}" target="_blank" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.book') }}</span>
                        <select name="book" class="{{ $field }}">
                            @foreach ($books as $book)
                                <option value="{{ $book->id }}" @selected($printed?->cheque_book_id === $book->id)>{{ $book->label }} — {{ __('accounts::cheque_print.book_left', ['first' => $book->number($book->first_no), 'last' => $book->number($book->last_no), 'left' => $book->leavesLeft()]) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.cheque_no') }}</span>
                        <span data-cheque-next class="num flex h-(--spacing-field) items-center font-semibold">{{ $paper['number'] }}</span>
                    </div>
                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-sm font-medium">{{ __('accounts::cheque_print.payee') }}</span>
                        <input type="text" name="payee" required maxlength="160" value="{{ old('payee', $printed?->payee ?? $paper['payee']) }}" class="{{ $field }}">
                        <span class="text-xs text-(--color-ink-muted)">{{ __('accounts::cheque_print.payee_hint') }}</span>
                    </label>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="hidden" name="crossed" value="0">
                        <input type="checkbox" name="crossed" value="1" @checked(old('crossed', $printed?->crossed ?? true))>
                        {{ __('accounts::cheque_print.crossed') }}
                    </label>
                    @if ($printed)
                        <label class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{ __('accounts::cheque_print.reason') }}</span>
                            <input type="text" name="reason" required maxlength="200" value="{{ old('reason') }}" class="{{ $field }}">
                        </label>
                    @endif
                    <p class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink) sm:col-span-2">{{ __('accounts::cheque_print.first_time_tip') }}</p>
                    <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                        <button type="submit" formaction="{{ $paper['test'] }}" formnovalidate
                                class="h-(--spacing-command) rounded-(--radius-field) border border-(--color-brand-500) px-4 text-sm font-semibold text-(--color-brand-600)">{{ __('accounts::cheque_print.test') }}</button>
                        <button type="submit" data-cheque-print-button
                                class="h-(--spacing-command) rounded-(--radius-field) bg-(--color-brand-600) px-4 text-sm font-bold text-(--color-brand-ink)">{{ $printed ? __('accounts::cheque_print.again') : __('accounts::cheque_print.print') }}</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="flex flex-col gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-base font-bold">{{ __('accounts::cheque_print.log_title') }}</h2>
            @forelse ($earlier as $p)
                <div class="flex justify-between gap-2 border-t border-(--color-border) pt-2 text-sm">
                    <span class="num font-semibold">{{ $p->book?->number($p->cheque_no) }}</span>
                    <span class="text-(--color-ink-muted)">{{ $p->status === \App\Modules\Accounts\Models\ChequePrint::SPOILED ? __('accounts::cheque_print.state_spoiled') : __('accounts::cheque_print.printed_times', ['times' => $p->times]) }}</span>
                </div>
            @empty
                <p class="text-sm text-(--color-ink-muted)">{{ __('accounts::cheque_print.not_printed') }}</p>
            @endforelse
        </section>
    </div>
</x-layouts.app>
