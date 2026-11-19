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
    }

    public function boot(Tracker $tracker): void
    {
        // A visit: core's last-seen write, the first one each day.
        User::saving(fn (User $user) => $tracker->saving($user));
    }
}
