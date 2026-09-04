<div class="col-span-12 xl:col-span-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Newest Users</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">Recently created user accounts and plan assignments.</p>
            </div>
        </div>

        @if ($latestUsers->isEmpty())
            <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500">
                No users found yet.
            </div>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($latestUsers as $user)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-500">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </span>
                            <div>
                                <p class="font-semibold text-gray-800 text-theme-sm">{{ $user->name }}</p>
                                <span class="block text-gray-500 text-theme-xs">{{ $user->email }}</span>
                            </div>
                        </div>

                        <div class="text-right">
                            <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-theme-xs font-medium text-gray-700">
                                {{ ucfirst($user->subscription_tier) }}
                            </span>
                            <p class="mt-1 text-gray-500 text-theme-xs">{{ $user->created_at?->format('M d, Y') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
