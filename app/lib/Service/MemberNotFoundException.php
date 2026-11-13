<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * The account an instructor tried to enrol does not exist (GitHub #35). The controller answers 404,
 * which the members tab shows as "user not found" instead of a generic failure.
 */
class MemberNotFoundException extends NotFoundException {
}
