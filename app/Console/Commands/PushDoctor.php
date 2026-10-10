<?php

namespace App\Console\Commands;

use App\Services\Notifications\PushConfiguration;
use App\Services\Notifications\TruthGuardPushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PushDoctor extends Command
{
    protected $signature = 'truthguard:push-doctor {--check-provider : Exchange credentials with Google without sending a notification}';

    protected $description = 'Check push configuration safely without displaying secrets';

    public function handle(PushConfiguration $configuration, TruthGuardPushNotificationService $push): int
    {
        $problems = $configuration->problems();
        foreach ($problems as $problem) {
            $this->error('Check '.$problem);
        }
        if (! $problems) {
            $this->info('Firebase web, VAPID, and service-account configuration is structurally valid.');
        }
        $this->line('Queue: '.config('queue.default').'; cache: '.config('cache.default'));
        foreach (['push_subscriptions', 'notification_preferences', 'push_deliveries', 'notifications'] as $table) {
            try {
                $exists = Schema::hasTable($table);
            } catch (Throwable) {
                $exists = false;
            }
            if (! $exists) {
                $problems[] = $table;
                $this->error('Missing or inaccessible table: '.$table);
            }
        }
        if (config('queue.default') === 'sync') {
            $this->warn('Use QUEUE_CONNECTION=database for Dokploy background workers.');
        }
        if (! in_array(config('cache.default'), ['database', 'redis'], true)) {
            $this->warn('Use a shared database/Redis cache for replica delivery locks.');
        }
        if ($this->option('check-provider') && $configuration->problems() === []) {
            try {
                $push->checkAuthentication();
                $this->info('Google OAuth accepted the service-account credentials. No notification was sent.');
            } catch (Throwable) {
                $problems[] = 'provider';
                $this->error('Google OAuth failed. Check the credential key, outbound HTTPS, server time, and TLS trust.');
            }
        }
        $this->comment('Configuration checks do not confirm device delivery. Use Send Test Notification on the actual device.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
