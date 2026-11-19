<?php

namespace ErnestDefoe\TrustLevels\Tests\integration;

/**
 * Fixtures and checks written with the old `users.trust_*` names: the trust
 * keys go to (and come back from) `trust_states`.
 */
trait TrustFixtures
{
    /** @var array<string, string> old users column => trust_states column */
    private static array $trustColumns = [
        'trust_level' => 'level', 'trust_level_at' => 'level_at', 'trust_locked' => 'locked',
        'trust_silenced_at' => 'silenced_at', 'trust_checked_at' => 'checked_at', 'trust_note' => 'note',
    ];

    /**
     * @param array<string, array<int, array<string, mixed>>> $fixtures
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function trustFixtures(array $fixtures): array
    {
        foreach ($fixtures[\Flarum\User\User::class] ?? [] as $i => $row) {
            [$user, $state] = $this->splitTrust($row);

            if ($state) {
                $fixtures['trust_states'][] = ['user_id' => $row['id']] + $state;
            }

            $fixtures[\Flarum\User\User::class][$i] = $user;
        }

        return $fixtures;
    }

    /**
     * Updates members: trust keys (old or new names) to trust_states, the rest to users.
     *
     * @param int|int[] $ids
     * @param array<string, mixed> $values
     */
    protected function setTrust(int|array $ids, array $values): void
    {
        [$user, $state] = $this->splitTrust($values);

        foreach ((array) $ids as $id) {
            if ($state) {
                $this->database()->table('trust_states')->upsert([['user_id' => $id] + $state], ['user_id'], array_keys($state));
            }

            if ($user) {
                $this->database()->table('users')->where('id', $id)->update($user);
            }
        }
    }

    /** The users row with the member's trust state under the old names (defaults when there's no row). */
    protected function trustRow(int $id): object
    {
        $user = (array) $this->database()->table('users')->where('id', $id)->first();
        $state = (array) ($this->database()->table('trust_states')->where('user_id', $id)->first() ?? []);

        foreach (self::$trustColumns as $old => $new) {
            $user[$old] = $state[$new] ?? match ($new) {
                'level' => 0,
                'locked' => false,
                default => null,
            };
        }

        return (object) $user;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitTrust(array $row): array
    {
        $state = [];

        foreach ($row as $key => $value) {
            $column = self::$trustColumns[$key] ?? (in_array($key, self::$trustColumns, true) ? $key : null);

            if ($column !== null) {
                $state[$column] = $value;
                unset($row[$key]);
            }
        }

        return [$row, $state];
    }
}
