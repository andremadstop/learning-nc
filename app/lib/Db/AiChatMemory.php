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
 */
class AiChatMemory extends Entity {
    protected $userId;
    protected $role;
    protected $message;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('role', 'string');
        $this->addType('message', 'string');
        $this->addType('createdAt', 'integer');
    }
}
