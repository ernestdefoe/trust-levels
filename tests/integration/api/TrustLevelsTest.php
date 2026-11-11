<?php

namespace ErnestDefoe\TrustLevels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Evaluator;
use ErnestDefoe\TrustLevels\Limits\Limits;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class TrustLevelsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private Carbon $then;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'flarum-flags', 'flarum-mentions', 'ernestdefoe-trust-levels');
        $this->setting('ernestdefoe-trust-levels.installed_at', Carbon::now()->subDays(10)->toIso8601String());

        $this->then = Carbon::now()->subDays(30);
        $placed = Carbon::now()->subDays(20);

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                // New members, joined after the install.
                ['id' => 3, 'username' => 'nina', 'email' => 'nina@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level' => 0, 'trust_level_at' => $placed],
                ['id' => 4, 'username' => 'basil', 'email' => 'basil@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now(), 'trust_level' => 1, 'trust_level_at' => $placed],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $this->then, 'trust_level' => 1, 'trust_level_at' => $placed],
                // Three Basic flaggers.
                ['id' => 6, 'username' => 'f1', 'email' => 'f1@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_level_at' => $placed],
                ['id' => 7, 'username' => 'f2', 'email' => 'f2@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_level_at' => $placed],
                ['id' => 8, 'username' => 'f3', 'email' => 'f3@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_level_at' => $placed],
                // A New flagger, whose flag doesn't count towards hiding.
                ['id' => 9, 'username' => 'f0', 'email' => 'f0@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 0, 'trust_level_at' => $placed],
                // A Regular.
                ['id' => 10, 'username' => 'reggie', 'email' => 'reggie@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 3, 'trust_level_at' => Carbon::now()->subDays(60)],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
            'group_permission' => [
                ['permission' => 'discussion.flagPosts', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'discussion.likePosts', 'group_id' => Group::MEMBER_ID],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Ferry times', 'user_id' => 2, 'created_at' => $this->then, 'last_posted_at' => $this->then, 'comment_count' => 6, 'first_post_id' => 1, 'last_post_id' => 6, 'last_post_number' => 6],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>One</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 2, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>Two</p></t>'],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'user_id' => 3, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>Buy watches</p></t>'],
                ['id' => 4, 'discussion_id' => 1, 'number' => 4, 'user_id' => 2, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>Four</p></t>'],
                ['id' => 5, 'discussion_id' => 1, 'number' => 5, 'user_id' => 4, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>Five</p></t>'],
                ['id' => 6, 'discussion_id' => 1, 'number' => 6, 'user_id' => 4, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>Six</p></t>'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];

        if ($method !== 'GET') {
            $options['json'] = $body;
        }

        $response = $this->send($this->request($method, $path, $options));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function db()
    {
        return $this->database();
    }

    private function user(int $id): object
    {
        return $this->db()->table('users')->where('id', $id)->first();
    }

    private function read(int $as, int $number): int
    {
        return $this->api('PATCH', '/api/discussions/1', $as, ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['lastReadPostNumber' => $number]]])[0];
    }

    private function reply(int $as, string $content): array
    {
        return $this->api('POST', '/api/posts', $as, ['data' => ['type' => 'posts', 'attributes' => ['content' => $content], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]);
    }

    private function check(bool $all = false): void
    {
        $this->app();
        $this->app->getContainer()->make(Evaluator::class)->run($all);
    }

    /** Before the app boots: settings are read once per process. */
    private function setConfig(array $config): void
    {
        $this->setting(Config::KEY, json_encode($config));
    }

    #[Test]
    public function reading_is_tracked_from_the_read_request_core_already_sends(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));
        $this->assertSame(200, $this->read(3, 2));

        Carbon::setTestNow(Carbon::now()->addSeconds(45));
        $this->assertSame(200, $this->read(3, 4));

        // A break: counted as two minutes at most.
        Carbon::setTestNow(Carbon::now()->addHours(3));
        $this->assertSame(200, $this->read(3, 6));

        $row = $this->db()->table('trust_daily')->where('user_id', 3)->first();
        $this->assertSame(6, (int) $row->posts_read);
        $this->assertSame(1, (int) $row->topics_entered);
        $this->assertSame(45 + 120, (int) $row->read_seconds);
    }

    #[Test]
    public function members_are_promoted_by_what_they_do_and_told_once(): void
    {
        // Basic: 5 discussions entered, 30 posts read, 10 minutes reading.
        $this->db()->table('trust_daily')->insert([
            ['user_id' => 3, 'day' => Carbon::now()->subDays(2)->toDateString(), 'posts_read' => 20, 'topics_entered' => 3, 'read_seconds' => 400],
            ['user_id' => 3, 'day' => Carbon::now()->subDay()->toDateString(), 'posts_read' => 15, 'topics_entered' => 2, 'read_seconds' => 300],
            ['user_id' => 4, 'day' => Carbon::now()->subDay()->toDateString(), 'posts_read' => 5, 'topics_entered' => 1, 'read_seconds' => 60],
        ]);
        $this->db()->table('users')->whereIn('id', [3, 4])->update(['last_seen_at' => Carbon::now()]);

        $this->check();

        $this->assertSame(1, (int) $this->user(3)->trust_level);
        $this->assertSame(1, (int) $this->user(4)->trust_level, 'not enough for Member yet');
        $this->assertSame(1, $this->db()->table('notifications')->where('user_id', 3)->where('type', 'trustLevelReached')->count());
        $this->assertSame(0, $this->db()->table('notifications')->where('user_id', 4)->count());

        $this->check();
        $this->assertSame(1, $this->db()->table('notifications')->where('user_id', 3)->where('type', 'trustLevelReached')->count());

        $stats = json_decode($this->db()->table('trust_stats')->where('user_id', 3)->value('data'), true);
        $this->assertSame(2, $stats['next']['level']);
    }

    #[Test]
    public function member_level_needs_visits_replies_and_likes(): void
    {
        $days = [];

        for ($i = 1; $i <= 15; $i++) {
            $days[] = ['user_id' => 4, 'day' => Carbon::now()->subDays($i)->toDateString(), 'posts_read' => 10, 'topics_entered' => 2, 'read_seconds' => 300];
        }

        $this->db()->table('trust_daily')->insert($days);
        $this->db()->table('users')->where('id', 4)->update(['last_seen_at' => Carbon::now()]);
        $this->check();
        $this->assertSame(1, (int) $this->user(4)->trust_level, 'needs replies in 3 discussions and likes');

        $db = $this->db();
        $db->table('discussions')->insert([
            ['id' => 2, 'title' => 'A', 'slug' => 'a', 'user_id' => 2, 'created_at' => $this->then, 'comment_count' => 2, 'last_post_number' => 2],
            ['id' => 3, 'title' => 'B', 'slug' => 'b', 'user_id' => 2, 'created_at' => $this->then, 'comment_count' => 2, 'last_post_number' => 2],
        ]);
        $db->table('posts')->insert([
            ['id' => 7, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>A</p></t>'],
            ['id' => 8, 'discussion_id' => 2, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>A2</p></t>'],
            ['id' => 9, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>B</p></t>'],
            ['id' => 10, 'discussion_id' => 3, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'created_at' => $this->then, 'content' => '<t><p>B2</p></t>'],
        ]);
        $db->table('post_likes')->insert([
            ['post_id' => 1, 'user_id' => 4, 'created_at' => $this->then],
            ['post_id' => 2, 'user_id' => 4, 'created_at' => $this->then],
            ['post_id' => 4, 'user_id' => 4, 'created_at' => $this->then],
            ['post_id' => 5, 'user_id' => 2, 'created_at' => $this->then],
        ]);

        $this->check();
        $this->assertSame(2, (int) $this->user(4)->trust_level);
    }

    #[Test]
    public function members_from_before_the_install_are_placed_never_below_basic_and_not_told(): void
    {
        // User 2 joined before the install; nina (3) joined since and stays New.
        $this->db()->table('users')->whereIn('id', [2, 3])->update(['trust_level_at' => null, 'trust_level' => 0]);
        $this->db()->table('users')->where('id', 2)->update(['joined_at' => Carbon::now()->subYear()]);

        $this->check();

        $this->assertSame(1, (int) $this->user(2)->trust_level);
        $this->assertNotNull($this->user(2)->trust_level_at);
        $this->assertSame(0, (int) $this->user(3)->trust_level);
        $this->assertSame(0, $this->db()->table('notifications')->whereIn('user_id', [2, 3])->count());
        $this->assertSame(1, (int) $this->db()->table('trust_stats')->where('user_id', 2)->value('base_days'), 'days with posts count as visits');
    }

    #[Test]
    public function a_quiet_regular_drops_after_the_grace_period_but_a_locked_one_never_moves(): void
    {
        $this->db()->table('users')->where('id', 10)->update(['last_seen_at' => Carbon::now()]);
        $this->check(true);

        $this->assertSame(2, (int) $this->user(10)->trust_level);
        $this->assertSame(1, $this->db()->table('notifications')->where('user_id', 10)->where('type', 'trustLevelDropped')->count());

        // Locked at Leader: nothing moves it.
        $this->db()->table('users')->where('id', 10)->update(['trust_level' => 4, 'trust_locked' => true]);
        $this->check(true);
        $this->assertSame(4, (int) $this->user(10)->trust_level);

        // Within the grace period after promotion: stays Regular.
        $this->db()->table('users')->where('id', 10)->update(['trust_level' => 3, 'trust_locked' => false, 'trust_level_at' => Carbon::now()->subDays(3)]);
        $this->check(true);
        $this->assertSame(3, (int) $this->user(10)->trust_level);
    }

    #[Test]
    public function new_members_are_held_to_their_limits_and_told_why(): void
    {
        [$status, $body] = $this->reply(3, 'See http://a.example http://b.example and http://c.example');
        $this->assertSame(422, $status);
        $this->assertSame('/data/attributes/content', $body['errors'][0]['source']['pointer']);

        [$status] = $this->reply(3, 'Just http://a.example and http://b.example');
        $this->assertSame(201, $status);

        // One post a minute at New (core's own throttle is 10 seconds).
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        [$status] = $this->reply(3, 'Again so soon');
        $this->assertSame(422, $status);

        // Images and mentions are counted in the parsed post, as Markdown, BBCode and uploads write them.
        $this->assertSame(['links' => 1, 'images' => 2, 'mentions' => 1], Limits::count(
            '<r><p><IMG alt="a" src="http://a.example/a.png"><s>![a](</s>http://a.example/a.png<e>)</e></IMG> '
            .'<URL url="http://b.example"><IMG src="http://b.example/b.png"/></URL> '
            .'<URL url="http://c.example">c</URL> <USERMENTION displayname="Ana" id="3">@"Ana"#3</USERMENTION></p></r>'
        ));
        $this->assertSame(['links' => 0, 'images' => 0, 'mentions' => 0], Limits::count('<t><p>Plain text</p></t>'));

        // Moderators are never limited.
        [$status] = $this->reply(5, 'http://a.example http://b.example http://c.example http://d.example');
        $this->assertSame(201, $status);
    }

    #[Test]
    public function members_from_before_the_install_arent_held_to_new_limits_before_their_first_check(): void
    {
        $this->db()->table('users')->where('id', 2)->update(['trust_level_at' => null, 'trust_level' => 0, 'joined_at' => Carbon::now()->subYear()]);

        [$status] = $this->reply(2, 'http://a.example http://b.example http://c.example');
        $this->assertSame(201, $status);
    }

    #[Test]
    public function new_members_may_start_three_discussions_a_day(): void
    {
        $this->setConfig(['limits' => ['post_seconds' => []]]);

        for ($i = 1; $i <= 4; $i++) {
            Carbon::setTestNow(Carbon::now()->addSeconds(20));
            [$status] = $this->api('POST', '/api/discussions', 3, ['data' => ['type' => 'discussions', 'attributes' => ['title' => "Number $i", 'content' => 'Hello there']]]);
            $this->assertSame($i <= 3 ? 201 : 422, $status, "discussion $i");
        }
    }

    #[Test]
    public function enough_flags_hide_a_post_and_silence_its_new_author(): void
    {
        // A New member's flag doesn't count; two Basic ones aren't enough.
        foreach ([9, 6, 7] as $flagger) {
            $this->assertSame(201, $this->flag($flagger, 3));
        }

        $this->assertNull($this->db()->table('posts')->where('id', 3)->value('hidden_at'));

        $this->assertSame(201, $this->flag(8, 3));
        $this->assertNotNull($this->db()->table('posts')->where('id', 3)->value('hidden_at'));
        $this->assertNotNull($this->user(3)->trust_silenced_at);

        // Silenced: can't reply or like; can still read.
        [$status] = $this->reply(3, 'Let me explain');
        $this->assertSame(403, $status);
        $this->assertSame(200, $this->api('GET', '/api/discussions/1', 3)[0]);

        // A moderator lifts it.
        [$status, $body] = $this->api('POST', '/api/trust-levels/users/3', 5, ['silenced' => false]);
        $this->assertSame(200, $status);
        $this->assertFalse($body['data']['silenced']);
        $this->assertNull($this->user(3)->trust_silenced_at);
    }

    #[Test]
    public function upheld_flags_drop_a_member_one_level(): void
    {
        $this->setConfig(['spam' => ['drop_flags' => 2, 'flags' => 0]]);

        foreach ([5, 6] as $postId) {
            $this->assertSame(201, $this->flag(6, $postId));
            // A moderator hides the flagged post: its flags are upheld.
            [$status] = $this->api('PATCH', "/api/posts/$postId", 5, ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['isHidden' => true]]]);
            $this->assertSame(200, $status);
        }

        $this->assertSame(2, $this->db()->table('trust_events')->where('user_id', 4)->where('type', 'flag_upheld')->count());
        $this->assertSame(0, (int) $this->user(4)->trust_level);
    }

    private function flag(int $as, int $postId): int
    {
        return $this->api('POST', '/api/flags', $as, ['data' => ['type' => 'flags', 'attributes' => ['reason' => 'spam'], 'relationships' => ['post' => ['data' => ['type' => 'posts', 'id' => (string) $postId]]]]])[0];
    }

    #[Test]
    public function only_the_member_and_staff_see_a_level(): void
    {
        [, $other, $raw] = $this->api('GET', '/api/users/4', 3);
        $this->assertArrayNotHasKey('trustLevel', $other['data']['attributes']);
        $this->assertStringNotContainsString('trustLevel', $raw);

        [, $self] = $this->api('GET', '/api/users/4', 4);
        $this->assertSame(1, $self['data']['attributes']['trustLevel']);

        [, $staff] = $this->api('GET', '/api/users/4', 5);
        $this->assertSame(1, $staff['data']['attributes']['trustLevel']);

        $this->assertSame(404, $this->api('GET', '/api/trust-levels/users/4', 3)[0]);
        $this->assertSame(200, $this->api('GET', '/api/trust-levels/users/4', 4)[0]);
        $this->assertSame(200, $this->api('GET', '/api/trust-levels/users/4', 5)[0]);
        $this->assertSame(403, $this->api('POST', '/api/trust-levels/users/4', 4, ['level' => 4])[0]);
    }

    #[Test]
    public function staff_set_and_lock_a_level(): void
    {
        [$status, $body] = $this->api('POST', '/api/trust-levels/users/4', 5, ['level' => 4, 'locked' => true, 'note' => 'Runs the ferry thread']);
        $this->assertSame(200, $status);
        $this->assertSame(4, $body['data']['level']);
        $this->assertTrue($body['data']['locked']);
        $this->assertSame('Runs the ferry thread', $body['data']['note']);
        $this->assertSame(1, $this->db()->table('trust_events')->where('user_id', 4)->where('type', 'locked')->count());
    }

    #[Test]
    public function a_level_group_gives_its_permissions_to_that_level_and_above(): void
    {
        $this->prepareDatabase([
            Group::class => [['id' => 10, 'name_singular' => 'Trust 1', 'name_plural' => 'Trust 1', 'is_hidden' => 1]],
            'group_permission' => [['permission' => 'trust.test', 'group_id' => 10]],
        ]);
        $this->setConfig(['groups' => [1 => 10]]);
        $this->app();

        $this->assertTrue(User::query()->find(4)->hasPermission('trust.test'));
        $this->assertTrue(User::query()->find(10)->hasPermission('trust.test'));
        $this->assertFalse(User::query()->find(3)->hasPermission('trust.test'));
        $this->assertSame(0, $this->db()->table('group_user')->where('group_id', 10)->count(), 'no one is put in the group');
    }

    #[Test]
    public function a_level_group_cant_be_a_core_group(): void
    {
        [$status] = $this->api('POST', '/api/settings', 1, [Config::KEY => json_encode(['groups' => [1 => Group::MEMBER_ID]])]);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function the_admin_dashboard_counts_members_per_level(): void
    {
        [$status, $body] = $this->api('POST', '/api/trust-levels/run', 1);
        $this->assertSame(200, $status);
        $this->assertSame($this->db()->table('users')->count(), array_sum($body['data']['counts']));
        $this->assertSame(1, $body['data']['counts'][3]);
        $this->assertFalse($body['data']['stale']);
        $this->assertSame(403, $this->api('GET', '/api/trust-levels/dashboard', 5)[0]);
    }
}
