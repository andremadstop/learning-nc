<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class QuestionTranslation extends Entity implements JsonSerializable {
    protected $questionId;
    protected $lang;
    protected $text;
    protected $explanation;
    protected $scenario;
    protected $hint;
    protected $backgroundFact;
    protected $keyTakeaway;
    protected $optionFeedback;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('questionId', 'integer');
        $this->addType('lang', 'string');
        $this->addType('text', 'string');
        $this->addType('explanation', 'string');
        $this->addType('scenario', 'string');
        $this->addType('hint', 'string');
        $this->addType('backgroundFact', 'string');
        $this->addType('keyTakeaway', 'string');
        $this->addType('optionFeedback', 'string');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'question_id' => $this->getQuestionId(),
            'lang' => $this->getLang(),
            'text' => $this->getText(),
            'explanation' => $this->getExplanation(),
            'scenario' => $this->getScenario(),
            // GitHub #66 — the translation endpoints strip all four for readers without edit
            // access and during an exam (TranslationService::getQuestionTranslations).
            'hint' => $this->getHint(),
            'background_fact' => $this->getBackgroundFact(),
            'key_takeaway' => $this->getKeyTakeaway(),
            'option_feedback' => \OCA\Learning\Service\RichFeedbackFormat::decodeOptions($this->getOptionFeedback()),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
