<?php

declare(strict_types=1);

namespace Rhapsody\Core\Ai\Assist;

use Closure;
use JsonException;
use Rhapsody\Core\Ai\Exceptions\AiAuthenticationException;
use Rhapsody\Core\Ai\Exceptions\AiException;
use Rhapsody\Core\Ai\Exceptions\AiRateLimitException;
use Rhapsody\Core\Ai\Exceptions\AiTimeoutException;
use Rhapsody\Core\Contracts\AiClientInterface;
use Rhapsody\Core\Snapshot\Redactor;
use Rhapsody\Core\Snapshot\SnapshotStore;
use Rhapsody\Core\Support\Fs;
use Rhapsody\Core\Support\Json;
use Throwable;

/**
 * Toolbar "Ask AI" and error page "Explain and fix".
 *
 * The only thing this class ever sends to a provider is the text of an
 * {@see AssistRequest}, which {@see prepare()} builds from an already
 * redacted request snapshot and masked source excerpts. The browser never
 * supplies request data, only a snapshot id, a question and a list of
 * sections to keep, so a forged request cannot make it send anything else.
 * Every outbound call is appended to a local audit log.
 *
 * Fixes are never applied by {@see run()}: they are validated, stored as
 * proposals with a diff, and only written to disk by an explicit
 * {@see apply()} call for one proposal id.
 *
 * @phpstan-import-type FixView from AssistResult
 */
final class AssistService
{
    /**
     * @param Closure():AiClientInterface|null $aiProvider    Builds the client on demand
     * @param string|null                      $auditLogPath  JSON-lines file listing every outbound call
     */
    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly AssistStore $store,
        private readonly PatchTool $patcher,
        private readonly Redactor $redactor,
        private readonly AssistSettings $settings,
        private readonly string $root,
        private readonly ?Closure $aiProvider,
        private readonly bool $allowed,
        private readonly ?string $auditLogPath = null,
    ) {
    }

    /**
     * Build, but do not send, the request for a snapshot.
     *
     * @param  list<string>|null $sections Section keys to include; null means the defaults
     * @throws AssistException
     */
    public function prepare(string $snapshotId, string $mode, string $question, ?array $sections = null): AssistRequest
    {
        $this->assertAllowed();

        if ($mode !== AssistPrompt::MODE_ASK && $mode !== AssistPrompt::MODE_FIX) {
            throw new AssistException('Unknown assist mode.', 400);
        }

        $question = trim($question);
        if ($mode === AssistPrompt::MODE_ASK && $question === '') {
            throw new AssistException('Type a question first.', 400);
        }

        if (mb_strlen($question) > AssistPrompt::MAX_QUESTION_CHARS) {
            throw new AssistException('Please keep the question under ' . AssistPrompt::MAX_QUESTION_CHARS . ' characters.', 400);
        }

        $snapshot = $this->loadSnapshot($snapshotId);
        $excerpts = [];

        if ($mode === AssistPrompt::MODE_FIX) {
            if (! is_array($snapshot['exception'] ?? null)) {
                throw new AssistException('That request did not fail, so there is nothing to explain and fix.', 400);
            }

            $excerpts = SourceContext::collect($snapshot, $this->root, $this->redactor, $this->settings->contextLines, $this->settings->maxSourceFiles);
        }

        try {
            return AssistPrompt::build($snapshot, $snapshotId, $mode, $question, $excerpts, $sections, $this->settings->maxPayloadBytes);
        } catch (JsonException $e) {
            throw new AssistException('The request snapshot could not be encoded.', 500, $e);
        }
    }

    /**
     * Name of the provider a request would go to, for the review screen.
     */
    public function providerLabel(): string
    {
        if ($this->aiProvider === null) {
            return 'the AI provider';
        }

        try {
            $class = (new \ReflectionClass(($this->aiProvider)()))->getShortName();
        } catch (Throwable) {
            return 'the AI provider';
        }

        $label = preg_replace('/Client$/', '', $class);

        return $label === null || $label === '' ? 'the AI provider' : $label;
    }

    /**
     * @param  list<string>|null $sections
     * @throws AssistException
     */
    public function run(string $snapshotId, string $mode, string $question, ?array $sections = null): AssistResult
    {
        $request = $this->prepare($snapshotId, $mode, $question, $sections);

        if ($this->aiProvider === null) {
            throw new AssistException('No AI client is available.', 503);
        }

        try {
            $client = ($this->aiProvider)();
        } catch (Throwable $e) {
            throw new AssistException('Could not create the AI client: ' . $this->redactor->scrubString($e->getMessage()), 503, $e);
        }

        $provider = $this->providerLabel();
        $this->audit($request, $provider);

        try {
            $response = $client->generateContent($request->prompt, [
                'system'      => $request->system,
                'temperature' => 0.2,
                'max_tokens'  => $mode === AssistPrompt::MODE_FIX ? 3072 : 2048,
            ]);
        } catch (AiAuthenticationException $e) {
            throw new AssistException($this->redactor->scrubString($e->getMessage()), 503, $e);
        } catch (AiRateLimitException $e) {
            $retry = $e->retryAfter !== null ? " Retry in {$e->retryAfter}s." : '';
            throw new AssistException('The AI provider is rate limiting requests.' . $retry, 429, $e);
        } catch (AiTimeoutException $e) {
            throw new AssistException('The AI provider took too long to answer. Try again.', 504, $e);
        } catch (AiException $e) {
            throw new AssistException('The AI provider failed: ' . $this->redactor->scrubString($e->getMessage()), 502, $e);
        }

        if ($response->wasBlocked()) {
            throw new AssistException('The model declined to answer.', 422);
        }

        $text = trim($response->getText());
        if ($text === '') {
            throw new AssistException('The model returned an empty answer.', 502);
        }

        $notices = $request->notices;
        if ($response->wasTruncated()) {
            $notices[] = 'The answer was cut off at the length limit.';
        }

        if ($mode === AssistPrompt::MODE_ASK) {
            return new AssistResult($mode, $snapshotId, $text, [], $notices, $request->bytes(), $provider, false);
        }

        $parsed = FixResponseParser::parse($text);
        if ($parsed === null) {
            $notices[] = 'The model did not answer in the expected format, so no automatic fix is offered.';

            return new AssistResult($mode, $snapshotId, $text, [], $notices, $request->bytes(), $provider, false);
        }

        [$fixes, $fixNotices] = $this->proposals($snapshotId, $parsed['fixes'], $request->excerpts);

        return new AssistResult(
            $mode,
            $snapshotId,
            $parsed['explanation'],
            $fixes,
            [...$notices, ...$fixNotices],
            $request->bytes(),
            $provider,
            $this->settings->apply
        );
    }

    /**
     * Write one stored proposal to disk. Returns the file and where its original was saved.
     *
     * @return array{file: string, backup: string}
     * @throws AssistException
     */
    public function apply(string $proposalId): array
    {
        $this->assertAllowed();

        if (! $this->settings->apply) {
            throw new AssistException('Applying changes is switched off (debug.assist.apply).', 403);
        }

        $record = $this->store->load($proposalId);
        if (($record['applied'] ?? false) === true) {
            throw new AssistException('That change was already applied.', 409);
        }

        $file    = $record['file'] ?? null;
        $search  = $record['search'] ?? null;
        $replace = $record['replace'] ?? null;
        if (! is_string($file) || ! is_string($search) || ! is_string($replace)) {
            throw new AssistException('That proposed change is damaged. Ask again.', 422);
        }

        $backup = $this->patcher->apply($file, $search, $replace, $this->store->undoDirectory(), $proposalId);
        $this->store->markApplied($proposalId, $record);

        return ['file' => $file, 'backup' => $this->relativeToRoot($backup)];
    }

    /**
     * @param  list<array{file: string, description: string, search: string, replace: string}> $fixes
     * @param  list<SourceExcerpt> $excerpts
     * @return array{0: list<FixView>, 1: list<string>}
     */
    private function proposals(string $snapshotId, array $fixes, array $excerpts): array
    {
        $editable = [];
        foreach ($excerpts as $excerpt) {
            if ($excerpt->patchable) {
                $editable[$excerpt->file] = true;
            }
        }

        $views   = [];
        $notices = [];

        foreach ($fixes as $fix) {
            $file = preg_replace('#^(\./)+#', '', str_replace('\\', '/', trim($fix['file']))) ?? '';

            if (! isset($editable[$file])) {
                $notices[] = "A proposed change to {$fix['file']} was discarded: that file was not part of what was sent.";
                continue;
            }

            try {
                $diff = $this->patcher->preview($file, $fix['search'], $fix['replace']);
                $id   = AssistStore::newId();
                $this->store->save($id, [
                    'id'          => $id,
                    'snapshot_id' => $snapshotId,
                    'file'        => $file,
                    'description' => $fix['description'],
                    'search'      => $fix['search'],
                    'replace'     => $fix['replace'],
                    'created_at'  => gmdate('c'),
                    'applied'     => false,
                ]);
            } catch (AssistException $e) {
                $notices[] = $e->getMessage();
                continue;
            }

            $views[] = ['id' => $id, 'file' => $file, 'description' => $fix['description'], 'diff' => $diff, 'applied' => false];
        }

        return [$views, $notices];
    }

    /**
     * @return array<array-key, mixed>
     * @throws AssistException
     */
    private function loadSnapshot(string $id): array
    {
        if (preg_match('/^\d{8}-\d{6}-\d{6}-[a-f0-9]{4}$/', $id) !== 1) {
            throw new AssistException('Unknown request snapshot.', 404);
        }

        foreach ($this->snapshots->paths() as $path) {
            if (basename($path, '.json') !== $id) {
                continue;
            }

            try {
                return $this->snapshots->read($path);
            } catch (Throwable $e) {
                throw new AssistException('The request snapshot could not be read.', 500, $e);
            }
        }

        throw new AssistException('That request snapshot has been replaced by newer ones. Reload the page and try again.', 404);
    }

    private function assertAllowed(): void
    {
        if (! $this->allowed || ! $this->settings->enabled) {
            throw new AssistException('AI assistance is only available when app_env is "development".', 404);
        }
    }

    private function audit(AssistRequest $request, string $provider): void
    {
        if ($this->auditLogPath === null) {
            return;
        }

        try {
            $directory = dirname($this->auditLogPath);
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            Fs::write($this->auditLogPath, Json::encode([
                'at'          => gmdate('c'),
                'mode'        => $request->mode,
                'snapshot_id' => $request->snapshotId,
                'provider'    => $provider,
                'bytes'       => $request->bytes(),
                'sections'    => $request->includedKeys(),
                'source'      => array_map(static fn (SourceExcerpt $e): string => $e->file . ':' . $e->range(), $request->excerpts),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // The audit log is a courtesy to the developer; it must never block the feature.
        }
    }

    private function relativeToRoot(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
