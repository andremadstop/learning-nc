<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getDocumentId()
 * @method void setDocumentId(int $documentId)
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string|null getChapter()
 * @method void setChapter(?string $chapter)
 * @method string getText()
 * @method void setText(string $text)
 * @method string getSourceFile()
 * @method void setSourceFile(string $sourceFile)
 * @method int getChunkIndex()
 * @method void setChunkIndex(int $chunkIndex)
 * @method int getTokenCount()
 * @method void setTokenCount(int $tokenCount)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 */
class RagChunk extends Entity {
    protected $documentId;
    protected $courseId;
    protected $chapter;
    protected $text;
    protected $sourceFile;
    protected $chunkIndex;
    protected $tokenCount;
    protected $createdAt;
    protected $userId;
    protected $status;
    protected $sourceType;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('chunkIndex', 'integer');
        $this->addType('tokenCount', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'course_id' => $this->courseId,
            'chapter' => $this->chapter,
            'text' => $this->text,
            'source_file' => $this->sourceFile,
            'chunk_index' => $this->chunkIndex,
            'token_count' => $this->tokenCount,
            'created_at' => $this->createdAt,
            'user_id' => $this->userId,
            'status' => $this->status,
            'source_type' => $this->sourceType,
        ];
    }
}
