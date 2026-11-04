<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Ai\Docs;

use Rhapsody\Core\Support\Fs;

/**
 * Builds, writes and freshness-checks the bundled docs index.
 *
 * The index is committed to core and ships in the Composer package, so users
 * never run a build. sourceHash() fingerprints everything the index is derived
 * from (docs templates, the routes and controller that give them URLs, and the
 * chunker's own settings), which lets a test or CI fail the moment docs change
 * without a rebuild.
 */
final class DocsIndexBuilder
{
    public const VERSION = 1;

    public function __construct(
        private readonly string $coreRoot,
        private readonly DocsChunker $chunker = new DocsChunker(),
    ) {
    }

    public function indexPath(): string
    {
        return $this->coreRoot . '/resources/docs-index/chunks.json';
    }

    public function docsDirectory(): string
    {
        return $this->coreRoot . '/resources/views/themes/default/docs';
    }

    /**
     * @return list<DocChunk>
     */
    public function chunks(): array
    {
        $urls = DocsUrlMap::fromSources(
            $this->coreRoot . '/src/routes.php',
            $this->coreRoot . '/src/Controllers/DocsController.php'
        );

        return $this->chunker->chunkDirectory($this->docsDirectory(), $urls);
    }

    /**
     * Hash of every input the index is derived from. Line endings are
     * normalized so a Windows checkout and a Linux CI agree.
     */
    public function sourceHash(): string
    {
        $files   = glob($this->docsDirectory() . '/*.twig') ?: [];
        $files[] = $this->coreRoot . '/src/routes.php';
        $files[] = $this->coreRoot . '/src/Controllers/DocsController.php';
        $files[] = __DIR__ . '/DocsChunker.php';
        $files[] = __DIR__ . '/ChunkBuilder.php';
        sort($files);

        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, basename($file) . "\0" . str_replace("\r\n", "\n", Fs::read($file)) . "\0");
        }

        return hash_final($context);
    }

    /**
     * The index file contents: one chunk per line, so git diffs show which
     * sections a docs edit changed.
     */
    /**
     * @param list<DocChunk>|null $chunks Pre-built chunks, to avoid chunking twice.
     */
    public function render(?array $chunks = null): string
    {
        $lines = [];
        foreach ($chunks ?? $this->chunks() as $chunk) {
            $lines[] = json_encode($chunk->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return '{"version":' . self::VERSION
            . ',"sourceHash":"' . $this->sourceHash()
            . "\",\"chunks\":[\n" . implode(",\n", $lines) . "\n]}\n";
    }

    /**
     * Write the index and return how many chunks it holds.
     */
    public function write(): int
    {
        $chunks    = $this->chunks();
        $contents  = $this->render($chunks);
        $directory = dirname($this->indexPath());
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        Fs::write($this->indexPath(), $contents);

        return count($chunks);
    }

    /**
     * True when the committed index was built from the current sources.
     */
    public function isFresh(): bool
    {
        if (! is_file($this->indexPath())) {
            return false;
        }

        $data = json_decode(Fs::read($this->indexPath()), true);

        return is_array($data)
            && ($data['version'] ?? null) === self::VERSION
            && ($data['sourceHash'] ?? null) === $this->sourceHash();
    }
}
