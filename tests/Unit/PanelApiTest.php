<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Editor\PanelApi;
use CherryLink\Support\Options;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Editor\PanelApi
 * @covers \CherryLink\Editor\DonorFinder
 * @covers \CherryLink\Editor\LinkMarkup
 */
final class PanelApiTest extends TestCase
{
    /** @var array<string,array{content:string,status?:string}> */
    private $posts = [];
    /** @var array<string,mixed> */
    private $transients = [];
    /** @var array<int,string> */
    private $updates = [];
    /** @var bool|int */
    private $lock = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        $this->updates    = [];
        $this->lock       = false;
        $this->posts      = [
            10 => ['content' => '<p>The target topic is discussed in this paragraph.</p>'],
            20 => ['content' => '<p>A donor article that talks about the target topic.</p>'],
        ];

        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_generate_password')->justReturn('tok1234567890abcdef1234');
        Functions\when('get_option')->justReturn([
            'link_before'    => \base64_encode(\urlencode('<a href="{url}" title="{title}">')),
            'link_after'     => \base64_encode(\urlencode('</a>')),
            'relative_links' => 'no_domain',
        ]);
        Functions\when('get_permalink')->alias(static function ($id) {
            return "https://site.test/post-$id/";
        });
        Functions\when('get_the_title')->alias(static function ($id) {
            return "Title $id";
        });
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_strip_all_tags')->alias('strip_tags');
        Functions\when('get_post')->alias(function ($id) {
            if (! isset($this->posts[$id])) {
                return null;
            }
            $p               = new \WP_Post();
            $p->ID           = (int) $id;
            $p->post_title   = "Title $id";
            $p->post_content = $this->posts[$id]['content'];
            $p->post_status  = $this->posts[$id]['status'] ?? 'publish';
            return $p;
        });
        Functions\when('wp_check_post_lock')->alias(function () {
            return $this->lock;
        });
        Functions\when('wp_update_post')->alias(function ($args) {
            $this->posts[$args['ID']]['content'] = $args['post_content'];
            $this->updates[]                     = $args['post_content'];
            return $args['ID'];
        });
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(function ($k) {
            return $this->transients[$k] ?? false;
        });
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });
        Functions\when('wp_send_json')->alias(static function ($data, $status = null) {
            throw new JsonSent($data, (int) $status);
        });
        Functions\when('wp_send_json_error')->alias(static function ($data, $status = null) {
            throw new JsonSent(['ok' => false], (int) $status);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string,mixed> $body */
    private function api(array $body, ?callable $client = null): PanelApi
    {
        return new PanelApi(new Options(), static function () use ($body) {
            return \json_encode($body);
        }, $client);
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function call(PanelApi $api, string $method): array
    {
        try {
            $api->$method();
        } catch (JsonSent $e) {
            return [$e->data, $e->status];
        }
        $this->fail('no JSON response sent');
    }

    public function testDonorInsertWritesLinkAndStoresUndo(): void
    {
        [$res] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic', 'block_index' => 0]), 'ajaxDonorInsert');
        $this->assertTrue($res['ok']);
        $this->assertSame('tok1234567890abcdef1234', $res['undo_token']);
        $this->assertSame(
            '<p>A donor article that talks about the <a href="/post-10/" title="Title 10">target topic</a>.</p>',
            $this->posts[20]['content']
        );
        $saved = $this->transients['cherrylink_undo_20_tok1234567890abcdef1234'];
        $this->assertSame('<p>A donor article that talks about the target topic.</p>', $saved['previous_content']);
        $this->assertSame(\md5($this->posts[20]['content']), $saved['new_hash']);
    }

    public function testDonorInsertUsesPlainAnchorForBlockContent(): void
    {
        $this->posts[20]['content'] = "<!-- wp:paragraph -->\n<p>A donor article that talks about the target topic.</p>\n<!-- /wp:paragraph -->";
        [$res] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic', 'block_index' => 0]), 'ajaxDonorInsert');
        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('<a href="/post-10/" title="Title 10">target topic</a>', $this->posts[20]['content']);
    }

    public function testDonorInsertPhraseNotFound(): void
    {
        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'unicorn', 'block_index' => 0]), 'ajaxDonorInsert');
        $this->assertFalse($res['ok']);
        $this->assertSame('phrase_not_found', $res['error']);
        $this->assertSame(422, $status);
        $this->assertSame([], $this->updates);
    }

    public function testDonorInsertRefusesWhenLocked(): void
    {
        $this->lock = 5;
        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic']), 'ajaxDonorInsert');
        $this->assertSame('locked', $res['error']);
        $this->assertSame(409, $status);
        $this->assertSame([], $this->updates);
    }

    public function testDonorInsertRefusesWithoutCapability(): void
    {
        Functions\when('current_user_can')->alias(static function ($cap, $id = 0) {
            return $id !== 20;
        });
        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic']), 'ajaxDonorInsert');
        $this->assertSame('forbidden', $res['error']);
        $this->assertSame(403, $status);
    }

    public function testDonorInsertRefusesUnpublished(): void
    {
        $this->posts[20]['status'] = 'draft';
        [$res] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic']), 'ajaxDonorInsert');
        $this->assertSame('not_published', $res['error']);
    }

    public function testDonorInsertRefusesAlreadyLinked(): void
    {
        $this->posts[20]['content'] = '<p>Already <a href="/post-10/">linked</a> to the target topic here.</p>';
        [$res] = $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic']), 'ajaxDonorInsert');
        $this->assertSame('already_linked', $res['error']);
    }

    public function testDonorInsertBadRequest(): void
    {
        [$res, $status] = $this->call($this->api(['donor_id' => 99, 'target_id' => 10, 'phrase' => 'x']), 'ajaxDonorInsert');
        $this->assertSame('bad_request', $res['error']);
        $this->assertSame(400, $status);
    }

    public function testUndoRestoresWhenUnchanged(): void
    {
        $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic', 'block_index' => 0]), 'ajaxDonorInsert');
        [$res] = $this->call($this->api(['donor_id' => 20, 'undo_token' => 'tok1234567890abcdef1234']), 'ajaxDonorUndo');
        $this->assertTrue($res['ok']);
        $this->assertSame('<p>A donor article that talks about the target topic.</p>', $this->posts[20]['content']);
        $this->assertSame([], $this->transients);
    }

    public function testUndoRefusesWhenContentChanged(): void
    {
        $this->call($this->api(['donor_id' => 20, 'target_id' => 10, 'phrase' => 'target topic', 'block_index' => 0]), 'ajaxDonorInsert');
        $this->posts[20]['content'] .= '<p>edited meanwhile</p>';
        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'undo_token' => 'tok1234567890abcdef1234']), 'ajaxDonorUndo');
        $this->assertSame('changed', $res['error']);
        $this->assertSame(409, $status);
    }

    public function testUndoExpiredAndInvalidToken(): void
    {
        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'undo_token' => 'nosuchtoken123']), 'ajaxDonorUndo');
        $this->assertSame('expired', $res['error']);
        $this->assertSame(410, $status);

        [$res, $status] = $this->call($this->api(['donor_id' => 20, 'undo_token' => '../bad']), 'ajaxDonorUndo');
        $this->assertSame('bad_request', $res['error']);
        $this->assertSame(400, $status);
    }

    public function testIncomingResolvesSourcesAndTitles(): void
    {
        Functions\when('url_to_postid')->alias(static function ($url) {
            return $url === 'https://site.test/post-30/' ? 30 : 0;
        });
        Functions\when('get_edit_post_link')->alias(static function ($id) {
            return "https://site.test/wp-admin/post.php?post=$id&action=edit";
        });
        $client = static function () {
            return new class extends \CherryLink\Api\Client {
                public function __construct()
                {
                }

                public function get_incoming_links(string $target_url): ?array
                {
                    return ['count' => 2, 'links' => [
                        ['source_id' => 20, 'source_url' => 'https://site.test/post-20/', 'anchor_text' => 'one'],
                        ['source_id' => 0, 'source_url' => 'https://site.test/post-30/', 'anchor_text' => 'two'],
                    ]];
                }
            };
        };
        [$res] = $this->call($this->api(['object_id' => 10, 'type' => 'post'], $client), 'ajaxIncoming');
        $this->assertTrue($res['ok']);
        $this->assertSame(20, $res['items'][0]['source_id']);
        $this->assertSame('Title 20', $res['items'][0]['source_title']);
        $this->assertSame('one', $res['items'][0]['anchor']);
        $this->assertSame(30, $res['items'][1]['source_id']);
        $this->assertSame('https://site.test/wp-admin/post.php?post=30&action=edit', $res['items'][1]['edit_url']);
    }

    public function testIncomingForbidden(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        [$res, $status] = $this->call($this->api(['object_id' => 10, 'type' => 'post']), 'ajaxIncoming');
        $this->assertFalse($res['ok']);
        $this->assertSame(403, $status);
    }

    public function testDonorsListFlagsLockedAndExcludesLinked(): void
    {
        $this->posts[21] = ['content' => '<p>Already <a href="/post-10/">links</a> to the target here.</p>'];
        $this->posts[22] = ['content' => '<p>Locked donor with the target topic inside.</p>'];
        $this->posts[23] = ['content' => '<p>Normal donor with the target topic inside.</p>'];
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('admin_url')->alias(static function ($p = '') {
            return 'https://site.test/wp-admin/' . $p;
        });
        Functions\when('get_post_meta')->justReturn('3');
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('wp_check_post_lock')->alias(static function ($id) {
            return $id === 22 ? 7 : false;
        });
        Functions\when('get_userdata')->alias(static function () {
            return (object) ['display_name' => 'Ann'];
        });
        $client = static function () {
            return new class extends \CherryLink\Api\Client {
                public $calls = 0;

                public function __construct()
                {
                }

                public function search(string $query, string $source_html = '', int $limit = 20, string $lang = '', int $suggestion_hits = -1): ?array
                {
                    return [['post_id' => 10], ['post_id' => 21], ['post_id' => 22], ['post_id' => 23]];
                }

                public function suggest_anchors(string $source_html, string $target_title, int $limit = 10, string $lang = '', int $target_post_id = 0): array
                {
                    return [['phrase' => 'target topic', 'context' => '…[target topic]…', 'block_index' => 0]];
                }
            };
        };
        [$res] = $this->call($this->api(['post_id' => 10], $client), 'ajaxDonors');
        $this->assertTrue($res['ok']);
        $ids = \array_column($res['items'], 'donor_id');
        $this->assertSame([22, 23], $ids);
        $this->assertSame('Ann', $res['items'][0]['locked_by']);
        $this->assertNull($res['items'][0]['suggestion']);
        $this->assertTrue($res['items'][0]['can_edit']);
        $this->assertArrayNotHasKey('locked_by', $res['items'][1]);
        $this->assertSame('target topic', $res['items'][1]['suggestion']['phrase']);
        $this->assertSame(3, $res['items'][1]['in']);
    }

    public function testTermsDecoratesTreeWithMarkup(): void
    {
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_option')->justReturn([
            'term_before' => \base64_encode(\urlencode('<a href="{url}" title="{title}">')),
            'term_after'  => \base64_encode(\urlencode('</a>')),
            'multilink'   => 'checked',
        ]);
        $api = new PanelApi(new Options());
        // Tree decoration is private; exercise through reflection with a known tree.
        $m = new \ReflectionMethod(PanelApi::class, 'decorateTerms');
        $m->setAccessible(true);
        $out = $m->invoke($api, [
            ['is_divider' => 'yes', 'name' => 'Categories'],
            ['is_divider' => 'no', 'name' => 'News &amp; Co', 'url' => 'https://site.test/news/', 'taxonomy' => 'category', 'post_count' => 4, 'children' => [
                ['is_divider' => 'no', 'name' => 'Sub', 'url' => 'https://site.test/news/sub/', 'taxonomy' => 'category', 'post_count' => 1, 'children' => []],
            ]],
        ], ['term_before' => \base64_encode(\urlencode('<a href="{url}" title="{title}">')), 'term_after' => \base64_encode(\urlencode('</a>'))]);
        $this->assertTrue($out[0]['is_divider']);
        $this->assertSame('Categories', $out[0]['title']);
        $this->assertSame('News & Co', $out[1]['title']);
        $this->assertSame('<a href="https://site.test/news/" title="News &amp; Co">', $out[1]['open']);
        $this->assertSame('</a>', $out[1]['close']);
        $this->assertSame('Sub', $out[1]['children'][0]['title']);
    }
}

/** Test double for wp_send_json (which exits in WordPress). */
final class JsonSent extends \RuntimeException
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
