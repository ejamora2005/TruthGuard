<section id="notification-settings" data-push-settings
    class="truthguard-push-compact {{ ($embedded ?? false) ? 'truthguard-push-embedded' : 'truthguard-push-standalone' }}"
    aria-label="Notification preferences">
    @unless ($embedded ?? false)
        <h2 class="truthguard-push-heading">Notifications</h2>
    @endunless

    <div class="truthguard-push-device">
        <div>
            <h3>This device <span data-push-device-state>Checking…</span></h3>
            <p>Receive alerts outside the app.</p>
        </div>
        <div class="truthguard-push-device-actions">
            <button type="button" data-push-enable disabled class="truthguard-push-button truthguard-push-button-primary" aria-label="Enable notifications on this device">Enable</button>
            <button type="button" data-push-disable hidden class="truthguard-push-button truthguard-push-button-secondary" aria-label="Disable notifications on this device">Disable</button>
        </div>
    </div>
    <p data-push-status role="status" aria-live="polite" class="truthguard-push-status" hidden></p>

    <div class="truthguard-push-preference-grid" role="group" aria-label="Alert preferences">
        @foreach (['push_enabled' => 'Push to all devices', 'analysis_results' => 'Analysis results', 'fact_check_updates' => 'Fact-check updates', 'new_fact_checks' => 'New fact-checks', 'system_notifications' => 'System alerts'] as $key => $label)
            <label class="truthguard-push-preference-row">
                <span class="truthguard-push-preference-label">{{ $label }}</span>
                <span class="truthguard-push-toggle">
                    <input type="checkbox" role="switch" data-preference="{{ $key }}" class="truthguard-push-preference-checkbox">
                    <span class="truthguard-push-toggle-track" aria-hidden="true"></span>
                </span>
            </label>
        @endforeach
    </div>
    <p class="truthguard-push-description">Categories also apply to in-app alerts.</p>
    <div class="truthguard-push-actions">
        <button type="button" data-push-save class="truthguard-push-button truthguard-push-button-primary">Save changes</button>
    </div>
</section>
