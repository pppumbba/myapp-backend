<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails notifications that Notifier queued for email (email_status 'queued'), one mail per
 * person, and marks each sent or failed. A row that is no longer queued is skipped, so a job
 * run twice doesn't mail twice.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $ids  user_notifications rows */
    public function __construct(public array $ids) {}

    public function handle(): void
    {
        $rows = UserNotification::whereIn('id', $this->ids)->where('email_status', 'queued')->get();
        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        foreach ($rows as $row) {
            $user = $users->get($row->user_id);
            try {
                if (! $user?->email) {
                    throw new \RuntimeException('This person has no email address.');
                }
                Mail::to($user->email)->locale($user->locale ?: config('app.locale'))->send(new NotificationMail($row));
                $row->forceFill(['email_status' => 'sent', 'email_error' => null, 'emailed_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
                $row->forceFill(['email_status' => 'failed', 'email_error' => Str::limit($e->getMessage(), 250)])->save();
            }
        }
    }
}
