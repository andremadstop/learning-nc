<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #86: in a course with open_before_confirm a required document is confirmed only after
 * the learner opened this version of its file in the current cycle (RequiredDocumentService::confirm()).
 */
class DocumentNotOpenedException extends \RuntimeException {
}
