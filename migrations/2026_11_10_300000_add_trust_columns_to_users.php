<?php

use Flarum\Database\Migration;

/*
 * Where 1.0 kept a member's level. 2026_11_19_000001 moves these into
 * Trust Levels' own `trust_states` table and drops them; kept so the
 * migration history installs the same way everywhere.
 *
 * trust_level_at: when they reached it (null = not placed yet: members who
 * were here before the extension was installed, placed by the first run).
 */
return Migration::addColumns('users', [
    'trust_level' => ['tinyInteger', 'unsigned' => true, 'default' => 0],
    'trust_level_at' => ['dateTime', 'nullable' => true],
    'trust_locked' => ['boolean', 'default' => false],
    'trust_note' => ['string', 'length' => 200, 'nullable' => true],
    'trust_silenced_at' => ['dateTime', 'nullable' => true],
    'trust_checked_at' => ['dateTime', 'nullable' => true],
]);
