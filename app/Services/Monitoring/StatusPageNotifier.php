<?php

namespace App\Services\Monitoring;

use App\Mail\StatusPageNoticeMail;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscriber;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails status page subscribers: the double opt-in confirmation, a service going down or coming back,
 * and announcements. Only confirmed subscribers of published pages that allow subscriptions are mailed.
 */
class StatusPageNotifier
{
    public function sendConfirmation(StatusPageSubscriber $subscriber): void
    {
        $page = $subscriber->statusPage;

        $this->send($subscriber, new StatusPageNoticeMail(
            pageName: $page->name,
            heading: 'Confirm your subscription',
            body: "Please confirm that you want email updates from the {$page->name} status page. If you did not ask for this, ignore this email — nothing will be sent.",
            actionUrl: route('status-pages.subscription.confirm', ['token' => $subscriber->token]),
            actionLabel: 'Confirm subscription',
        ));
    }

    public function monitorDown(Monitor $monitor): void
    {
        $this->notifyPagesShowing($monitor, 'is down', "{$monitor->name} is currently not reachable. We are on it and will email you when it is back.");
    }

    public function monitorRecovered(Monitor $monitor, string $downFor): void
    {
        $this->notifyPagesShowing($monitor, 'is back up', "{$monitor->name} is working again after {$downFor}.");
    }

    public function announcementPosted(StatusPageAnnouncement $announcement): void
    {
        $page = $announcement->statusPage;

        foreach ($this->confirmedSubscribers($page) as $subscriber) {
            $this->send($subscriber, $this->pageMail($page, $subscriber, $announcement->title, (string) ($announcement->body ?: $announcement->typeLabel())));
        }
    }

    private function notifyPagesShowing(Monitor $monitor, string $what, string $body): void
    {
        $pages = $monitor->statusPages()->where('is_published', true)->where('allow_subscriptions', true)->get();

        foreach ($pages as $page) {
            foreach ($this->confirmedSubscribers($page) as $subscriber) {
                $this->send($subscriber, $this->pageMail($page, $subscriber, "{$monitor->name} {$what}", $body));
            }
        }
    }

    /**
     * @return iterable<StatusPageSubscriber>
     */
    private function confirmedSubscribers(StatusPage $page): iterable
    {
        if (! $page->is_published || ! $page->allow_subscriptions) {
            return [];
        }

        return $page->subscribers()->confirmed()->lazyById(200);
    }

    private function pageMail(StatusPage $page, StatusPageSubscriber $subscriber, string $heading, string $body): StatusPageNoticeMail
    {
        return new StatusPageNoticeMail(
            pageName: $page->name,
            heading: $heading,
            body: $body,
            actionUrl: $page->publicUrl(),
            actionLabel: 'View status page',
            unsubscribeUrl: $subscriber->unsubscribeUrl(),
        );
    }

    private function send(StatusPageSubscriber $subscriber, StatusPageNoticeMail $mail): void
    {
        try {
            Mail::to($subscriber->email)->queue($mail);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
