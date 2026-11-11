<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a level, or a silence, takes away: uploads, private messages, posting
 * without approval, likes. Never more than the permission grid allows, and
 * never for staff.
 */
class PoliciesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags', 'flarum-approval', 'flarum-likes', 'flarum-messages', 'ernestdefoe-trust-levels');

        $at = Carbon::now()->subDays(3);

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'nina', 'email' => 'nina@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 0, 'trust_level_at' => $at],
                ['id' => 4, 'username' => 'basil', 'email' => 'basil@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_level_at' => $at, 'comment_count' => 5],
                ['id' => 5, 'username' => 'mia', 'email' => 'mia@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 2, 'trust_level_at' => $at, 'comment_count' => 9],
                ['id' => 6, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 0, 'trust_level_at' => $at],
                ['id' => 7, 'username' => 'quiet', 'email' => 'quiet@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 2, 'trust_level_at' => $at, 'trust_silenced_at' => $at, 'comment_count' => 9],
            ],
            'group_user' => [['user_id' => 6, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'fof-upload.upload', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'discussion.replyWithoutApproval', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'startDiscussion', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'discussion.likePosts', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'dialog.sendMessage', 'group_id' => Group::MEMBER_ID],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Ferry times', 'slug' => 'ferry', 'user_id' => 2, 'created_at' => $at, 'last_posted_at' => $at, 'comment_count' => 1, 'first_post_id' => 1, 'last_post_id' => 1, 'last_post_number' => 1, 'is_approved' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $at, 'content' => '<t><p>One</p></t>', 'is_approved' => 1],
            ],
        ]);
    }

    private function can(int $userId, string $ability, $model = null): bool
    {
        $this->app();

        return User::query()->find($userId)->can($ability, $model);
    }

    private function send_(string $method, string $path, int $as, array $body): int
    {
        return $this->send($this->request($method, $path, ['authenticatedAs' => $as, 'json' => $body]))->getStatusCode();
    }

    #[Test]
    public function uploads_open_at_member_by_default(): void
    {
        $this->assertFalse($this->can(3, 'fof-upload.upload'));
        $this->assertFalse($this->can(4, 'fof-upload.upload'));
        $this->assertTrue($this->can(5, 'fof-upload.upload'));
        $this->assertTrue($this->can(6, 'fof-upload.upload'), 'moderators are never limited');
        $this->assertFalse($this->can(7, 'fof-upload.upload'), 'silenced');
    }

    #[Test]
    public function a_new_members_first_posts_wait_for_approval(): void
    {
        $this->send_('POST', '/api/posts', 3, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Hello'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]);
        $this->assertSame(0, (int) $this->database()->table('posts')->where('user_id', 3)->value('is_approved'));

        $this->send_('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Hello'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]);
        $this->assertSame(1, (int) $this->database()->table('posts')->where('user_id', 4)->value('is_approved'));
    }

    #[Test]
    public function new_members_cant_send_private_messages_and_basic_ones_five_a_day(): void
    {
        $this->assertFalse($this->can(3, 'sendAnyMessage'));
        $this->assertTrue($this->can(4, 'sendAnyMessage'));

        $message = fn (int $i) => ['data' => ['type' => 'dialog-messages', 'attributes' => ['content' => "Hi $i", 'users' => [['id' => 5]]]]];

        for ($i = 1; $i <= 6; $i++) {
            Carbon::setTestNow(Carbon::now()->addMinutes(2));
            $status = $this->send_('POST', '/api/dialog-messages', 4, $message($i));
            $this->assertSame($i <= 5 ? 201 : 422, $status, "message $i");
        }

        Carbon::setTestNow();
    }

    #[Test]
    public function a_silenced_member_can_read_but_not_post_or_like(): void
    {
        $discussion = null;
        $this->app();
        $discussion = Discussion::query()->find(1);
        $post = Post::query()->find(1);

        $this->assertFalse($this->can(7, 'reply', $discussion));
        $this->assertFalse($this->can(7, 'like', $post));
        $this->assertFalse($this->can(7, 'startDiscussion'));
        $this->assertTrue($this->can(5, 'reply', $discussion));
        $this->assertTrue($this->can(5, 'like', $post));
        $this->assertSame(200, $this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 7]))->getStatusCode());
    }
}
