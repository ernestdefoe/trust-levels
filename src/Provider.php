<?php

namespace ErnestDefoe\TrustLevels;

use ErnestDefoe\TrustLevels\Http\RememberActor;
use ErnestDefoe\TrustLevels\Tracking\Tracker;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Config::class);
        $this->container->singleton(RememberActor::class);
        // Holds per-request batches (keyed by the request), so one instance for all.
        $this->container->singleton(Api\Fields\StateBatch::class);
        $this->container->bind(Integration\NewMemberQueue::class, Integration\NoQueue::class);
    }

    public function boot(Tracker $tracker): void
    {
        // A visit: core's last-seen write, the first one each day.
        User::saving(fn (User $user) => $tracker->saving($user));
    }
}
