<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for the learning_ai_chat_memory table.
 *
 * @method int    getId()
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getRole()
 * @method void   setRole(string $role)
 * @method string getMessage()
 * @method void   setMessage(string $message)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int|null getSwarmSourced()
 * @method void   setSwarmSourced(?int $swarmSourced)
 *
 * swarmSourced (5.7.0, spec 3.6): 1 = the answer or summary was built with another learner's
 * contribution in the prompt, 0 = it was not, NULL = unknown (written before 5.7.0). The learner's
 * own messages are always 0.
 */
class AiChatMemory extends Entity {
    protected $userId;
    protected $role;
    protected $message;
    protected $createdAt;
    protected $swarmSourced;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('role', 'string');
        $this->addType('message', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('swarmSourced', 'integer');
    }
}
