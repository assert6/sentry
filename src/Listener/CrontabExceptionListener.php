<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\Sentry\Listener;

use FriendsOfHyperf\Sentry\Switcher;
use Hyperf\Crontab\Event;

class CrontabExceptionListener extends CaptureExceptionListener
{
    public function __construct(protected Switcher $switcher)
    {
    }

    public function listen(): array
    {
        return [
            Event\BeforeExecute::class, /* @phpstan-ignore-line */
            Event\FailToExecute::class,
        ];
    }

    /**
     * @param \Hyperf\Crontab\Event\FailToExecute $event
     */
    public function process(object $event): void
    {
        if (! $this->switcher->isEnable('crontab')) {
            return;
        }

        match ($event::class) {
            Event\FailToExecute::class => $this->captureException($event->throwable),
            default => $this->setupSentrySdk(),
        };
    }
}
