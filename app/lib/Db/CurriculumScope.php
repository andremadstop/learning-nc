<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method ?string getHandbookKey()
 * @method void setHandbookKey(?string $handbookKey)
 * @method ?string getHandbookTitle()
 * @method void setHandbookTitle(?string $handbookTitle)
 * @method ?string getChapterKeysJson()
 * @method void setChapterKeysJson(?string $chapterKeysJson)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class CurriculumScope extends Entity {
    protected $courseId;
    protected $enabled;
    protected $handbookKey;
    protected $handbookTitle;
    protected $chapterKeysJson;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('courseId', 'integer');
        $this->addType('enabled', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function getChapterKeys(): array {
        if ($this->chapterKeysJson === null || $this->chapterKeysJson === '') {
            return [];
        }
        return json_decode($this->chapterKeysJson, true) ?? [];
    }

    public function setChapterKeys(array $keys): void {
        $this->setChapterKeysJson(json_encode(array_values($keys)));
    }
}
