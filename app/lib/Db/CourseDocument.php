<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getFileType()
 * @method void setFileType(string $fileType)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getExtractedText()
 * @method void setExtractedText(?string $extractedText)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int|null getFileSize()
 * @method void setFileSize(?int $fileSize)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getChunkingStatus()
 * @method void setChunkingStatus(?string $chunkingStatus)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method int|null getConfirmAfter()
 * @method void setConfirmAfter(?int $confirmAfter)
 *
 * Deliberately NOT \JsonSerializable (GitHub #65): the full row (extracted_text, file_path,
 * error_message) goes into no response; DocumentService::projectDocuments() builds the per-role
 * view. Without the interface, an entity put into a response by mistake is sent as {"id": N} and
 * leaks nothing. JsonSerializableEntitiesTest keeps this exception list.
 */
class CourseDocument extends Entity {
    protected $courseId;
    protected $filePath;
    protected $fileName;
    protected $fileType;
    protected $status;
    protected $extractedText;
    protected $errorMessage;
    protected $fileSize;
    protected $chunkingStatus;
    /** GitHub #75: Nextcloud file id, so a rename keeps the row (NULL until the next scan). */
    protected $fileId;
    /**
     * GitHub #75: "Ask everyone to confirm again" — confirmations of this document from before it
     * no longer count. On the document, so making it optional and required again keeps it (GitHub #75).
     */
    protected $confirmAfter;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('fileSize', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('fileId', 'integer');
        $this->addType('confirmAfter', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
            'file_type' => $this->fileType,
            'status' => $this->status,
            'extracted_text' => $this->extractedText,
            'error_message' => $this->errorMessage,
            'file_size' => $this->fileSize,
            'chunking_status' => $this->chunkingStatus,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
