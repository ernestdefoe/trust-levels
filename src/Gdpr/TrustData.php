<?php

namespace ErnestDefoe\TrustLevels\Gdpr;

use ErnestDefoe\TrustLevels\TrustState;
use Flarum\Gdpr\Data\Type;

/**
 * A member's level, daily activity counts, rollup and trust history, in a
 * data export; all go on erasure and anonymizing (the rows also go with the
 * account through their foreign keys), so an anonymized account starts over
 * at New, unlocked and not on hold. Registered only when flarum/gdpr is enabled. gdpr
 * builds its types with `new`, not the container, so the queries go through
 * the member's own database connection.
 */
class TrustData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-trust-levels.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-trust-levels.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = $this->user->getConnection();
        $id = $this->user->id;
        $state = TrustState::of($this->user);

        return [
            ['trust-levels/level.json' => $this->encodeForExport([
                'level' => $state->level,
                'since' => $state->level_at,
                'locked' => $state->locked,
                'on_hold_since' => $state->silenced_at,
            ])],
            ['trust-levels/days.json' => $this->encodeForExport($db->table('trust_daily')->where('user_id', $id)->orderBy('day')->get(['day', 'posts_read', 'topics_entered', 'read_seconds'])->all())],
            ['trust-levels/history.json' => $this->encodeForExport($db->table('trust_events')->where('user_id', $id)->orderBy('id')->get(['type', 'from_level', 'to_level', 'created_at'])->all())],
        ];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = $this->user->getConnection();
        $id = $this->user->id;

        // The level, lock, hold and staff's free-text note go with the row.
        TrustState::query()->whereKey($id)->delete();
        $db->table('trust_daily')->where('user_id', $id)->delete();
        $db->table('trust_stats')->where('user_id', $id)->delete();
        $db->table('trust_events')->where('user_id', $id)->delete();
        $db->table('trust_events')->where('actor_id', $id)->update(['actor_id' => null]);
    }
}
