<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A member's level, lock, hold and staff note move off `users` into Trust
 * Levels' own table (one row per member who has any of them; no row = New,
 * unplaced, unlocked, not on hold). The rows are copied across, then the
 * columns go. Down puts them back the same way.
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
        if (! $schema->hasColumn('users', 'trust_level')) {
            $schema->table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('trust_level')->default(0);
                $table->dateTime('trust_level_at')->nullable();
                $table->boolean('trust_locked')->default(false);
                $table->string('trust_note', 200)->nullable();
                $table->dateTime('trust_silenced_at')->nullable();
                $table->dateTime('trust_checked_at')->nullable();
            });
        }

        $db = $schema->getConnection();

        $db->table('trust_states')->orderBy('user_id')->chunk(500, function ($rows) use ($db) {
            foreach ($rows as $row) {
                $db->table('users')->where('id', $row->user_id)->update([
                    'trust_level' => $row->level, 'trust_level_at' => $row->level_at, 'trust_locked' => $row->locked,
                    'trust_silenced_at' => $row->silenced_at, 'trust_checked_at' => $row->checked_at, 'trust_note' => $row->note,
                ]);
            }
        });

        $schema->dropIfExists('trust_states');
    },
];
