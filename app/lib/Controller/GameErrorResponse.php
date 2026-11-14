<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

/**
 * Error answer of the game endpoints (league, duel, gameshow, coop).
 *
 * Their services report user errors as exception messages ('Daily kudos limit', 'Session is not
 * active' ...), and the clients show that text, so those stay a 400 with the message. Server
 * faults are something else: a database error or a PHP error used to come back as a 400 with
 * the raw SQL/Doctrine text in the browser and nothing in the log, so nobody noticed them
 * (audit #141). They are now logged and answered with a generic 500. A missing row (wrong
 * code, deleted session) is a 404 without the mapper's message, which contains the query.
 */
final class GameErrorResponse {
    private function __construct() {
    }

    public static function fromThrowable(\Throwable $e, LoggerInterface $logger, string $fallback, string $action): DataResponse {
        if ($e instanceof DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        if (self::isServerFault($e)) {
            $logger->error('Game endpoint {action} failed: {message}', [
                'action' => $action,
                'message' => $e->getMessage(),
                'exception' => $e,
                'app' => 'learning',
            ]);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse(['error' => $e->getMessage() !== '' ? $e->getMessage() : $fallback], Http::STATUS_BAD_REQUEST);
    }

    private static function isServerFault(\Throwable $e): bool {
        return $e instanceof \OCP\DB\Exception
            || $e instanceof \PDOException
            || $e instanceof \OCP\AppFramework\Db\MultipleObjectsReturnedException
            || $e instanceof \Error;
    }
}
