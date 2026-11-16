<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The cues JSON of a question for the media player (GitHub #66): keywords per dialog line,
 * contrast wrong/right, key takeaway. It names the right answer, so it is handed out only where
 * the solution is revealed (QuestionMediaService); no jsonSerialize().
 *
 * @method int getQuestionId()
 * @method void setQuestionId(int $questionId)
 * @method int getPoolId()
 * @method void setPoolId(int $poolId)
 * @method string getCues()
 * @method void setCues(string $cues)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class QuestionCues extends Entity {
    protected $questionId;
    protected $poolId;
    protected $cues;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('questionId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('cues', 'string');
        $this->addType('updatedAt', 'integer');
    }
}
