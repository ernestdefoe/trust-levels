<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A member's level, lock, hold and staff note, in Trust Levels' own table
 * (one row per member who has any of them; no row = New, unplaced, unlocked,
 * not on hold). Trust Levels 1.0 kept them as columns on `users`: where those
 * are there, every member's values are copied across and the columns go.
 * Down drops the table (uninstalling leaves nothing behind).
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('trust_states')) {
            $schema->create('trust_states', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->unsignedTinyInteger('level')->default(0)->index();
                $table->dateTime('level_at')->nullable();
                $table->boolean('locked')->default(false);
                $table->dateTime('silenced_at')->nullable()->index();
                $table->dateTime('checked_at')->nullable();
                $table->string('note', 200)->nullable();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! $schema->hasColumn('users', 'trust_level')) {
            return;
        }

        $db = $schema->getConnection();

        $db->table('trust_states')->insertUsing(
            ['user_id', 'level', 'level_at', 'locked', 'silenced_at', 'checked_at', 'note'],
            $db->table('users')
                ->where(fn ($q) => $q->where('trust_level', '>', 0)->orWhereNotNull('trust_level_at')->orWhere('trust_locked', true)
                    ->orWhereNotNull('trust_silenced_at')->orWhereNotNull('trust_checked_at')->orWhereNotNull('trust_note'))
                ->select(['id', 'trust_level', 'trust_level_at', 'trust_locked', 'trust_silenced_at', 'trust_checked_at', 'trust_note'])
        );

        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn(['trust_level', 'trust_level_at', 'trust_locked', 'trust_note', 'trust_silenced_at', 'trust_checked_at']);
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('trust_states');
    },
];
