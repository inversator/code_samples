<?php

namespace App\Console;

use App\Jobs\Inbox\PublishingMessages;
use App\Jobs\Post\PublishingSchedulePosts;
use App\Jobs\Video\PublishingScheduleVideos;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Mailing about unread chat messages
        if (config('app.env') == 'production') {

            $schedule->command('notification:dashboard no-uploaded-videos')
                ->pingOnSuccess(config('envoyer.heartbeats.notification_dashboard_no_uploaded_videos'))
                ->pingOnFailure(config('envoyer.heartbeats.notification_dashboard_no_uploaded_videos_failure'))
                ->weekly();

            $schedule->command('notifications:creator')
                ->pingOnSuccess(config('envoyer.heartbeats.notifications_creator'))
                ->pingOnFailure(config('envoyer.heartbeats.notifications_creator_failure'))
                ->dailyAt('00:25');

            $schedule->command('custom-request:notifications')
                ->pingOnSuccess(config('envoyer.heartbeats.custom_requests_check'))
                ->pingOnFailure(config('envoyer.heartbeats.custom_requests_check_failure'))
                ->dailyAt('00:30');

            $schedule->command('chat:chat_daily_mailer')
                ->pingOnSuccess(config('envoyer.heartbeats.chat_daily_mailer'))
                ->pingOnFailure(config('envoyer.heartbeats.chat_daily_mailer_failure'))
                ->dailyAt('00:45');

//            $schedule->command('sendgrid:import')
//                ->pingOnSuccess(config('envoyer.heartbeats.sendgrid_importer'))
//                ->pingOnFailure(config('envoyer.heartbeats.sendgrid_importer_failure'))
//                ->dailyAt('02:22');

            $schedule->command('cams:online')->everyTenMinutes();
            $schedule->command('cams:categories')->everyFifteenMinutes();
        }

        $schedule->command('payments:refund-for-custom-video-request')
            ->pingOnSuccess(config('envoyer.heartbeats.payments_refund_for_custom_video_request'))
            ->pingOnFailure(config('envoyer.heartbeats.payments_refund_for_custom_video_request_failure'))
            ->dailyAt('01:00');

        $schedule->command('processing-partners:recurring')
            ->pingOnSuccess(config('envoyer.heartbeats.processing_partners_recurring'))
            ->pingOnFailure(config('envoyer.heartbeats.processing_partners_recurring_failure'))
            ->dailyAt('01:15');

        $schedule->command('gpnData:recurring')
            ->pingOnSuccess(config('envoyer.heartbeats.gpn_data_recurring'))
            ->pingOnFailure(config('envoyer.heartbeats.gpn_data_recurring_failure'))
            ->dailyAt('01:20');

        $schedule->command('internal:recurring')
            ->pingOnSuccess(config('envoyer.heartbeats.internal_recurring'))
            ->pingOnFailure(config('envoyer.heartbeats.internal_recurring_failure'))
            ->dailyAt('01:30');

        $schedule->command('remove:unsuccessful-uploads-with-empty-file_url')
            ->pingOnSuccess(config('envoyer.heartbeats.remove_unsuccessful_uploads_with_empty_file_url'))
            ->pingOnFailure(config('envoyer.heartbeats.remove_unsuccessful_uploads_with_empty_file_url_failure'))
            ->dailyAt('02:02');

        $schedule->command('sitemap:regenerate-xml-files')
            ->pingOnSuccess(config('envoyer.heartbeats.sitemap_regenerate_xml_files'))
            ->pingOnFailure(config('envoyer.heartbeats.sitemap_regenerate_xml_files_failure'))
            ->dailyAt('02:12');

        $schedule->command('model:update-creators-rank')
            ->pingOnSuccess(config('envoyer.heartbeats.model_update_creators_rank'))
            ->pingOnFailure(config('envoyer.heartbeats.model_update_creators_rank_failure'))
            ->dailyAt('03:01');

        $schedule->command('video:calculate-video-scores')
            ->pingOnSuccess(config('envoyer.heartbeats.video_calculate_video_scores'))
            ->pingOnFailure(config('envoyer.heartbeats.video_calculate_video_scores_failure'))
            ->dailyAt('03:02');

        $schedule->command('internal:preauthorization-refund')
            ->pingOnSuccess(config('envoyer.heartbeats.payments_refund_for_pre_authorization'))
            ->pingOnFailure(config('envoyer.heartbeats.payments_refund_for_pre_authorization_failure'))
            ->cron('0 */5 * * *'); // Every 5 hours

        $schedule->command('payments:fanclub-financial-accrual')
            ->pingOnSuccess(config('envoyer.heartbeats.payments_fanclub_financial_accrual'))
            ->pingOnFailure(config('envoyer.heartbeats.payments_fanclub_financial_accrual_failure'))
            ->hourlyAt(15);

        $schedule->command('model:delete-creator-accounts')
            ->pingOnSuccess(config('envoyer.heartbeats.model_delete_creator_accounts'))
            ->pingOnFailure(config('envoyer.heartbeats.model_delete_creator_accounts_failure'))
            ->hourlyAt(30);

        $schedule->command('model:restore-creator-accounts')
            ->pingOnSuccess(config('envoyer.heartbeats.model_restore_creator_accounts'))
            ->pingOnFailure(config('envoyer.heartbeats.model_restore_creator_accounts_failure'))
            ->hourlyAt(32);

        $schedule->command('entity:increment-and-collect-page-views')
            ->everyFiveMinutes();

        $schedule->command('users:reset-pp-channel')->dailyAt('23:59')->when(function () {
            return now()->endOfMonth()->isToday();
        });

        $schedule->command('finance:monthly-balance-report')->dailyAt('00:05')->when(function () {
            return now()->startOfMonth()->isToday();
        });

        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        $schedule->command('video:notify-upload-issues')->everyTenMinutes();

        $schedule->command('video:notify-transcoding-issues')->hourly();

        $schedule->job(new PublishingMessages,'API')->everyFiveMinutes();
        $schedule->job(new PublishingSchedulePosts,'API')->everyFifteenMinutes();
        $schedule->job(new PublishingScheduleVideos,'API')->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        include base_path('routes/console.php');
    }
}
