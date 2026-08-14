{{--
    যে কাগজগুলো এখনো বেরোয়নি।

    ── কেন এই পর্দাটা "ছাপাও" বোতাম দেয় না ─────────────────────────────
    সার্ভার নিজে কাগজ বের করতে পারে না — ছাপা হয় ব্রাউজারে, ব্যবহারকারীর
    প্রিন্টারে। বোতাম বসালে সেটা মিথ্যা বলত: চাপলে সারিটা "ছাপা হয়েছে"
    হয়ে যেত, আর কাগজ বেরোত না।

    তাই প্রতিটা সারি ছাপার পাতাটার দিকে একটা লিংক। কাগজ সত্যিই বেরোলে
    ওই পাতাটাই গোনাটা বাড়ায়, প্রথমবারের মতোই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.print_queue') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.print_queue')"
                          :subtitle="__('sales::message.print_queue_note')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    @if ($jobs->isEmpty())
        {{--
            খালি থাকাই স্বাভাবিক — এটা রোজকার কাজের পর্দা নয়, প্রিন্টার
            বিগড়ানোর দিনের। তাই বার্তাটা "কিছু নেই" নয়, "সব বেরিয়ে গেছে"।
        --}}
        <x-ui.empty-state icon="printer" :message="__('sales::message.print_queue_empty')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)">
            <table class="w-full text-sm">
                <thead class="bg-(--color-surface-app) text-start">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ __('core.table.document') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('sales::field.paper') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('sales::field.status') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('sales::field.print_failure') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                        <tr class="border-t border-(--color-border)">
                            <td class="px-3 py-2 font-medium">{{ $job->document_no ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $job->paper }}</td>
                            <td class="px-3 py-2">
                                <x-ui.badge :tone="$job->status === \App\Modules\Sales\Models\PrintJob::FAILED ? 'danger' : 'warn'">
                                    {{ __('sales::message.print_'.$job->status) }}
                                </x-ui.badge>
                            </td>

                            {{--
                                ব্যর্থতার কারণটা দেখানো হয়, লুকানো হয় না।
                                "কাগজ ফুরিয়েছে" আর "প্রিন্টার বন্ধ" দুইটা
                                আলাদা কাজ চায়, আর কর্মী সেটা জানলে দ্বিতীয়বার
                                একই ভুল করেন না।
                            --}}
                            <td class="px-3 py-2 text-(--color-ink-muted)">{{ $job->failure ?: '—' }}</td>

                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                @if ($job->printUrl() !== null)
                                    <a class="text-(--color-link) underline"
                                       href="{{ $job->printUrl() }}" target="_blank" rel="noopener">
                                        {{ __('sales::action.print_again') }}
                                    </a>
                                @endif

                                <form method="POST" class="ms-3 inline"
                                      action="{{ route('sales.print_queue.settle', ['job' => $job->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-(--color-ink-muted) underline">
                                        {{ __('sales::action.print_settled') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
