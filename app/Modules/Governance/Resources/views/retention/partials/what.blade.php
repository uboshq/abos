                            @if ($row['route'])
                                <a href="{{ \App\Modules\Finance\Support\FinancePlan::urlFor($row['route']) }}"
                                   class="text-(--color-link) hover:underline">{{ __('governance::retention.kind.'.$row['key']) }}</a>
                            @else
                                {{ __('governance::retention.kind.'.$row['key']) }}
                            @endif
