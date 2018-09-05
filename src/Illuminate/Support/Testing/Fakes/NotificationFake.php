<?php

namespace Illuminate\Support\Testing\Fakes;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;
use Illuminate\Contracts\Notifications\Factory as NotificationFactory;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;

class NotificationFake implements NotificationFactory, NotificationDispatcher
{
    /**
     * All of the notifications that have been sent.
     *
     * @var array
     */
    protected $notifications = [];

    /**
     * Locale used when sending notifications.
     *
     * @var string|null
     */
    public $locale;

    /**
     * Assert if a notification was sent based on a truth-test callback.
     *
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return void
     */
    public function assertSent($notification, $callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertSentTimes($notification, $callback);
        }

        PHPUnit::assertTrue(
            $this->sent($notification, $callback)->count() > 0,
            "The expected [{$notification}] notification was not sent."
        );
    }

    /**
     * Assert if a notification was sent to a notifiable entity based on a truth-test callback.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return void
     */
    public function assertSentTo($notifiable, $notification, $callback = null)
    {
        if (is_array($notifiable) || $notifiable instanceof Collection) {
            foreach ($notifiable as $singleNotifiable) {
                $this->assertSentTo($singleNotifiable, $notification, $callback);
            }

            return;
        }

        if (is_numeric($callback)) {
            return $this->assertSentToTimes($notifiable, $notification, $callback);
        }

        PHPUnit::assertTrue(
            $this->sentTo($notifiable, $notification, $callback)->count() > 0,
            "The expected [{$notification}] notification was not sent."
        );
    }

    /**
     * Assert if a notification was sent a number of times.
     *
     * @param  string  $notification
     * @param  int  $times
     * @return void
     */
    public function assertSentTimes($notification, $times = 1)
    {
        PHPUnit::assertTrue(
            ($count = $this->sent($notification)->count()) === $times,
            "Expected [{$notification}] to be sent {$times} times, but was sent {$count} times."
        );
    }

    /**
     * Assert if a notification was sent to a notifiable entity a number of times.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @param  int  $times
     * @return void
     */
    public function assertSentToTimes($notifiable, $notification, $times = 1)
    {
        PHPUnit::assertTrue(
            ($count = $this->sentTo($notifiable, $notification)->count()) === $times,
            "Expected [{$notification}] to be sent {$times} times, but was sent {$count} times."
        );
    }

    /**
     * Determine if a notification was sent based on a truth-test callback.
     *
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return void
     */
    public function assertNotSent($notification, $callback = null)
    {
        PHPUnit::assertTrue(
            $this->sent($notification, $callback)->count() === 0,
            "The unexpected [{$notification}] notification was sent."
        );
    }

    /**
     * Determine if a notification was sent to a notifiable entity based on a truth-test callback.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return void
     */
    public function assertNotSentTo($notifiable, $notification, $callback = null)
    {
        if (is_array($notifiable) || $notifiable instanceof Collection) {
            foreach ($notifiable as $singleNotifiable) {
                $this->assertNotSentTo($singleNotifiable, $notification, $callback);
            }

            return;
        }

        PHPUnit::assertTrue(
            $this->sentTo($notifiable, $notification, $callback)->count() === 0,
            "The unexpected [{$notification}] notification was sent."
        );
    }

    /**
     * Assert that no notifications were sent.
     *
     * @return void
     */
    public function assertNothingSent()
    {
        PHPUnit::assertEmpty($this->notifications, 'Notifications were sent unexpectedly.');
    }

    /**
     * Assert the total amount of times a notification was sent.
     *
     * @param  int  $expectedCount
     * @param  string  $notification
     * @return void
     */
    public function assertTimesSent($expectedCount, $notification)
    {
        $actualCount = collect($this->notifications)
            ->flatten(1)
            ->reduce(function ($count, $sent) use ($notification) {
                return $count + count($sent[$notification] ?? []);
            }, 0);

        PHPUnit::assertSame(
            $expectedCount, $actualCount,
            "Expected [{$notification}] to be sent {$expectedCount} times, but was sent {$actualCount} times."
        );
    }

    /**
     * Get all of the notifications matching a truth-test callback.
     *
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return \Illuminate\Support\Collection
     */
    public function sent($notification, $callback = null)
    {
        if (! $this->hasSent($notification)) {
            return collect();
        }

        $callback = $callback ?: function () {
            return true;
        };

        $notifications = collect($this->notificationsOfType($notification));

        return $notifications->filter(function ($arguments) use ($callback) {
            return $callback(...array_values($arguments));
        })->pluck('notification');
    }

    /**
     * Get all of the notifications sent to a notifiable entity matching a truth-test callback.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @param  callable|null  $callback
     * @return \Illuminate\Support\Collection
     */
    public function sentTo($notifiable, $notification, $callback = null)
    {
        if (! $this->hasSentTo($notifiable, $notification)) {
            return collect();
        }

        $callback = $callback ?: function () {
            return true;
        };

        $notifications = collect($this->notificationsFor($notifiable, $notification));

        return $notifications->filter(function ($arguments) use ($callback) {
            return $callback(...array_values($arguments));
        })->pluck('notification');
    }

    /**
     * Determine if there are more notifications left to inspect.
     *
     * @param  string  $notification
     * @return bool
     */
    public function hasSent($notification)
    {
        return ! empty($this->notificationsOfType($notification));
    }

    /**
     * Determine if there are more notifications for a notifiable entity left to inspect.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @return bool
     */
    public function hasSentTo($notifiable, $notification)
    {
        return ! empty($this->notificationsFor($notifiable, $notification));
    }

    /**
     * Get all of the notifications for a notifiable entity by type.
     *
     * @param  mixed  $notifiable
     * @param  string  $notification
     * @return array
     */
    protected function notificationsFor($notifiable, $notification)
    {
        if (isset($this->notifications[get_class($notifiable)][$notifiable->getKey()][$notification])) {
            return $this->notifications[get_class($notifiable)][$notifiable->getKey()][$notification];
        }

        return [];
    }

    /**
     * Get all of the notifications by type.
     *
     * @param  string  $notification
     * @return array
     */
    protected function notificationsOfType($notification)
    {
        return Arr::collapse(Arr::collapse($this->notifications));

        if (isset($notifications[$notification])) {
            return $notifications[$notification];
        }

        return [];
    }

    /**
     * Send the given notification to the given notifiable entities.
     *
     * @param  \Illuminate\Support\Collection|array|mixed  $notifiables
     * @param  mixed  $notification
     * @return void
     */
    public function send($notifiables, $notification)
    {
        return $this->sendNow($notifiables, $notification);
    }

    /**
     * Send the given notification immediately.
     *
     * @param  \Illuminate\Support\Collection|array|mixed  $notifiables
     * @param  mixed  $notification
     * @return void
     */
    public function sendNow($notifiables, $notification)
    {
        if (! $notifiables instanceof Collection && ! is_array($notifiables)) {
            $notifiables = [$notifiables];
        }

        foreach ($notifiables as $notifiable) {
            if (! $notification->id) {
                $notification->id = Str::uuid()->toString();
            }

            $this->notifications[get_class($notifiable)][$notifiable->getKey()][get_class($notification)][] = [
                'notification' => $notification,
                'channels' => $notification->via($notifiable),
                'notifiable' => $notifiable,
                'locale' => $notification->locale ?? $this->locale,
            ];
        }
    }

    /**
     * Get a channel instance by name.
     *
     * @param  string|null  $name
     * @return mixed
     */
    public function channel($name = null)
    {
        //
    }

    /**
     * Set the locale of notifications.
     *
     * @param  string  $locale
     * @return $this
     */
    public function locale($locale)
    {
        $this->locale = $locale;

        return $this;
    }
}
