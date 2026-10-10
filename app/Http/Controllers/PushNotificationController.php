<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\PushConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushNotificationController extends Controller
{
    public function settings(Request $request)
    {
        $ready = app(PushConfiguration::class)->problems() === [];

        return response()->json([
            'deviceDisabled' => $request->cookie('truthguard_push_disabled') === '1',
            'enabled' => $ready,
            'configurationReady' => $ready,
            'firebase' => config('firebase.web'),
            'vapidKey' => config('firebase.vapid_key'),
            'preferences' => NotificationPreference::forUser($request->user()),
            'canTest' => true,
            'subscribed' => PushSubscription::where('user_id', $request->user()->id)->where('token_hash', $request->cookie('truthguard_push'))->exists(),
        ])->header('Cache-Control', 'no-store');
    }

    public function register(Request $request)
    {
        abort_unless(config('firebase.enabled'), 503, 'Push notifications are not configured.');
        $input = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9_:\-]+$/']]);
        $hash = hash('sha256', $input['token']);
        DB::transaction(function () use ($request, $input, $hash) {
            $subscription = PushSubscription::firstOrCreate(['token_hash' => $hash], [
                'user_id' => $request->user()->id,
                'token' => $input['token'],
                'device' => Str::limit($request->userAgent() ?? '', 255, ''),
            ]);
            abort_unless($subscription->user_id === $request->user()->id, 409, 'This browser subscription belongs to another account. Sign out there first.');
            $subscription->touch();
            $previous = $request->cookie('truthguard_push');
            if ($previous && $previous !== $hash) {
                PushSubscription::where('user_id', $request->user()->id)->where('token_hash', $previous)->delete();
            }
        });

        return response()->json(['ok' => true])->cookie('truthguard_push', $hash, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax')->withoutCookie('truthguard_push_disabled');
    }

    public function remove(Request $request)
    {
        $input = $request->validate(['token' => ['nullable', 'string', 'max:4096']]);
        $hash = isset($input['token']) ? hash('sha256', $input['token']) : $request->cookie('truthguard_push');
        if ($hash) {
            PushSubscription::where('user_id', $request->user()->id)->where('token_hash', $hash)->delete();
        }

        return response()->json(['ok' => true])->withoutCookie('truthguard_push')->cookie('truthguard_push_disabled', '1', 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public function preferences(Request $request)
    {
        $input = $request->validate(array_fill_keys(array_keys(NotificationPreference::DEFAULTS), ['sometimes', 'required', 'boolean']));
        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], $input);

        return response()->json(['preferences' => NotificationPreference::forUser($request->user())]);
    }

    public function test(Request $request, NotificationEventService $events)
    {
        $input = $request->validate(['delay_seconds' => ['sometimes', 'integer', 'in:0,15,30']]);
        abort_unless(config('firebase.enabled'), 503, 'Push notifications are not configured.');
        $subscription = PushSubscription::where('user_id', $request->user()->id)
            ->where('token_hash', $request->cookie('truthguard_push'))->first();
        $preferences = NotificationPreference::forUser($request->user());
        abort_unless($preferences['push_enabled'] && $preferences['system_notifications']
            && $subscription, 422, 'Enable push and system notifications on a device first.');
        $notification = $events->system($request->user(), 'test:'.Str::uuid(), 'This is your TruthGuard test notification.', '/notifications', $subscription->id, (int) ($input['delay_seconds'] ?? 0));

        return response()->json(['message' => 'Test queued for this device. Waiting for the worker...', 'statusUrl' => '/push/test/'.$notification->id], 202);
    }

    public function testStatus(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $subscription = PushSubscription::where('user_id', $request->user()->id)
            ->where('token_hash', $request->cookie('truthguard_push'))->first();
        $delivery = $subscription ? PushDelivery::where('push_subscription_id', $subscription->id)
            ->where('notification_id', $record->id)->first() : null;

        return response()->json([
            'status' => $subscription ? ($delivery?->status ?? 'queued') : 'unsubscribed',
            'message' => ! $subscription ? 'This device is no longer subscribed. Enable notifications again.' : match ($delivery?->status) {
                'sent' => 'Firebase accepted the test. This does not confirm display; check your device notifications.',
                'failed', 'retrying' => $delivery->last_error ?: 'Delivery failed. Check the queue worker.',
                default => 'Still queued. If this continues, check that the Dokploy worker is running.',
            },
        ])->header('Cache-Control', 'no-store');
    }

    // A background worker may outlive the login session. The encrypted HttpOnly
    // device cookie authorizes only this subscription, never an arbitrary user.
    public function delivery(Request $request, string $notification)
    {
        $hash = $request->cookie('truthguard_push');
        abort_unless($hash && config('firebase.enabled') && $request->cookie('truthguard_push_disabled') !== '1', 404);
        $subscription = PushSubscription::where('token_hash', $hash)->firstOrFail();
        abort_if($request->user() && $request->user()->id !== $subscription->user_id, 404);
        $user = User::findOrFail($subscription->user_id);
        $record = $user->notifications()->whereKey($notification)->whereNull('archived_at')->firstOrFail();
        $preferences = NotificationPreference::forUser($user);
        abort_unless($preferences['push_enabled'] && ($preferences[$record->data['preference'] ?? ''] ?? false), 404);

        return response()->json([
            'title' => $record->data['title'], 'body' => $record->data['message'],
            'url' => route('notifications.show', $record->id, absolute: false),
            'notification_id' => $record->id, 'subscription_id' => (string) $subscription->id,
            'timestamp' => $record->created_at->timestamp * 1000,
        ])->header('Cache-Control', 'private, no-store');
    }
}
