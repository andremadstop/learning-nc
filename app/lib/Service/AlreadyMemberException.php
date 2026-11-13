<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The account is already enrolled in the course with the requested role (GitHub #35). The
 * controller answers 409 so the members tab can say so instead of pretending to add it again.
 */
class AlreadyMemberException extends \RuntimeException {
}
