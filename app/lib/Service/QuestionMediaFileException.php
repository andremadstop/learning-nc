<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * A file of a media bundle that is refused (QuestionMediaBundle): $reason is one of the
 * QuestionMediaBundle::E_* codes, $path the manifest path as written.
 */
final class QuestionMediaFileException extends \Exception {
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
    ) {
        parent::__construct($reason . ': ' . $path);
    }
}
