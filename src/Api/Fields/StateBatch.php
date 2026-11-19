<?php

namespace ErnestDefoe\TrustLevels\Api\Fields;

use Closure;
use ErnestDefoe\TrustLevels\TrustState;
use Flarum\Api\Context;
use Flarum\User\User;
use WeakMap;

/**
 * Every member in a response, loaded in one query: a user field asks for a
 * member's state; if it isn't loaded yet the member is noted and the
 * serializer gets a closure, and the first closure resolved loads all the
 * noted members' rows at once (a discussion page serializes every participant).
 * Keyed by the request object, so nothing outlives or crosses a request.
 */
class StateBatch
{
    /** @var WeakMap<object, array<int, User>> */
    private WeakMap $pending;

    public function __construct()
    {
        $this->pending = new WeakMap();
    }

    /**
     * @template T
     * @param Closure(TrustState): T $read
     * @return T|Closure(): T
     */
    public function read(User $user, Context $context, Closure $read): mixed
    {
        if ($user->relationLoaded('trustState')) {
            return $read(TrustState::of($user));
        }

        $request = $context->request;
        $pending = $this->pending[$request] ?? [];
        $pending[(int) $user->id] = $user;
        $this->pending[$request] = $pending;

        return function () use ($request, $user, $read) {
            $this->load($request);

            return $read(TrustState::of($user));
        };
    }

    private function load(object $request): void
    {
        $users = array_filter($this->pending[$request] ?? [], fn (User $u) => ! $u->relationLoaded('trustState'));
        $this->pending[$request] = [];

        if (! $users) {
            return;
        }

        $states = TrustState::query()->whereIn('user_id', array_keys($users))->get()->keyBy('user_id');

        foreach ($users as $id => $user) {
            $user->setRelation('trustState', $states->get($id));
        }
    }
}
