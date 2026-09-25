<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Why a model call is made. Only AdminTest may run without a subject user (spec 2.1).
 */
enum LlmPurpose: string {
    case Chat = 'chat';
    case Interview = 'interview';
    case TelosStructured = 'telos_structured';
    case Note = 'note';
    case WeeklyNote = 'weekly_note';
    case CourseNarrative = 'course_narrative';
    case MemoryCompress = 'memory_compress';
    case Lernplan = 'lernplan';
    case Story = 'story';
    case TicketTriage = 'ticket_triage';
    case PoolGenerate = 'pool_generate';
    case Explain = 'explain';
    case QuestionGenerate = 'question_generate';
    case AdminTest = 'admin_test';
}
