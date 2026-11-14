<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Service\ChunkingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that picks up extracted-but-unchunked documents
 * and splits their text into ~500-token chunks for keyword search.
 *
 * Runs every 5 minutes for near-real-time chunking after document extraction.
 */
class ChunkingJob extends TimedJob {
    /** A document still in 'chunking' after this long belongs to a run that died (audit #62). */
    public const STALE_CHUNKING_SECONDS = 3600;

    private CourseDocumentMapper $documentMapper;
    private ChunkingService $chunkingService;
    private LoggerInterface $logger;
    // Own reference — the NC Job base class does not expose its time factory to subclasses.
    private ITimeFactory $timeFactory;

    public function __construct(
        ITimeFactory $time,
        CourseDocumentMapper $documentMapper,
        ChunkingService $chunkingService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->timeFactory = $time;
        $this->setInterval(5 * 60); // Every 5 minutes
        $this->documentMapper = $documentMapper;
        $this->chunkingService = $chunkingService;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $now = $this->timeFactory->getTime();
        $documents = $this->documentMapper->findExtractedUnchunked(10, $now - self::STALE_CHUNKING_SECONDS);

        if (count($documents) === 0) {
            return;
        }

        $processed = 0;

        foreach ($documents as $doc) {
            try {
                $doc->setChunkingStatus('chunking');
                // When the claim was made, so a run that dies here is retried after an hour.
                $doc->setUpdatedAt($this->timeFactory->getTime());
                $this->documentMapper->update($doc);

                $chunkCount = $this->chunkingService->chunkDocument($doc);

                $doc->setChunkingStatus('chunked');
                $this->documentMapper->update($doc);

                $this->logger->info('ChunkingJob: chunked document {docId} ({file}) into {chunks} chunks', [
                    'docId' => $doc->getId(),
                    'file' => $doc->getFileName(),
                    'chunks' => $chunkCount,
                    'app' => 'learning',
                ]);

                $processed++;
            } catch (\Throwable $e) {
                $doc->setChunkingStatus('error');
                try {
                    $this->documentMapper->update($doc);
                } catch (\Throwable $updateError) {
                    // Best-effort status update
                }

                $this->logger->warning('ChunkingJob: failed to chunk document {docId}: {error}', [
                    'docId' => $doc->getId(),
                    'error' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }

        $this->logger->info('ChunkingJob: processed {count} documents', [
            'count' => $processed,
            'app' => 'learning',
        ]);
    }
}
