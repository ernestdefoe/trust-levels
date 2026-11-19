<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Access\Exempt;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Evaluator;
use ErnestDefoe\TrustLevels\Job\ConfirmSpam;
use ErnestDefoe\TrustLevels\Job\RunCheck;
use ErnestDefoe\TrustLevels\LevelWriter;
use ErnestDefoe\TrustLevels\Spam\Spam;
use ErnestDefoe\TrustLevels\TrustState;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Tobyz\JsonApiServer\Context as BaseContext;
use WeakMap;

use function Tobyz\JsonApiServer\json_api_response;

/**
 * A member's trust level and progress, as the `trust-levels` type (id = the
 * member's user id). Only the member and staff with the manage permission
 * find it; for anyone else it doesn't exist (404), so it can't be used to
 * learn someone's level.
 *
 * - Show: the Trust level tab (Progress).
 * - Update {level?, locked?, note?, silenced: false}: staff only, never on
 *   their own account nor, unless they're an admin, on another member of
 *   staff's; Leader and raises that hand over a level group's permissions
 *   are an admin's call. One transaction.
 * - POST /{id}/spam: "Confirm spam" on a member on hold, queued (202).
 * - Admin only: GET /dashboard, POST /run ("Run now", queued) and POST
 *   /groups ("Create level groups"), all answering in `meta`.
 *
 * @extends AbstractDatabaseResource<TrustState>
 */
class TrustStateResource extends AbstractDatabaseResource
{
    public const MANAGE = 'ernestdefoe-trust-levels.manage';

    /** @var WeakMap<TrustState, array<string, mixed>> what an update asked for */
    private WeakMap $changes;

    /** @var WeakMap<TrustState, array<string, mixed>> the progress tab, worked out once per model */
    private WeakMap $shown;

    public function __construct(
        private Progress $progress,
        private LevelWriter $writer,
        private Spam $spam,
        private Exempt $exempt,
        private Config $config,
        private ConnectionInterface $db,
        private Queue $queue,
        private Dashboard $dashboard,
        private Evaluator $evaluator,
        private LevelGroups $groups,
    ) {
        $this->changes = new WeakMap();
        $this->shown = new WeakMap();
    }

    public function type(): string
    {
        return 'trust-levels';
    }

    public function model(): string
    {
        return TrustState::class;
    }

    /** The member, if the actor may see their level: their state (a default one when they have no row). */
    public function find(string $id, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        $user = User::query()->find((int) $id);

        if (! $user || ($actor->id !== $user->id && ! $actor->hasPermission(self::MANAGE))) {
            return null;
        }

        $state = TrustState::of($user);
        $state->setRelation('user', $user);

        return $state;
    }

    public function endpoints(): array
    {
        // The static admin paths come before Show's /{id}, or FastRoute treats them as shadowed.
        return [
            Endpoint\Endpoint::make('dashboard')
                ->route('GET', '/dashboard')
                ->admin()
                ->action(fn () => ['meta' => $this->dashboard->data() + ['busy' => false, 'queued' => false]]),
            // Run now: queued (202) unless a run already holds the lock (200, busy); the page polls until lastRun moves.
            Endpoint\Endpoint::make('run')
                ->route('POST', '/run')
                ->admin()
                ->action(function () {
                    $busy = $this->evaluator->running();

                    if (! $busy) {
                        $this->queue->push(new RunCheck());
                    }

                    return ['meta' => $this->dashboard->data() + ['busy' => $busy, 'queued' => ! $busy]];
                })
                ->response(fn (Context $context, array $data) => json_api_response($data, $data['meta']['queued'] ? 202 : 200)),
            Endpoint\Endpoint::make('groups')
                ->route('POST', '/groups')
                ->admin()
                ->action(fn () => ['meta' => ['groups' => $this->groups->create()]]),
            Endpoint\Endpoint::make('spam')
                ->route('POST', '/{id}/spam')
                ->authenticated()
                ->can(self::MANAGE)
                ->action(function (Context $context) {
                    $this->assertMayChange($context->getActor(), $context->model->user);
                    $this->queue->push(new ConfirmSpam((int) $context->model->user_id, (int) $context->getActor()->id));

                    return null;
                })
                ->response(fn () => new EmptyResponse(202)),
            Endpoint\Show::make()
                ->authenticated(),
            Endpoint\Update::make()
                ->authenticated()
                ->can(self::MANAGE)
                ->before(fn (Context $context) => $this->assertMayChange($context->getActor(), $context->model->user)),
        ];
    }

    public function fields(): array
    {
        $staff = fn (TrustState $state, Context $context) => $context->getActor()->hasPermission(self::MANAGE);
        $shown = fn (string $key) => fn (TrustState $state, Context $context) => $this->shown($state, $context)[$key];
        $ask = fn (string $key) => function (TrustState $state, mixed $value) use ($key) {
            $this->changes[$state] = [$key => $value] + ($this->changes[$state] ?? []);
        };

        return [
            Schema\Integer::make('level')->get($shown('level'))->writable($staff)->min(0)->max(Config::MAX)->set($ask('level')),
            Schema\Boolean::make('locked')->get($shown('locked'))->writable($staff)->set($ask('locked')),
            // Only lifting a hold: one is put on by reports, never by hand.
            Schema\Boolean::make('silenced')->get($shown('silenced'))->writable($staff)->set($ask('silenced')),
            Schema\Str::make('note')->nullable()->visible($staff)->get($shown('note'))->writable($staff)->maxLength(200)->set($ask('note')),
            Schema\Str::make('levelAt')->nullable()->get($shown('levelAt')),
            Schema\Str::make('joinedAt')->nullable()->get($shown('joinedAt')),
            Schema\Str::make('computedAt')->nullable()->get($shown('computedAt')),
            Schema\Arr::make('next')->nullable()->get($shown('next')),
            Schema\Arr::make('keep')->nullable()->get($shown('keep')),
            Schema\Arr::make('unlocks')->get($shown('unlocks')),
            Schema\Arr::make('stats')->nullable()->visible($staff)->get($shown('stats')),
            Schema\Arr::make('history')->get($shown('history')),
        ];
    }

    public function update(object $model, BaseContext $context): object
    {
        $user = $model->user;
        $actor = $context->getActor();
        $changes = $this->changes[$model] ?? [];

        if (! $actor->isAdmin() && $this->grantsWhatOnlyAdminsMay($model, $changes)) {
            throw new PermissionDeniedException();
        }

        // All of it or none: a level, lock, note and hold change together.
        $this->db->transaction(fn () => $this->apply($user, $actor, $changes));

        unset($this->changes[$model], $this->shown[$model]);

        return $model;
    }

    /** @return array<string, mixed> */
    private function shown(TrustState $state, Context $context): array
    {
        return $this->shown[$state] ??= $this->progress->for($state->user, $context->getActor()->hasPermission(self::MANAGE));
    }

    /**
     * Nobody changes their own level, lock or hold: a moderator could otherwise
     * make themselves Leader and take its permissions. Nor, unless they're an
     * admin, another member of staff's.
     */
    private function assertMayChange(User $actor, User $user): void
    {
        if ($actor->id === $user->id || (! $actor->isAdmin() && ($user->isAdmin() || $user->hasPermission(self::MANAGE)))) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * Leader, and any raise that hands over a level group's permissions, are
     * an admin's call. Moderators can still lower, lock and lift holds.
     *
     * @param array<string, mixed> $changes
     */
    private function grantsWhatOnlyAdminsMay(TrustState $state, array $changes): bool
    {
        if (! isset($changes['level'])) {
            return false;
        }

        $from = $state->level;
        $to = (int) $changes['level'];

        if ($to <= $from) {
            return false;
        }

        if ($to === Config::MAX) {
            return true;
        }

        $groups = array_values(array_filter($this->config->groups(), fn ($level) => $level > $from && $level <= $to, ARRAY_FILTER_USE_KEY));

        return $groups && $this->db->table('group_permission')->whereIn('group_id', $groups)->exists();
    }

    /** @param array<string, mixed> $changes */
    private function apply(User $user, User $actor, array $changes): void
    {
        if (array_key_exists('note', $changes)) {
            $note = trim((string) $changes['note']);
            TrustState::put($user, ['note' => $note === '' ? null : mb_substr($note, 0, 200)]);
        }

        if (isset($changes['level'])) {
            $this->writer->set($user, (int) $changes['level'], 'staff', $actor);
        }

        $state = TrustState::of($user);

        if (isset($changes['locked']) && (bool) $changes['locked'] !== $state->locked) {
            $locked = (bool) $changes['locked'];
            $values = ['locked' => $locked];

            // A lock is a placement too: a member locked before their first check
            // would otherwise be picked up as unplaced by every run. At the level they
            // count as now (a member from before the install is Basic), never by
            // resetting them to the 0 stored before their first check.
            if (! $state->level_at) {
                $values += ['level' => $this->exempt->level($user), 'level_at' => Carbon::now()];
            }

            $state = TrustState::put($user, $values);
            $this->writer->log($user->id, $locked ? 'locked' : 'unlocked', null, $state->level, $actor->id);
        }

        if (($changes['silenced'] ?? null) === false && $state->silenced_at) {
            $this->spam->unsilence($user, $actor);
        }
    }
}
