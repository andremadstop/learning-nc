<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #75 (review): a required document cannot be confirmed while its file cannot be read
 * (gone, outside the material folder, storage not reachable). A confirmation without a version
 * would count like one taken over from before 5.14.0 and never ask again after a change.
 */
class DocumentUnavailableException extends \RuntimeException {
}
