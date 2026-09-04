<div class="col-span-12 grid grid-cols-12 gap-4 md:gap-6">
    @foreach ($metricCards as $card)
        <div class="col-span-12 sm:col-span-6 xl:col-span-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-theme-sm md:p-6">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100">
                    @if ($card['icon'] === 'users')
                        <svg class="fill-gray-800" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M8.80443 5.60156C7.59109 5.60156 6.60749 6.58517 6.60749 7.79851C6.60749 9.01185 7.59109 9.99545 8.80443 9.99545C10.0178 9.99545 11.0014 9.01185 11.0014 7.79851C11.0014 6.58517 10.0178 5.60156 8.80443 5.60156ZM5.10749 7.79851C5.10749 5.75674 6.76267 4.10156 8.80443 4.10156C10.8462 4.10156 12.5014 5.75674 12.5014 7.79851C12.5014 9.84027 10.8462 11.4955 8.80443 11.4955C6.76267 11.4955 5.10749 9.84027 5.10749 7.79851ZM15.3042 11.4955C14.4702 11.4955 13.7006 11.2193 13.0821 10.7533C13.3742 10.3314 13.6054 9.86419 13.7632 9.36432C14.1597 9.75463 14.7039 9.99545 15.3042 9.99545C16.5176 9.99545 17.5012 9.01185 17.5012 7.79851C17.5012 6.58517 16.5176 5.60156 15.3042 5.60156C14.7039 5.60156 14.1597 5.84239 13.7632 6.23271C13.6054 5.73284 13.3741 5.26561 13.082 4.84371C13.7006 4.37777 14.4702 4.10156 15.3042 4.10156C17.346 4.10156 19.0012 5.75674 19.0012 7.79851C19.0012 9.84027 17.346 11.4955 15.3042 11.4955Z" fill="" />
                        </svg>
                    @elseif ($card['icon'] === 'subscription')
                        <svg class="fill-gray-800" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M3.75 6.5A2.75 2.75 0 0 1 6.5 3.75h11A2.75 2.75 0 0 1 20.25 6.5v11a2.75 2.75 0 0 1-2.75 2.75h-11A2.75 2.75 0 0 1 3.75 17.5v-11ZM6.5 5.25c-.69 0-1.25.56-1.25 1.25v1h13.5v-1c0-.69-.56-1.25-1.25-1.25h-11Zm12.25 3.75H5.25v8.5c0 .69.56 1.25 1.25 1.25h11c.69 0 1.25-.56 1.25-1.25V9Zm-10 3.25a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75Z" fill="" />
                        </svg>
                    @elseif ($card['icon'] === 'activity')
                        <svg class="fill-gray-800" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M3.75 12A.75.75 0 0 1 4.5 11.25h2.24l1.95-4.56a.75.75 0 0 1 1.38.05l2.72 7.25 1.69-3.39a.75.75 0 0 1 .67-.41h3.35a.75.75 0 0 1 0 1.5h-2.88l-2.22 4.44a.75.75 0 0 1-1.4-.08l-2.68-7.15-1.36 3.18a.75.75 0 0 1-.69.46H4.5A.75.75 0 0 1 3.75 12Z" fill="" />
                        </svg>
                    @else
                        <svg class="fill-gray-800" width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 3.75c-4.556 0-8.25 3.694-8.25 8.25 0 4.557 3.694 8.25 8.25 8.25s8.25-3.693 8.25-8.25c0-4.556-3.694-8.25-8.25-8.25Zm0 1.5A6.75 6.75 0 0 1 18.75 12 6.75 6.75 0 0 1 12 18.75 6.75 6.75 0 0 1 5.25 12 6.75 6.75 0 0 1 12 5.25Zm0 2.25a.75.75 0 0 1 .75.75v3.19l1.78 1.07a.75.75 0 0 1-.78 1.28l-2.14-1.29a.75.75 0 0 1-.36-.64V8.25A.75.75 0 0 1 12 7.5Z" fill="" />
                        </svg>
                    @endif
                </div>

                <div class="mt-5 flex items-end justify-between gap-3">
                    <div>
                        <span class="text-sm text-gray-500">{{ $card['label'] }}</span>
                        <h4 class="mt-2 font-bold text-gray-800 text-title-sm">{{ $card['value'] }}</h4>
                    </div>

                    <span @class([
                        'rounded-full py-0.5 pl-2 pr-2.5 text-sm font-medium',
                        'bg-success-50 text-success-600' => $card['change_tone'] === 'success',
                        'bg-brand-50 text-brand-500' => $card['change_tone'] === 'brand',
                        'bg-warning-50 text-warning-700' => $card['change_tone'] === 'warning',
                        'bg-error-50 text-error-600' => $card['change_tone'] === 'error',
                    ])>
                        {{ $card['change'] }}
                    </span>
                </div>
            </div>
        </div>
    @endforeach
</div>
