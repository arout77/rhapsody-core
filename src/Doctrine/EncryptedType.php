<?php
namespace Rhapsody\Core\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Rhapsody\Core\Services\Encrypter;

/**
 * Doctrine column type that encrypts a string on write and decrypts it on
 * read, so the database only ever stores ciphertext.
 *
 * Usage in an entity:
 *
 *     #[ORM\Column(type: 'encrypted', nullable: true)]
 *     private ?string $apiKey = null;
 *
 * Notes:
 *  - Stored as TEXT/CLOB (ciphertext is longer than the plaintext), which
 *    works on MySQL, SQL Server, PostgreSQL and SQLite.
 *  - Encryption uses a random nonce, so identical plaintexts produce
 *    different ciphertexts: you cannot WHERE/ORDER BY/index an encrypted
 *    column. Keep a separate hash column if you need to look rows up.
 *  - Strings only. json_encode() arrays yourself before assigning them.
 */
final class EncryptedType extends Type
{
    public const NAME = 'encrypted';

    // Shared context for all encrypted columns. Doctrine doesn't tell a type
    // which entity/column it is serving, so ciphertext is bound to this
    // label rather than to a specific column.
    private const CONTEXT = 'doctrine:encrypted';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return Encrypter::getInstance()->encrypt((string) $value, self::CONTEXT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $plain = Encrypter::getInstance()->decrypt((string) $value, self::CONTEXT);

        // Never silently hand back null for data that exists but can't be
        // read: that would look like "empty" and invite overwriting it.
        if ($plain === null) {
            throw new \RuntimeException(
                'Could not decrypt an encrypted column value. The data is corrupt, or APP_KEY changed ' .
                'without the old key being added to APP_PREVIOUS_KEYS.'
            );
        }

        return $plain;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * DBAL 3 only (removed in DBAL 4, where it is simply never called).
     * Makes schema tooling distinguish this type from a plain TEXT column.
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
