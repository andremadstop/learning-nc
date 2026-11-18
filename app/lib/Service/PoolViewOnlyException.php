<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #97: the pool is shared with the user "View only". Only its owner and edit shares may add
 * it to a course; the controller answers 403 with code pool_view_only so the dialog can say so.
 * Thrown only when the user really holds a read share: for anyone else the pool stays "not found",
 * so its existence is not disclosed (FIX-HI-2).
 */
class PoolViewOnlyException extends ForbiddenException {
}
