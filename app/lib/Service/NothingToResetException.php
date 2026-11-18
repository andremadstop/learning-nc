<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #84 / #87: the learner is not stuck — no revoked or expired certificate without an open
 * cycle, no used-up attempts — so there is no new certification cycle to open.
 */
class NothingToResetException extends \Exception {
    public const CODE = 'nothing_to_reset';

    public function __construct() {
        parent::__construct(self::CODE);
    }
}
