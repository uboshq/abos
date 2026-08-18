{{--
    মাস বন্ধ করা ও খোলা।

    পাতাটা মাসের তালিকা দেখায়, নতুন আগে — কারণ যে মাসটা এইমাত্র শেষ
    হয়েছে, সেটাই বন্ধ করার কথা। পুরনোগুলো নিচে, আর সেগুলো সাধারণত
    আগেই বন্ধ।

    বন্ধ করা এক ক্লিক, খোলা নয়: খুলতে কারণ লিখতে হয়, আর সেটাই ছয় মাস
    পরে নিরীক্ষকের প্রশ্নের একমাত্র উত্তর।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.periods') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.periods')"
                          :subtitle="__('accounts::message.period_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($months === [])
        <x-ui.empty-state :message="__('accounts::message.no_current_year')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.month') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.state') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                            {{ __('accounts::field.reason') }}
                        </th>
                        <th scope="col" class="px-3 py-2 text-end font-medium text-(--color-ink-muted)">
                            {{ __('core.table.action') }}
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($months as $month)
                        <tr class="border-b border-(--color-border)">
                            <td class="px-3 py-2 font-medium">{{ $month['label'] }}</td>

                            <td class="px-3 py-2">
                                @if ($month['lock'])
                                    <span class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                                                 px-2 py-0.5 text-2xs text-(--color-badge-danger-ink)">
                                        {{ __('accounts::field.closed') }}
                                    </span>
                                @else
                                    <span class="rounded-(--radius-field) bg-(--color-badge-success-bg)
                                                 px-2 py-0.5 text-2xs text-(--color-badge-success-ink)">
                                        {{ __('accounts::field.open') }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-(--color-ink-muted)">
                                {{ $month['lock']?->reason ?: '—' }}
                            </td>

                            <td class="px-3 py-2 text-end">
                                @if ($month['lock'])
                                    @can('accounts.period.reopen')
                                        {{--
                                            খোলার কারণটা এখানেই চাওয়া হয়, আলাদা
                                            পাতায় নয় — এক ক্লিকে খোলা যাওয়াটাই
                                            আসল বিপদ, আর একটা ঘর ভরা সেটার
                                            সবচেয়ে সস্তা প্রতিরোধ।
                                        --}}
                                        <form method="POST"
                                              action="{{ route('accounts.period.reopen', $month['lock']) }}"
                                              class="flex flex-wrap items-center justify-end gap-2">
                                            @csrf
                                            <input type="text" name="reason" required minlength="3"
                                                   placeholder="{{ __('accounts::field.reopen_reason') }}"
                                                   class="h-(--spacing-field) w-56 rounded-(--radius-field)
                                                          border border-(--color-border)
                                                          bg-(--color-surface-app) px-2">
                                            <x-ui.button type="submit" tone="secondary">
                                                {{ __('accounts::action.reopen') }}
                                            </x-ui.button>
                                        </form>
                                    @endcan
                                @else
                                    <form method="POST" action="{{ route('accounts.period.close') }}"
                                          class="flex flex-wrap items-center justify-end gap-2">
                                        @csrf
                                        <input type="hidden" name="year" value="{{ $month['year'] }}">
                                        <input type="hidden" name="month" value="{{ $month['month'] }}">
                                        <input type="text" name="reason"
                                               placeholder="{{ __('accounts::field.reason') }}"
                                               class="h-(--spacing-field) w-56 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-app) px-2">
                                        <x-ui.button type="submit" tone="primary">
                                            {{ __('accounts::action.close_month') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
