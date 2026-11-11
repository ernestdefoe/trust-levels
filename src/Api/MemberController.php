<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\LevelWriter;
use ErnestDefoe\TrustLevels\Spam\Spam;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/trust-levels/users/{id}: the progress tab. The member themselves
 *      or staff with the manage permission; anyone else gets a 404, so it
 *      can't be used to learn someone's level.
 * POST /api/trust-levels/users/{id} {level?, locked?, note?, silenced?}: staff only.
 */
class MemberController implements RequestHandlerInterface
{
    public function __construct(
        private Progress $progress,
        private LevelWriter $writer,
        private Spam $spam,
        private ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $user = User::query()->find((int) ($request->getAttribute('routeParameters')['id'] ?? 0));
        $staff = $actor->hasPermission('ernestdefoe-trust-levels.manage');

        if (! $user || (! $staff && $actor->id !== $user->id)) {
            return Json::error(404, 'not_found');
        }

        if ($request->getMethod() === 'POST') {
            if (! $staff) {
                return Json::error(403, 'permission_denied');
            }

            $this->update($user, $actor, Json::body($request));
            $user->refresh();
        }

        return new JsonResponse(['data' => $this->progress->for($user, $staff)]);
    }

    private function update(User $user, User $actor, array $body): void
    {
        if (array_key_exists('note', $body)) {
            $note = trim((string) $body['note']);
            $this->db->table('users')->where('id', $user->id)->update(['trust_note' => $note === '' ? null : mb_substr($note, 0, 200)]);
        }

        if (array_key_exists('level', $body) && is_numeric($body['level'])) {
            $level = max(0, min(Config::MAX, (int) $body['level']));
            $this->writer->set($user, $level, 'staff', $actor);
        }

        if (array_key_exists('locked', $body) && (bool) $body['locked'] !== (bool) $user->getAttribute('trust_locked')) {
            $locked = (bool) $body['locked'];
            $this->db->table('users')->where('id', $user->id)->update(['trust_locked' => $locked]);
            $this->writer->log($user->id, $locked ? 'locked' : 'unlocked', null, (int) $user->getAttribute('trust_level'), $actor->id);
        }

        if (($body['silenced'] ?? null) === false && $user->getAttribute('trust_silenced_at')) {
            $this->spam->unsilence($user, $actor);
        }
    }
}
