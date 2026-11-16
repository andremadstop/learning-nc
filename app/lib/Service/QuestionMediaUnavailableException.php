<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * A question media file exists but cannot be read right now: a read error of the storage
 * backend (GenericFileException) or a lock held by a running import (GitHub #66). The audio
 * endpoint answers 503 instead of a 500 with a stack trace.
 */
class QuestionMediaUnavailableException extends \RuntimeException {
}
