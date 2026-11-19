<?php

namespace ErnestDefoe\TrustLevels\Listener;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Http\RememberActor;
use ErnestDefoe\TrustLevels\Job\CheckMember;
use ErnestDefoe\TrustLevels\TrustState;
use Flarum\Discussion\Event\UserRead;
use Flarum\Post\Event\Posted;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;

/**
 * Members at New or Basic are checked right after they read or post, at most
 * once every 10 minutes each, so promotions up to Member don't wait for the
 * hourly run and work on hosts with no cron at all. The check itself is a
 * queued job (CheckMember); the 10 minutes start when it's queued, so a member
 * reading fast never queues a second one.
 */
class QuickCheck
{
    public const EVERY_MINUTES = 10;

    public function __construct(private Queue $queue, private RememberActor $current)
    {
    }

    public function read(UserRead $event): void
    {
        $actor = $this->current->actor;

        if ($actor && (int) $actor->id === (int) $event->state->user_id) {
            $this->check($actor);
        }
    }

    public function posted(Posted $event): void
    {
        if ($event->actor && $event->actor->id === $event->post->user_id) {
            $this->check($event->actor);
        }
    }

    private function check(User $user): void
    {
        if ($user->isGuest()) {
            return;
        }

        $state = TrustState::of($user);

        if ($state->level > 1 || $state->locked) {
            return;
        }

        if ($state->checked_at && $state->checked_at->copy()->addMinutes(self::EVERY_MINUTES)->isFuture()) {
            return;
        }

        TrustState::put($user, ['checked_at' => Carbon::now()]);

        $this->queue->push(new CheckMember((int) $user->id));
    }
}
