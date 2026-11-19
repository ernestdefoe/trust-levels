<?php

namespace ErnestDefoe\TrustLevels\Access;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\TrustState;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who the limits never apply to: admins, holders of the bypass permission
 * (moderators by default) and the groups the admin lists.
 */
class Exempt
{
    public function __construct(private Config $config, private SettingsRepositoryInterface $settings)
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

    /**
     * The level limits and policies go by. A member who was here before the
     * install and hasn't been placed yet (the first check hasn't reached them)
     * counts as Basic, so enabling the extension never puts the whole forum
     * under New-member limits for an hour.
     */
    public function level(User $actor): int
    {
        if (! $actor->exists) {
            return 0;
        }

        $state = TrustState::of($actor);

        return $this->effective($state->level, $state->level_at, $actor->joined_at);
    }

    /** The same, from a stored level, when they reached it and when they joined. */
    private function effective(int $level, mixed $levelAt, mixed $joinedAt): int
    {
        if ($level === 0 && ! $levelAt) {
            $installed = $this->settings->get(Config::INSTALLED);

            if (! $installed || ! $joinedAt || Carbon::parse($joinedAt)->lt(Carbon::parse($installed))) {
                return 1;
            }
        }

        return $level;
    }

    public static function silenced(User $actor): bool
    {
        return $actor->exists && TrustState::of($actor)->silenced_at !== null;
    }
}
