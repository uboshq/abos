                            @if ($row['kept'] === \App\Modules\Governance\Services\WhatIsKeptHowLong::FOREVER)
                                <span class="rounded-(--radius-pill) bg-(--color-badge-success-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-success-ink)">
                                    {{ __('governance::retention.forever') }}
                                </span>
                            @else
                                <span class="rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-pending-ink)">
                                    {{ trans_choice('governance::retention.days', $row['days'], ['count' => $row['days']]) }}
                                </span>
                            @endif
