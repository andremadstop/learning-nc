<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

/**
 * GitHub #39 (B1): the course switched this learning mode (Training, Leitner, exam) off, and the
 * pool is reachable for the user only through such courses. A ForbiddenException, so callers that
 * map those to 403 keep doing so; controllers tell it apart from the video/material gate.
 */
class CourseModeClosedException extends ForbiddenException {
    public function __construct(string $modeKey) {
        parent::__construct('This learning mode is switched off for your course: ' . $modeKey);
    }
}
