<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One audio track of a question (GitHub #66): kind intro | dialog, a language, the mp3 in the
 * app's own app data (QuestionMediaStorage) and its subtitle timeline. No jsonSerialize(): what a
 * learner may see of it is decided by QuestionMediaService, never by serializing the row.
 *
 * @method int getQuestionId()
 * @method void setQuestionId(int $questionId)
 * @method int getPoolId()
 * @method void setPoolId(int $poolId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getLang()
 * @method void setLang(string $lang)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method string|null getTimeline()
 * @method void setTimeline(?string $timeline)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class QuestionTrack extends Entity {
    protected $questionId;
    protected $poolId;
    protected $kind;
    protected $lang;
    protected $fileName;
    protected $size;
    protected $sha256;
    protected $timeline;
    protected $durationMs;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('questionId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('kind', 'string');
        $this->addType('lang', 'string');
        $this->addType('fileName', 'string');
        $this->addType('size', 'integer');
        $this->addType('sha256', 'string');
        $this->addType('timeline', 'string');
        $this->addType('durationMs', 'integer');
        $this->addType('updatedAt', 'integer');
    }
}
