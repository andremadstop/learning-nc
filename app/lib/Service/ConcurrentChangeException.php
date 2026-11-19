<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The row a decision was based on changed before the write: another request got there first.
 * The caller answers 409 'concurrent_change'; nothing was written.
 */
class ConcurrentChangeException extends \Exception {
    public const CODE = 'concurrent_change';

    public function __construct() {
        parent::__construct(self::CODE);
    }
}
