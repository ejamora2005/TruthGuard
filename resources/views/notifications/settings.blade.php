<section id="notification-settings" data-push-settings class="mx-auto my-5 w-full max-w-[1380px] rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-labelledby="notification-settings-title">
    <h2 id="notification-settings-title" class="text-lg font-semibold text-gray-900 dark:text-white">Notification Settings</h2>
    <h3 class="mt-3 font-medium text-gray-900 dark:text-white">Enable TruthGuard Notifications</h3>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Receive alerts when your analysis is complete or when important fact-check updates are available.</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <button type="button" data-push-enable disabled class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Enable Notifications</button>
        <button type="button" data-push-later class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:text-white">Not Now</button>
        <button type="button" data-push-disable class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:text-white">Disable on this device</button>
    </div>
    <p data-push-status role="status" aria-live="polite" class="mt-3 text-sm text-gray-600 dark:text-gray-300">Loading notification settings...</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach (['push_enabled' => 'Push Notifications (all devices)', 'analysis_results' => 'Analysis Results', 'fact_check_updates' => 'Fact-Check Updates', 'new_fact_checks' => 'New Fact-Checks', 'system_notifications' => 'System Notifications'] as $key => $label)
            <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700 dark:text-white">
                <input type="checkbox" data-preference="{{ $key }}" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                {{ $label }}
            </label>
        @endforeach
    </div>
    <p class="mt-3 text-sm text-gray-500">Category settings apply to in-app and push alerts. The push switch controls delivery to all your devices. Use Enable Notifications to subscribe this device.</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <button type="button" data-push-save class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white">Save notification preferences</button>
        <button type="button" data-push-test hidden class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:text-white">Send Test Notification</button>
    </div>
</section>
