<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\Docket\Crowd\Crowd;
use Illuminate\Database\ConnectionInterface;

/**
 * Docket's crowd moderation, with each reporter weighed by their level.
 *
 * Bound in place of Docket's own Crowd only while Docket is enabled (this
 * file is never loaded otherwise). Level 0 counts 0.5, level 4 counts 1.5;
 * with Docket's own track-record weighting on, the two are averaged. One
 * query for all the reporters of a report.
 */
class DocketCrowd extends Crowd
{
    public const LEVEL_WEIGHTS = [0 => 0.5, 1 => 0.75, 2 => 1.0, 3 => 1.25, 4 => 1.5];

    public function weights(array $userIds, ?int $exceptReport = null): array
    {
        $weights = parent::weights($userIds, $exceptReport);

        if (! $userIds) {
            return $weights;
        }

        $trackRecord = (bool) $this->setting('trust');
        $levels = resolve(ConnectionInterface::class)->table('users')->whereIn('id', $userIds)->pluck('trust_level', 'id');

        foreach ($levels as $id => $level) {
            $byLevel = self::LEVEL_WEIGHTS[min(4, (int) $level)];
            $weights[(int) $id] = $trackRecord ? ($weights[(int) $id] + $byLevel) / 2 : $byLevel;
        }

        return $weights;
    }
}
