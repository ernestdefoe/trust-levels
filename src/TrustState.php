<?php

namespace ErnestDefoe\TrustLevels;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member's trust level, lock, hold and staff note: one row in Trust Levels'
 * own table, keyed on the user (never columns on `users`). A member with no
 * row is New, unplaced, unlocked and not on hold.
 *
 * The User relation `trustState` is registered in extend.php. Other extensions
 * read a member's level with TrustState::of($user)->level and filter members
 * with whereLevel() / whereSilenced(), which also count members without a row.
 *
 * @property int $user_id
 * @property int $level
 * @property Carbon|null $level_at when they reached it (null = not placed yet)
 * @property bool $locked
 * @property Carbon|null $silenced_at
 * @property Carbon|null $checked_at
 * @property string|null $note
 * @property User|null $user
 */
class TrustState extends AbstractModel
{
    protected $table = 'trust_states';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;


    protected $casts = [
        'user_id' => 'integer',
        'level' => 'integer',
        'locked' => 'boolean',
        'level_at' => 'datetime',
        'silenced_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // A member with no row: New and unlocked (Flarum's model replaces $attributes).
        $this->attributes += ['level' => 0, 'locked' => false];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The member's state: the loaded (or lazily loaded) row, or an unsaved
     * default one that saving creates. Set on the user, so it's read once.
     */
    public static function of(User $user): self
    {
        $state = $user->exists ? $user->getRelationValue('trustState') : null;

        if (! $state) {
            $state = new self();
            $state->user_id = (int) $user->id;

            if ($user->exists) {
                $user->setRelation('trustState', $state);
            }
        }

        return $state;
    }

    /**
     * Writes these fields for the member (an upsert, so two requests making
     * the first row at once don't collide) and keeps the loaded state in step.
     *
     * @param array<string, mixed> $values
     */
    public static function put(User $user, array $values): self
    {
        static::query()->upsert([['user_id' => (int) $user->id] + $values], ['user_id'], array_keys($values));

        $state = static::of($user);
        $state->forceFill($values)->syncOriginal();
        $state->exists = true;

        return $state;
    }

    /**
     * Narrows a users query to members at these levels. Level 0 includes
     * members with no row.
     *
     * @param Builder<User> $users
     * @param int[] $levels
     * @return Builder<User>
     */
    public static function whereLevel(Builder $users, array $levels): Builder
    {
        return $users->where(function (Builder $q) use ($levels) {
            $q->whereHas('trustState', fn (Builder $s) => $s->whereIn('level', $levels));

            if (in_array(0, $levels, true)) {
                $q->orWhereDoesntHave('trustState');
            }
        });
    }

    /**
     * Narrows a users query to members on hold.
     *
     * @param Builder<User> $users
     * @return Builder<User>
     */
    public static function whereSilenced(Builder $users): Builder
    {
        return $users->whereHas('trustState', fn (Builder $s) => $s->whereNotNull('silenced_at'));
    }
}
