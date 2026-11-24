{{--
    ⭐ দেওয়ার অপেক্ষায় বকেয়া মজুরি — যেকোনো দিন, পরের মাসের রানের অপেক্ষা নয় (fe, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩; [[HeldWages]])।

    উপরে যাদের পথ এখন সই-পড়া (ব্যাংক বা MFS) — পথ ধরে আলাদা ফর্ম: টাকার খাত বেছে "দিন" — খসড়া পরিশোধ ভাউচার আর দেওয়ার কাগজ।
    নিচে যাঁদের হিসাব এখনো সইয়ের অপেক্ষায় (আইনের শেষ দিনসহ), আর আগের কাগজগুলো আবার নামানোর পথ।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::payroll.held_ready_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::payroll.held_ready_title')" :subtitle="__('hr::payroll.held_ready_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach (['bank', 'mfs'] as $channel)
        @php $lines = $ready->where('channel', $channel); @endphp
        <section data-held-ready="{{ $channel }}" data-boxed
                 class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            <h2 class="mb-2 font-semibold">{{ __('hr::kind.'.$channel) }}</h2>

            @if ($lines->isEmpty())
                <p class="text-(--color-ink-muted)">{{ __('hr::payroll.held_ready_none') }}</p>
            @else
                <form method="POST" action="{{ route('hr.payroll.held.pay', ['channel' => $channel]) }}">
                    @csrf
                    <table class="ui-lines w-full">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="text-start">{{ __('hr::dashboard.employee') }}</th>
                                <th class="text-start">{{ __('hr::payroll.held_run') }}</th>
                                <th class="text-start">{{ __('hr::field.payment_method') }}</th>
                                <th class="text-end">{{ __('hr::field.net') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $line)
                                <tr data-held-line="{{ $line['employee']->code }}">
                                    <td><input type="checkbox" name="payslips[]" value="{{ $line['payslip']->id }}" checked></td>
                                    <td>{{ $line['employee']->code }} · {{ $line['employee']->name() }}</td>
                                    <td>{{ $line['payslip']->run?->document_no }}</td>
                                    <td>{{ __('hr::kind.'.$line['channel']) }}</td>
                                    <td class="num text-end">{{ \App\Core\Support\Money::format($line['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @can('hr.payroll.manage')
                        <div class="mt-3 flex flex-wrap items-end gap-2">
                            <select name="account_id" required
                                    class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm">
                                @foreach ($accounts[$channel] as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name() }}</option>
                                @endforeach
                            </select>
                            <x-ui.button type="submit" tone="primary">{{ __('hr::payroll.held_pay_'.$channel) }}</x-ui.button>
                        </div>
                    @endcan
                </form>
            @endif
        </section>
    @endforeach

    {{--
        ⭐ ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায় (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬; [[HeldWages::awaitingBank()]])। ⓘ বাতিল রানের ব্যাংক ফাইলে
        যাওয়া বকেয়া — নিজে নিজে আবার দেওয়ার তালিকায় ফেরে না। ব্যাংকের বিবরণী মিলিয়ে "ব্যাংক এই লাইন দেয়নি" (কারণসহ), আর আরেকজনের
        দ্বিতীয় সই; ব্যাংক মিলকরণের পাতা থেকে এখানে আসার পথ আছে (#bank-awaiting)।
    --}}
    @if ($awaiting->isNotEmpty())
        <section id="bank-awaiting" data-bank-awaiting data-boxed
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) text-sm">
            <div class="border-b border-(--color-border) p-4">
                <h2 class="font-semibold">{{ __('hr::payroll.bank_awaiting') }}</h2>
                <p class="mt-0.5 max-w-(--spacing-prose-max) text-(--color-ink-muted)">{{ __('hr::payroll.bank_awaiting_note') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            <th scope="col" class="text-start">{{ __('hr::dashboard.employee') }}</th>
                            <th scope="col" class="text-start">{{ __('hr::payroll.held_run') }}</th>
                            <th scope="col" class="text-start">{{ __('hr::payroll.bank_awaiting_carrier') }}</th>
                            <th scope="col" class="num">{{ __('hr::field.net') }}</th>
                            <th scope="col" class="text-start">{{ __('hr::payroll.bank_awaiting_state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($awaiting as $line)
                            <tr data-bank-awaiting-line="{{ $line['employee']?->code }}">
                                <td>{{ $line['employee']?->code }} · {{ $line['employee']?->name() }}</td>
                                <td>{{ $line['held_run']?->document_no }}</td>
                                <td>{{ $line['carrier']?->document_no }}</td>
                                <td class="num">{{ \App\Core\Support\Money::format($line['amount']) }}</td>
                                <td>
                                    @if ($line['asked'] !== null)
                                        {{-- ⓘ চাওয়া হয়েছে — দ্বিতীয় সইয়ের অপেক্ষায়; বোতাম কেবল যিনি সই দিতে পারেন তাঁর (ইনবক্সের একই প্রশ্ন) --}}
                                        <span data-bank-unpaid-waiting class="text-(--color-badge-warning-ink)">{{ __('hr::payroll.bank_unpaid_waiting') }}</span>
                                        @if (app(\App\Modules\Hr\Services\HeldWages::class)->canSignUnpaid($line['asked'], auth()->user()))
                                            <form method="POST" action="{{ route('hr.payroll.held.bank_unpaid_sign', $line['asked']->id) }}" class="mt-1">
                                                @csrf
                                                <x-ui.button type="submit" tone="primary">{{ __('hr::payroll.bank_unpaid_sign') }}</x-ui.button>
                                            </form>
                                        @endif
                                    @else
                                        <span data-bank-awaiting-state>{{ __('hr::payroll.bank_awaiting') }}</span>
                                        @can('hr.payroll.manage')
                                            <form method="POST" action="{{ route('hr.payroll.held.bank_unpaid', $line['payment']->id) }}"
                                                  class="mt-1 flex flex-wrap items-center gap-1">
                                                @csrf
                                                <input type="text" name="reason" required maxlength="500" placeholder="{{ __('hr::field.reason') }}"
                                                       aria-label="{{ __('hr::field.reason') }}"
                                                       class="w-40 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1 text-sm">
                                                <x-ui.button type="submit">{{ __('hr::payroll.bank_unpaid_action') }}</x-ui.button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($papers->isNotEmpty())
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            <h2 class="mb-2 font-semibold">{{ __('hr::payroll.held_papers') }}</h2>
            <ul class="list-inside list-disc">
                @foreach ($papers as $paper)
                    <li>
                        {{ $paper->voucher?->document_no }} · {{ __('hr::kind.'.$paper->channel) }}
                        @can('hr.identity.view')
                            · <a class="underline" href="{{ route('hr.payroll.held.paper', $paper->voucher_id) }}">{{ __('hr::payroll.held_paper_again') }}</a>
                        @endcan
                    </li>
                @endforeach
            </ul>
            <x-ui.pager :rows="$papers" />
            <x-ui.list-totals :rows="$papers" />
        </section>
    @endif
</x-layouts.app>
