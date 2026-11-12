<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Admin\AdminApi;
use CherryLink\Api\Client;
use CherryLink\Editor\PanelApi;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\KeyPages;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Support\KeyPages
 * @covers \CherryLink\Admin\AdminApi::ajaxKeyPin
 * @covers \CherryLink\Editor\PanelApi::ajaxKeyPin
 */
final class KeyPagesTest extends TestCase
{
    /** @var array<string,mixed> */
    private $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->store[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->store[$key]);
            return true;
        });
        Functions\when('get_the_title')->alias(static function ($id) {
            return $id === 6 ? '' : "WP title $id";
        });
        Functions\when('get_edit_post_link')->alias(static function ($id) {
            return "https://site.test/wp-admin/post.php?post=$id&action=edit";
        });
        Functions\when('get_permalink')->alias(static function ($id) {
            return "https://site.test/p$id/";
        });
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('absint')->alias(static function ($v) {
            return abs((int) $v);
        });
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('is_admin')->justReturn(true);
    }

    protected function tearDown(): void
    {
        unset($_POST['post_id'], $_POST['mode']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function response(): array
    {
        return [
            'key_pages' => [[
                'post_id' => 5, 'title' => 'API hub', 'url' => 'https://site.test/hub/', 'pinned' => false, 'inbound' => 1, 'underlinked' => true,
                'members' => [
                    ['post_id' => 6, 'title' => 'API member', 'url' => 'https://site.test/m6/', 'links_to_key' => false],
                    ['post_id' => 8, 'title' => 'Eight', 'url' => 'https://site.test/m8/', 'links_to_key' => true],
                ],
            ]],
            'posts_considered' => 9,
        ];
    }

    private function client(): Client
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('key_pages')->andReturnUsing(function ($pin, $demote) {
            $this->store['seen'] = [$pin, $demote];
            return $this->response();
        });
        return $client;
    }

    public function testSetModeKeepsOneChoicePerPost(): void
    {
        $keys = new KeyPages();
        $this->assertTrue($keys->setMode(5, 'pin'));
        $this->assertTrue($keys->setMode(7, 'demote'));
        $this->assertTrue($keys->setMode(5, 'demote'));
        $this->assertSame(['pin' => [], 'demote' => [7, 5]], $keys->pins());
        $keys->setMode(7, 'clear');
        $this->assertSame(['pin' => [], 'demote' => [5]], $keys->pins());
        $this->assertFalse($keys->setMode(5, 'nonsense'));
        $this->assertFalse($keys->setMode(0, 'pin'));
    }

    public function testRefreshSendsPinsAndStoresEnrichedResult(): void
    {
        $keys = new KeyPages($this->client());
        $keys->setMode(5, 'pin');
        $keys->setMode(7, 'demote');

        $stored = $keys->refresh();

        $this->assertSame([[5], [7]], $this->store['seen']);
        $this->assertSame($stored, $this->store[KeyPages::PAGES_OPTION]);
        $this->assertSame(9, $stored['posts_considered']);
        $page = $stored['key_pages'][0];
        $this->assertSame('WP title 5', $page['title']); // current WP title wins
        $this->assertSame('https://site.test/wp-admin/post.php?post=5&action=edit', $page['edit_url']);
        $this->assertSame('https://site.test/p5/', $page['view_url']);
        $this->assertSame('API member', $page['members'][0]['title']); // empty WP title falls back to the API's
        $this->assertSame('WP title 8', $page['members'][1]['title']);
        $this->assertStringContainsString('post=6', $page['members'][0]['edit_url']);
    }

    public function testRefreshFailureKeepsPreviousResult(): void
    {
        $this->store[KeyPages::PAGES_OPTION] = ['checked_at' => 'x', 'key_pages' => [['post_id' => 1]], 'posts_considered' => 3];
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('key_pages')->andReturn(null);

        $this->assertNull((new KeyPages($client))->refresh());
        $this->assertSame(1, $this->store[KeyPages::PAGES_OPTION]['key_pages'][0]['post_id']);
    }

    public function testForPostDescribesKeyPageMemberAndOutsider(): void
    {
        $keys = new KeyPages($this->client());
        $keys->refresh();

        $key = $keys->forPost(5);
        $this->assertTrue($key['is_key']);
        $this->assertSame(1, $key['members_not_linking']);

        $member = $keys->forPost(8);
        $this->assertFalse($member['is_key']);
        $this->assertSame(5, $member['key']['post_id']);
        $this->assertSame('https://site.test/p5/', $member['key']['view_url']);
        $this->assertSame('https://site.test/hub/', $member['key']['url']);

        $outsider = $keys->forPost(99);
        $this->assertFalse($outsider['is_key']);
        $this->assertNull($outsider['key']);
        $this->assertSame([5], $keys->keyIds());
    }

    public function testClearResultKeepsPins(): void
    {
        $keys = new KeyPages($this->client());
        $keys->setMode(5, 'pin');
        $keys->refresh();
        KeyPages::clearResult();
        $this->assertArrayNotHasKey(KeyPages::PAGES_OPTION, $this->store);
        $this->assertSame([5], $keys->pins()['pin']);
        $this->assertSame([], $keys->stored()['key_pages']);
    }

    public function testRankBoostIsATieBreakerAndIsStable(): void
    {
        $scores = [10 => 0.90, 11 => 0.897, 12 => 0.88, 13 => 0.88];
        $this->assertSame([10, 11, 12, 13], KeyPages::rank($scores, []));
        // 0.897 + 0.004 passes 0.90; 0.88 + 0.004 does not.
        $this->assertSame([11, 10, 12, 13], KeyPages::rank($scores, [11]));
        $this->assertSame([10, 11, 12, 13], KeyPages::rank($scores, [12]));
        // equal scores keep API order; a key page wins the tie.
        $this->assertSame([13, 12], KeyPages::rank([12 => 0.88, 13 => 0.88], [13]));
    }

    // ── AJAX guards ──────────────────────────────────────────────────────────

    private function admin(): AdminApi
    {
        $options = new Options();
        $license = new License($options);

        return new AdminApi($options, $license, new SyncService($options, $license));
    }

    public function testAdminPinIsGuardedByTheSettingsCapability(): void
    {
        Functions\expect('current_user_can')->once()->with('cherrylink_settings')->andReturn(false);
        Functions\when('wp_send_json_error')->alias(static function ($data, $status = null) {
            throw new \RuntimeException('halt:' . (int) $status);
        });
        $_POST = ['post_id' => 5, 'mode' => 'pin'];
        try {
            $this->admin()->ajaxKeyPin();
            $this->fail('expected halt');
        } catch (\RuntimeException $e) {
            $this->assertSame('halt:403', $e->getMessage());
        }
        $this->assertArrayNotHasKey(KeyPages::PINS_OPTION, $this->store);
    }

    public function testAdminRejectsUnknownMode(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_error')->alias(static function ($data) {
            throw new \RuntimeException('error:' . $data['message']);
        });
        $_POST = ['post_id' => 5, 'mode' => 'explode'];
        try {
            $this->admin()->ajaxKeyPin();
            $this->fail('expected error');
        } catch (\RuntimeException $e) {
            $this->assertSame('error:Unknown action.', $e->getMessage());
        }
        $this->assertArrayNotHasKey(KeyPages::PINS_OPTION, $this->store);
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function panel(array $body, callable $can): array
    {
        Functions\when('current_user_can')->alias($can);
        Functions\when('wp_send_json')->alias(static function ($data, $status = null) {
            throw new KeyPagesJson($data, (int) $status);
        });
        $client = $this->client();
        $api    = new PanelApi(new Options(), static function () use ($body) {
            return \json_encode($body);
        }, static function () use ($client) {
            return $client;
        });
        try {
            $api->ajaxKeyPin();
        } catch (KeyPagesJson $e) {
            return [$e->data, $e->status];
        }
        $this->fail('no JSON sent');
    }

    public function testPanelPinRequiresEditOthersPostsAndEditPost(): void
    {
        [$res, $status] = $this->panel(['post_id' => 5, 'mode' => 'pin'], static function ($cap) {
            return $cap !== 'edit_others_posts';
        });
        $this->assertFalse($res['ok']);
        $this->assertSame(403, $status);
        [$res] = $this->panel(['post_id' => 5, 'mode' => 'pin'], static function ($cap, $id = 0) {
            return $cap !== 'edit_post';
        });
        $this->assertSame('forbidden', $res['error']);
        $this->assertArrayNotHasKey(KeyPages::PINS_OPTION, $this->store);
    }

    public function testPanelPinStoresChoiceRefreshesAndReturnsPostState(): void
    {
        [$res] = $this->panel(['post_id' => 5, 'mode' => 'pin'], static function () {
            return true;
        });
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['keyPage']['is_key']);
        $this->assertTrue($res['keyPage']['pinned']);
        $this->assertSame([[5], []], $this->store['seen']);

        [$res, $status] = $this->panel(['post_id' => 5, 'mode' => 'bogus'], static function () {
            return true;
        });
        $this->assertSame(400, $status);
    }
}

final class KeyPagesJson extends \RuntimeException
{
    /** @var array<string,mixed> */
    public $data;
    /** @var int */
    public $status;

    /** @param array<string,mixed> $data */
    public function __construct(array $data, int $status)
    {
        parent::__construct('json');
        $this->data   = $data;
        $this->status = $status;
    }
}
