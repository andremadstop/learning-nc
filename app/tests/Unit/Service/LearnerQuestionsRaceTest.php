<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketNotifier;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #59 (review): answering, archiving and restoring read a question, then write it. What
 * happens to the row in between (a learner withdraws it, an instructor deletes or answers it)
 * must decide the result, not the read.
 *
 * The mapper here keeps the stored row apart from what findById() hands out, so a test can
 * change the row after the service has read it.
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerQuestionsRaceTest extends TestCase {
    /** @var array<int, SupportTicket> the rows as stored */
    private array $rows = [];
    /** @var (callable(): void)|null runs after the service's first read, before its write */
    private $meanwhile = null;
    /** @var list<string> */
    private array $notices = [];

    private function row(int $id, string $status, ?string $answer = null): void {
        $ticket = new SupportTicket();
        $ticket->setId($id);
        $ticket->setUserId('learner');
        $ticket->setSubject('Frist');
        $ticket->setMessage('Bis wann?');
        $ticket->setStatus($status);
        $ticket->setCreatedAt(100);
        $ticket->setRoutingTargetType('course_instructor');
        $ticket->setRoutingCourseId(7);
        $ticket->setAnswerText($answer);
        $this->rows[$id] = $ticket;
    }

    private function service(): SupportTicketService {
        $mapper = $this->createMock(SupportTicketMapper::class);
        $mapper->method('findById')->willReturnCallback(function (int $id): SupportTicket {
            if (!isset($this->rows[$id])) {
                throw new DoesNotExistException('none');
            }
            $copy = clone $this->rows[$id];
            if ($this->meanwhile !== null) {
                $meanwhile = $this->meanwhile;
                $this->meanwhile = null;
                $meanwhile();
            }
            return $copy;
        });
        // like QBMapper::update(): writes the changed fields, says nothing about a missing row
        $mapper->method('update')->willReturnCallback(function (SupportTicket $t): SupportTicket {
            $id = (int)$t->getId();
            if (isset($this->rows[$id])) {
                $stored = $this->rows[$id];
                $stored->setStatus($t->getStatus());
                $stored->setAnswerText($t->getAnswerText());
                $stored->setAnsweredBy($t->getAnsweredBy());
            }
            return $t;
        });
        $mapper->method('restoreArchived')->willReturnCallback(function (int $id): void {
            $stored = $this->rows[$id] ?? null;
            if ($stored !== null && $stored->getStatus() === 'archived') {
                $stored->setStatus($stored->getAnswerText() === null ? 'open' : 'answered');
            }
        });

        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturn(true);
        $notifier = $this->createMock(SupportTicketNotifier::class);
        $notifier->method('answerGiven')->willReturnCallback(function (SupportTicket $t): void {
            $this->notices[] = 'answer ' . $t->getId();
        });
        $notifier->method('questionSettled')->willReturnCallback(function (SupportTicket $t): void {
            $this->notices[] = 'settled ' . $t->getId();
        });

        return new SupportTicketService($mapper, $courses, $this->createMock(GeminiService::class),
            $this->createMock(TelosService::class), $notifier);
    }

    public function testAnAnswerToAQuestionWithdrawnMeanwhileIsNotFoundAndNotifiesNobody(): void {
        $this->row(11, 'open');
        $this->meanwhile = function (): void {
            unset($this->rows[11]);
        };
        $service = $this->service();

        try {
            $service->answer(11, 'Bis Freitag.', 'owner', true);
            $this->fail('an answer to a withdrawn question must not be reported as saved');
        } catch (DoesNotExistException $e) {
        }
        $this->assertSame([], $this->notices);
    }

    public function testApprovingADraftOfAQuestionDeletedMeanwhileIsNotFound(): void {
        $this->row(11, 'open');
        $this->rows[11]->setAiDraftAnswer('Bis Freitag.');
        $this->meanwhile = function (): void {
            unset($this->rows[11]);
        };

        $this->expectException(DoesNotExistException::class);
        try {
            $this->service()->approveDraft(11, 'admin');
        } finally {
            $this->assertSame([], $this->notices);
        }
    }

    public function testArchivingAQuestionDeletedMeanwhileIsNotFound(): void {
        $this->row(11, 'open');
        $this->meanwhile = function (): void {
            unset($this->rows[11]);
        };

        $this->expectException(DoesNotExistException::class);
        try {
            $this->service()->setCourseTicketArchived(7, 11, 'owner', true);
        } finally {
            $this->assertSame([], $this->notices);
        }
    }

    public function testRestoringAfterAnAnswerSavedMeanwhileGivesAnswered(): void {
        $this->row(11, 'archived');
        // the instructor read the question without an answer; another one answers it right then
        $this->meanwhile = function (): void {
            $this->rows[11]->setAnswerText('Bis Freitag.');
        };

        $result = $this->service()->setCourseTicketArchived(7, 11, 'owner', false);

        $this->assertSame('answered', $result['status']);
        $this->assertSame('answered', $this->rows[11]->getStatus());
    }

    public function testAnsweringAnArchivedQuestionBringsItBackAsAnswered(): void {
        // decided on purpose: whoever answers an archived question (an old tab still shows it
        // as open) treats it as not settled, so it returns to the inbox, and the learner hears
        $this->row(11, 'archived');

        $result = $this->service()->answer(11, 'Bis Freitag.', 'owner', true);

        $this->assertSame('answered', $result['status']);
        $this->assertSame('answered', $this->rows[11]->getStatus());
        $this->assertSame('Bis Freitag.', $this->rows[11]->getAnswerText());
        $this->assertSame(['answer 11'], $this->notices);
    }
}
