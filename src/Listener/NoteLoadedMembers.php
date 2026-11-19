<?php

namespace ErnestDefoe\TrustLevels\Listener;

use ErnestDefoe\TrustLevels\TrustState;
use Flarum\User\User;

/** `eloquent.retrieved` for users: noted, so their trust rows load together (TrustState::of). */
class NoteLoadedMembers
{
    public function handle(User $user): void
    {
        TrustState::noteLoaded($user);
    }
}
