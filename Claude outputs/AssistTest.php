<?php

declare(strict_types=1);

namespace Tests\Unit\Assist;

use PHPUnit\Framework\TestCase;
use Rhapsody\Core\Ai\AiResponse;
use Rhapsody\Core\Ai\Assist\AssistException;
use Rhapsody\Core\Ai\Assist\AssistPrompt;
use Rhapsody\Core\Ai\Assist\AssistService;
use Rhapsody\Core\Ai\Assist\AssistSettings;
use Rhapsody\Core\Ai\Assist\AssistStore;
use Rhapsody\Core\Ai\Assist\FixResponseParser;
use Rhapsody\Core\Ai\Assist\PatchTool;
use Rhapsody\Core\Ai\Assist\SourceContext;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Snapshot\Redactor;
use Rhapsody\Core\Snapshot\RequestSnapshot;
use Rhapsody\Core\Snapshot\SnapshotStore;

/**
 * Toolbar "Ask AI" and error page "Explain and fix": what is sent, what is
 * masked, and the safety rules for applying a model's proposed change.
 */
final class AssistTest extends TestCase
{
    private const OLD = "        \$user = null;\n        return \$user->name;";
    private const NEW = "        \$user = null;\n        return \$user?->name ?? '';";

    private const FAILED_SNAPSHOT = '20261104-221500-123456-ab12';
    private const OK_SNAPSHOT     = '20261104-221600-000001-cd34';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rh-assist-' . bin2hex(random_bytes(4));
        foreach (['app/Controllers', 'vendor/x', 'config', 'storage/debug/snapshots'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }

        $source = "<?php\nnamespace App\\Controllers;\n\nclass Home\n{\n    private \$apiKey = 'sk_live_abcdefghijklmnop';\n    public function index(): string\n    {\n        \$user = null;\n        return \$user->name;\n    }\n}\n";
        file_put_contents($this->root . '/app/Controllers/Home.php', $source);
        file_put_contents($this->root . '/app/Controllers/Win.php', str_replace("\n", "\r\n", $source));
        file_put_contents($this->root . '/app/Dupe.php', "<?php\n\$a = 1;\n\$a = 1;\n");
        file_put_contents($this->root . '/vendor/x/Lib.php', "<?php\nfunction boom() { throw new Exception('x'); }\n");
        file_put_contents($this->root . '/config/app.php', "<?php\nreturn ['password' => 'hunter2'];\n");
        file_put_contents($this->root . '/.env', "SECRET=1\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // ---------------------------------------------------------------- source context

    public function testSourceExcerptsMaskSecretsAndSkipBlockedFiles(): void
    {
        $excerpts = SourceContext::collect($this->failure(), $this->root, new Redactor(), 15, 5);

        $this->assertCount(1, $excerpts, 'vendor, config, .env and paths outside the project are skipped');
        $this->assertSame('app/Controllers/Home.php', $excerpts[0]->file);
        $this->assertTrue($excerpts[0]->patchable);
        $this->assertStringContainsString("\$apiKey = '[REDACTED]'", $excerpts[0]->code);
        $this->assertStringNotContainsString('sk_live', $excerpts[0]->code);
        $this->assertStringContainsString('>   10| ', $excerpts[0]->numbered());
    }

    public function testVendorThrowSiteIsReadOnlyContext(): void
    {
        $snapshot = ['exception' => ['file' => 'vendor/x/Lib.php', 'line' => 2, 'trace' => ['f() at app/Controllers/Home.php:10']]];
        $excerpts = SourceContext::collect($snapshot, $this->root, new Redactor(), 3, 5);

        $this->assertCount(2, $excerpts);
        $this->assertFalse($excerpts[0]->patchable);
        $this->assertTrue($excerpts[1]->patchable);
    }

    public function testMaskLineCoversArrayPairsVariablesAndConstants(): void
    {
        $redactor = new Redactor();

        $this->assertSame("'api_key' => '[REDACTED]',", SourceContext::maskLine("'api_key' => 'abc123',", $redactor));
        $this->assertSame("'name' => 'Andrew',", SourceContext::maskLine("'name' => 'Andrew',", $redactor));
        $this->assertSame('$dbPassword = "[REDACTED]";', SourceContext::maskLine('$dbPassword = "p@ss";', $redactor));
        $this->assertSame('$title = "Hello";', SourceContext::maskLine('$title = "Hello";', $redactor));
        $this->assertSame("define('APP_SECRET', '[REDACTED]');", SourceContext::maskLine("define('APP_SECRET', 'zzz');", $redactor));
        $this->assertSame("const TOKEN = '[REDACTED]';", SourceContext::maskLine("const TOKEN = 'zzz';", $redactor));
    }

    // ---------------------------------------------------------------- patch tool

    public function testPreviewBuildsADiffWithoutTouchingTheFile(): void
    {
        $before = (string) file_get_contents($this->root . '/app/Controllers/Home.php');
        $diff   = (new PatchTool($this->root))->preview('app/Controllers/Home.php', self::OLD, self::NEW);

        $this->assertStringContainsString('--- a/app/Controllers/Home.php', $diff);
        $this->assertStringContainsString('-        return $user->name;', $diff);
        $this->assertStringContainsString("+        return \$user?->name ?? '';", $diff);
        $this->assertStringNotContainsString('-        $user = null;', $diff, 'unchanged lines are context');
        $this->assertSame($before, (string) file_get_contents($this->root . '/app/Controllers/Home.php'));
    }

    public function testApplyKeepsCrlfLineEndingsAndSavesTheOriginal(): void
    {
        (new PatchTool($this->root))->apply('app/Controllers/Win.php', self::OLD, self::NEW, $this->root . '/undo', 'abcd1234abcd1234');

        $after = (string) file_get_contents($this->root . '/app/Controllers/Win.php');
        $this->assertStringContainsString("return \$user?->name ?? '';\r\n", $after);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $after), 'no bare LF was introduced');
        $this->assertStringContainsString('$user->name', (string) file_get_contents($this->root . '/undo/abcd1234abcd1234.orig'));
    }

    public function testUnsafeChangesAreRejected(): void
    {
        $patch = new PatchTool($this->root);

        $this->assertRejected(fn () => $patch->preview('app/Controllers/Home.php', 'nope nope', 'x'), 'not found');
        $this->assertRejected(fn () => $patch->preview('app/Dupe.php', '$a = 1;', '$a = 2;'), 'appears 2 times');
        $this->assertRejected(fn () => $patch->preview('app/Controllers/Home.php', '   ', 'x'), 'empty');
        $this->assertRejected(fn () => $patch->preview('vendor/x/Lib.php', 'boom', 'bang'), 'does not edit');
        $this->assertRejected(fn () => $patch->preview('config/app.php', 'hunter2', 'x'), 'does not edit');
        $this->assertRejected(fn () => $patch->preview('.env', 'SECRET', 'X'), 'does not edit');
        $this->assertRejected(fn () => $patch->preview('../x.php', 'a', 'b'), 'outside the project');
        $this->assertRejected(fn () => $patch->preview('/etc/hostname', 'a', 'b'), 'outside the project');
        $this->assertRejected(fn () => $patch->preview('app/Controllers/Home.php', 'class Home', 'class [REDACTED]'), 'masked value');
        $this->assertRejected(fn () => $patch->preview('app/Controllers/Home.php', 'return $user->name;', 'return $user->name'), 'syntax error');
    }

    public function testAFileThatIsAlreadyBrokenCanBeFixed(): void
    {
        file_put_contents($this->root . '/app/Broken.php', "<?php\n\$x = 1\n\$y = 2;\n");

        $diff = (new PatchTool($this->root))->preview('app/Broken.php', "\$x = 1\n", "\$x = 1;\n");

        $this->assertStringContainsString('+$x = 1;', $diff);
    }

    public function testApplyIsRefusedWhenTheFileChangedAfterThePreview(): void
    {
        $patch = new PatchTool($this->root);
        file_put_contents($this->root . '/app/Stale.php', "<?php\n\$a = 1;\n");
        $patch->preview('app/Stale.php', '$a = 1;', '$a = 2;');
        file_put_contents($this->root . '/app/Stale.php', "<?php\n\$a = 9;\n");

        $this->assertRejected(fn () => $patch->apply('app/Stale.php', '$a = 1;', '$a = 2;', $this->root . '/undo', 'ffffffffffffffff'), 'not found');
        $this->assertSame("<?php\n\$a = 9;\n", (string) file_get_contents($this->root . '/app/Stale.php'));
    }

    // ---------------------------------------------------------------- parser and prompt

    public function testParserAcceptsFencedJsonAndRejectsBadShapes(): void
    {
        $parsed = FixResponseParser::parse("Sure!\n```json\n{\"explanation\":\"Because null.\",\"fixes\":[{\"file\":\"a.php\",\"description\":\"d\",\"search\":\"x\",\"replace\":\"y\"},{\"file\":1}]}\n```");

        $this->assertNotNull($parsed);
        $this->assertSame('Because null.', $parsed['explanation']);
        $this->assertCount(1, $parsed['fixes']);
        $this->assertNull(FixResponseParser::parse('no json here'));
        $this->assertNull(FixResponseParser::parse('{"fixes":[]}'));
        $this->assertNull(FixResponseParser::parse('{"explanation": "cut off'));
    }

    public function testPromptSectionsDefaultsSelectionAndSizeLimit(): void
    {
        $snapshot = [
            'framework' => ['php_version' => '8.4'],
            'request'   => ['method' => 'GET', 'url' => '/x', 'ip' => '1.2.3.4', 'headers' => ['A' => 'b'], 'query' => [], 'body' => null],
            'route'     => ['path' => '/x'],
            'queries'   => ['total' => 1, 'items' => [['sql' => 'select 1']]],
            'session'   => ['k' => '[REDACTED string]'],
            'logs'      => ['php' => 'line'],
        ];

        $request = AssistPrompt::build($snapshot, 'id', 'ask', 'Why slow?', [], null, 60000);
        $this->assertContains('queries', $request->includedKeys());
        $this->assertNotContains('session', $request->includedKeys(), 'optional sections start off');
        $this->assertStringNotContainsString('1.2.3.4', $request->prompt, 'the client IP is never sent');
        $this->assertStringNotContainsString('"headers"', $request->prompt);
        $this->assertStringContainsString('Why slow?', $request->prompt);

        $picked = AssistPrompt::build($snapshot, 'id', 'ask', 'q', [], ['session'], 60000);
        $this->assertContains('session', $picked->includedKeys());
        $this->assertNotContains('request', $picked->includedKeys());

        $big = $snapshot;
        $big['queries']['items'] = array_fill(0, 40, ['sql' => str_repeat('x', 3000)]);
        $trimmed = AssistPrompt::build($big, 'id', 'ask', 'q', [], null, 8000);
        $this->assertNotContains('queries', $trimmed->includedKeys());
        $this->assertNotSame([], $trimmed->notices);

        $this->assertSame(['request'], AssistPrompt::cleanSelection(['request', 'nope', 5]));
        $this->assertNull(AssistPrompt::cleanSelection('x'));
    }

    // ---------------------------------------------------------------- service

    public function testFixModeProposesOnlyForFilesThatWereSentAndWritesNothingUntilApplied(): void
    {
        $this->saveSnapshot(self::FAILED_SNAPSHOT, $this->failure());
        $ai      = new FakeAiClient($this->fixReply());
        $service = $this->service($ai);
        $before  = (string) file_get_contents($this->root . '/app/Controllers/Home.php');

        $result = $service->run(self::FAILED_SNAPSHOT, 'fix', '');

        $this->assertCount(1, $result->fixes);
        $this->assertSame('app/Controllers/Home.php', $result->fixes[0]['file']);
        $this->assertGreaterThanOrEqual(2, count($result->notices), 'the vendor and unsent-file proposals were discarded with a note');
        $this->assertSame($before, (string) file_get_contents($this->root . '/app/Controllers/Home.php'));
        $this->assertStringNotContainsString('sk_live', $ai->prompts[0], 'masked source never leaves');
        $this->assertStringContainsString('[REDACTED]', $ai->prompts[0]);
        $this->assertFileExists($this->root . '/storage/logs/ai-requests.jsonl');

        $applied = $service->apply($result->fixes[0]['id']);

        $this->assertStringContainsString('$user?->name', (string) file_get_contents($this->root . '/app/Controllers/Home.php'));
        $this->assertFileExists($this->root . '/' . $applied['backup']);
        $this->assertRejected(fn () => $service->apply($result->fixes[0]['id']), 'already applied');
    }

    public function testApplyCanBeSwitchedOffAndUnknownIdsAreRejected(): void
    {
        $this->saveSnapshot(self::FAILED_SNAPSHOT, $this->failure());
        $service = $this->service(new FakeAiClient($this->fixReply()), new AssistSettings(apply: false));

        $result = $service->run(self::FAILED_SNAPSHOT, 'fix', '');

        $this->assertFalse($result->canApply);
        $this->assertRejected(fn () => $service->apply($result->fixes[0]['id']), 'switched off');
        $this->assertRejected(fn () => $this->service(null)->apply('../../etc/passwd'), 'Unknown');
        $this->assertRejected(fn () => $this->service(null)->apply('0123456789abcdef'), 'no longer exists');
    }

    public function testAskModeNeverProposesFixesAndFixModeNeedsAnException(): void
    {
        $this->saveSnapshot(self::OK_SNAPSHOT, ['framework' => ['php_version' => '8.4'], 'request' => ['method' => 'GET'], 'queries' => ['total' => 3, 'items' => []], 'exception' => null]);

        $result = $this->service(new FakeAiClient("Three queries ran.\n- one"))->run(self::OK_SNAPSHOT, 'ask', 'Why slow?');

        $this->assertSame([], $result->fixes);
        $this->assertFalse($result->canApply);
        $this->assertRejected(fn () => $this->service(new FakeAiClient('x'))->run(self::OK_SNAPSHOT, 'fix', ''), 'did not fail');
        $this->assertRejected(fn () => $this->service(new FakeAiClient('x'))->run(self::OK_SNAPSHOT, 'ask', '  '), 'Type a question');
    }

    public function testBadModelOutputDegradesToText(): void
    {
        $this->saveSnapshot(self::FAILED_SNAPSHOT, $this->failure());

        $result = $this->service(new FakeAiClient('Just prose, no JSON.'))->run(self::FAILED_SNAPSHOT, 'fix', '');

        $this->assertSame([], $result->fixes);
        $this->assertStringContainsString('expected format', implode(' ', $result->notices));
        $this->assertRejected(fn () => $this->service(new FakeAiClient('', 'SAFETY'))->run(self::FAILED_SNAPSHOT, 'fix', ''), 'declined');
        $this->assertRejected(fn () => $this->service(new FakeAiClient('   '))->run(self::FAILED_SNAPSHOT, 'fix', ''), 'empty');
    }

    public function testRefusesOutsideDevelopmentAndForBadInput(): void
    {
        $this->saveSnapshot(self::FAILED_SNAPSHOT, $this->failure());
        $ai = new FakeAiClient('x');

        $this->assertRejected(fn () => $this->service($ai, null, false)->run(self::FAILED_SNAPSHOT, 'fix', ''), 'development');
        $this->assertRejected(fn () => $this->service($ai)->run(self::FAILED_SNAPSHOT, 'chat', ''), 'Unknown assist mode');
        $this->assertRejected(fn () => $this->service($ai)->prepare('../../x', 'fix', ''), 'Unknown request snapshot');
        $this->assertRejected(fn () => $this->service($ai)->prepare('20200101-000000-000000-ffff', 'fix', ''), 'replaced by newer');
        $this->assertRejected(fn () => $this->service(null)->run(self::FAILED_SNAPSHOT, 'fix', ''), 'No AI client');
        $this->assertSame([], $ai->prompts, 'nothing was sent');
    }

    public function testPreviewNeverCallsTheAiClient(): void
    {
        $this->saveSnapshot(self::FAILED_SNAPSHOT, $this->failure());
        $ai = new FakeAiClient('x');

        $preview = $this->service($ai)->prepare(self::FAILED_SNAPSHOT, 'fix', '')->toPreview('Fake');

        $this->assertSame([], $ai->prompts);
        $this->assertSame('Fake', $preview['provider']);
        $this->assertSame('app/Controllers/Home.php', $preview['excerpts'][0]['file']);
        $this->assertStringContainsString('SYSTEM PROMPT', $preview['payload']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function failure(): array
    {
        return [
            'framework' => ['php_version' => '8.4'],
            'request'   => ['method' => 'GET', 'url' => '/home'],
            'logs'      => ['php' => 'boom'],
            'exception' => [
                'class'   => 'Error',
                'message' => 'Attempt to read property "name" on null',
                'file'    => 'app/Controllers/Home.php',
                'line'    => 10,
                'trace'   => [
                    'App\\Controllers\\Home->index() at app/Controllers/Home.php:10',
                    'boom() at vendor/x/Lib.php:2',
                    'x() at config/app.php:2',
                    'y() at .env:1',
                    'z() at ../../etc/passwd:1',
                ],
            ],
        ];
    }

    private function fixReply(): string
    {
        return json_encode(['explanation' => 'The `$user` is null on line 10.', 'fixes' => [
            ['file' => 'app/Controllers/Home.php', 'description' => 'Null-safe access', 'search' => self::OLD, 'replace' => self::NEW],
            ['file' => 'vendor/x/Lib.php', 'description' => 'not allowed', 'search' => 'boom', 'replace' => 'bang'],
            ['file' => 'app/Dupe.php', 'description' => 'not sent', 'search' => '$a = 1;', 'replace' => '$a = 2;'],
        ]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveSnapshot(string $id, array $data): void
    {
        (new SnapshotStore($this->root . '/storage/debug/snapshots'))->save(new RequestSnapshot($id, '2026-11-04T22:15:00.123+00:00', $data));
    }

    private function service(?FakeAiClient $ai, ?AssistSettings $settings = null, bool $allowed = true): AssistService
    {
        return new AssistService(
            new SnapshotStore($this->root . '/storage/debug/snapshots'),
            new AssistStore($this->root . '/storage/debug/assist'),
            new PatchTool($this->root),
            new Redactor(),
            $settings ?? new AssistSettings(),
            $this->root,
            $ai === null ? null : static fn (): AiClientInterface => $ai,
            $allowed,
            $this->root . '/storage/logs/ai-requests.jsonl'
        );
    }

    private function assertRejected(callable $action, string $messageContains): void
    {
        try {
            $action();
        } catch (AssistException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());

            return;
        }

        $this->fail("Expected an AssistException containing \"{$messageContains}\".");
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}

final class FakeAiClient implements AiClientInterface
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly string $reply, private readonly string $finishReason = 'STOP')
    {
    }

    public function generateContent(string $prompt, array $options = []): AiResponse
    {
        $this->prompts[] = $prompt;

        return new AiResponse($this->reply, $this->finishReason, ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], []);
    }

    public function generateChatContent(array $messages, array $options = []): AiResponse
    {
        return $this->generateContent('', $options);
    }
}
