<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Names;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * "Create level groups": a hidden group for each level that has none
 * ("Trust 2 · Member"), tied to the level.
 */
class LevelGroups
{
    public function __construct(private Config $config, private SettingsRepositoryInterface $settings, private Names $names, private Dispatcher $events)
    {
    }

    /** @return array<int, int|null> each level's group */
    public function create(): array
    {
        $all = $this->config->all();
        $raw = json_decode((string) $this->settings->get(Config::KEY), true) ?: [];

        for ($level = 1; $level <= Config::MAX; $level++) {
            if (! empty($all['groups'][$level]) && Group::query()->whereKey($all['groups'][$level])->exists()) {
                continue;
            }

            $name = 'Trust '.$level.' · '.$this->names->name($level);
            $group = new Group();
            $group->name_singular = $name;
            $group->name_plural = $name;
            $group->icon = 'fas fa-shield-halved';
            $group->is_hidden = true;
            $group->save();
            $raw['groups'][$level] = $group->id;

            // Core's Group\Event\Created, as when a group is made in the admin.
            foreach ($group->releaseEvents() as $event) {
                $this->events->dispatch($event);
            }
        }

        $this->settings->set(Config::KEY, json_encode($raw));
        $this->config->refresh();

        return $this->config->all()['groups'];
    }
}
