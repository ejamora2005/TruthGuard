<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushNotificationController extends Controller
{
    public function settings(Request $request)
    {
        return response()->json([
            'deviceDisabled' => $request->cookie('truthguard_push_disabled') === '1',
            'enabled' => (bool) config('firebase.enabled'),
            'firebase' => config('firebase.web'),
            'vapidKey' => config('firebase.vapid_key'),
            'preferences' => NotificationPreference::forUser($request->user()),
            'canTest' => app()->environment(['local', 'testing']) || $request->user()->isAdmin(),
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
        abort_unless(app()->environment(['local', 'testing']) || $request->user()->isAdmin(), 403);
        abort_unless(config('firebase.enabled'), 503, 'Push notifications are not configured.');
        $preferences = NotificationPreference::forUser($request->user());
        abort_unless($preferences['push_enabled'] && $preferences['system_notifications']
            && PushSubscription::where('user_id', $request->user()->id)->exists(), 422, 'Enable push and system notifications on a device first.');
        $events->system($request->user(), 'test:'.Str::uuid(), 'This is your TruthGuard test notification.');

        return response()->json(['message' => 'Test queued. Delivery still depends on Firebase and device settings.'], 202);
    }
}
