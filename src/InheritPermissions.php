<?php

namespace ErnestDefoe\TrustLevels;

use Flarum\User\User;

/**
 * A level's group gives its permissions to everyone at that level and above.
 *
 * Members are never put in the groups: the level column decides, here, at
 * permission time. So a group can hold a permission once ("Trust 2" may
 * upload) and every higher level has it, no badge ever shows, and changing a
 * member's level is one column, not group membership.
 *
 * Runs on every permission check: reads the actor's column and the config
 * already in memory, nothing else.
 */
class InheritPermissions
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param int[] $groupIds
     * @return int[]
     */
    public function __invoke(User $user, array $groupIds): array
    {
        $level = (int) $user->getAttribute('trust_level');

        if ($level < 1 || $user->isGuest() || ! $user->is_email_confirmed) {
            return $groupIds;
        }

        foreach ($this->config->groups() as $groupLevel => $groupId) {
            if ($groupLevel <= $level) {
                $groupIds[] = $groupId;
            }
        }

        return array_values(array_unique($groupIds));
    }
}
