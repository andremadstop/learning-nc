<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #102: a change to a certificate image library (CertImageLibrary) that was not made because
 * the library is not what the administrator saw. The message is the error code the controllers
 * answer with HTTP 409:
 * - library_changed: the list has changed since it was loaded (another administrator renamed,
 *   deleted or added an image); the change would have been made to a list nobody saw
 * - library_busy: another change to the same library held the lock longer than the wait
 */
class CertLibraryConflictException extends \RuntimeException {
    public const CHANGED = 'library_changed';
    public const BUSY = 'library_busy';
}
