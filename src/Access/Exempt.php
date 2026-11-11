<?php

namespace ErnestDefoe\TrustLevels\Access;

use ErnestDefoe\TrustLevels\Config;
use Flarum\User\User;

/**
 * Who the limits never apply to: admins, holders of the bypass permission
 * (moderators by default) and the groups the admin lists. Reads only what the
 * actor already has loaded.
 */
class Exempt
{
    public function __construct(private Config $config)
    {
    }

    public function is(User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-trust-levels.bypassLimits')) {
            return true;
        }

        $groups = $this->config->get('exempt_groups');

        return $groups && $actor->groups->whereIn('id', $groups)->isNotEmpty();
    }

    public static function level(User $actor): int
    {
        return (int) $actor->getAttribute('trust_level');
    }

    public static function silenced(User $actor): bool
    {
        return (bool) $actor->getAttribute('trust_silenced_at');
    }
}
