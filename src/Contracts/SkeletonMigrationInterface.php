<?php

namespace Rhapsody\Core\Contracts;

/**
 * Contract for a single skeleton migration — a discrete change to the
 * rhapsody-app skeleton (e.g. a new middleware config entry, a new
 * routes file stub) that `composer update` alone can't deliver, since
 * that only pulls changes into rhapsody-core, not into the consuming
 * app's own files.
 *
 * Migration IDs are independent of framework version numbers — use a
 * monotonic timestamp (e.g. 2026011500 or 2026_01_15_000000) so
 * ordering is stable regardless of how core itself is versioned.
 * introducedIn() is for display only; it is NOT the migration's identity.
 */
interface SkeletonMigrationInterface
{
    /**
     * Unique, sortable identifier for this migration. Timestamp-first
     * so `SkeletonSyncCommand` can order and track applied migrations
     * without depending on filesystem order.
     */
    public function id(): string;

    /**
     * The framework version this migration was introduced in, for
     * display purposes only.
     */
    public function introducedIn(): string;

    /**
     * Short human-readable description shown while `skeleton:sync` runs.
     */
    public function description(): string;

    /**
     * Apply this migration against the given rhapsody-app root path.
     * Should be idempotent-safe to call once (SkeletonSyncCommand
     * ensures each id is only applied once, tracked in .skeleton_state).
     *
     * @throws \Throwable on failure, so the sync command can halt and
     *                     avoid marking this migration as applied.
     */
    public function apply(string $basePath): void;
}
