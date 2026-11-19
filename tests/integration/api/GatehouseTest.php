<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Tests\integration\TrustFixtures;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** Gatehouse: someone put on hold in their first days and sent back to its queue. */
class GatehouseTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use TrustFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-gatehouse', 'ernestdefoe-trust-levels');

        $this->prepareDatabase($this->trustFixtures([
            User::class => [
                ['id' => 3, 'username' => 'held', 'email' => 'held@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level_at' => Carbon::now(), 'trust_silenced_at' => Carbon::now(), 'gatehouse_status' => 'pending'],
                ['id' => 4, 'username' => 'fresh', 'email' => 'fresh@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level' => 0, 'trust_level_at' => Carbon::now()],
            ],
            'access_tokens' => [
                ['token' => 'freshtoken', 'user_id' => 4, 'last_activity_at' => Carbon::now(), 'created_at' => Carbon::now(), 'type' => 'session'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Watches', 'user_id' => 4, 'created_at' => Carbon::now(), 'comment_count' => 1, 'first_post_id' => 1, 'last_post_id' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => Carbon::now(), 'content' => '<t><p>Buy watches</p></t>'],
            ],
        ]));
    }

    #[Test]
    public function approving_them_in_gatehouse_lifts_the_hold(): void
    {
        $response = $this->send($this->request('POST', '/api/gatehouse/applicants/3/approve', ['authenticatedAs' => 1]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNull($this->trustRow(3)->trust_silenced_at);
        $this->assertSame(1, $this->database()->table('trust_events')->where('user_id', 3)->where('type', 'unsilenced')->count());
    }

    #[Test]
    public function a_new_member_put_on_hold_goes_back_to_gatehouses_queue_through_gatehouse(): void
    {
        $this->app();
        $container = $this->app->getContainer();

        $container->make(\ErnestDefoe\TrustLevels\Spam\Spam::class)->silenceFor(User::query()->findOrFail(4), Post::query()->findOrFail(1));

        // Gatehouse's own hold (its storage, its alerts), and signed out everywhere.
        $this->assertSame('pending', $this->database()->table('users')->where('id', 4)->value('gatehouse_status'));
        $this->assertSame(0, $this->database()->table('access_tokens')->where('user_id', 4)->count());
        $this->assertSame(1, $this->database()->table('trust_events')->where('user_id', 4)->where('type', 'gatehouse')->count());
        $this->assertNotNull($this->trustRow(4)->trust_silenced_at);
    }
}
