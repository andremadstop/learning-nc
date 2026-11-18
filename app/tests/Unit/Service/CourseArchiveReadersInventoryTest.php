<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #99: the archive dialog and the note on an archived course tell instructors what
 * archiving changes ("Lernende können weiterhin … / … nicht mehr …"). That text is true only as
 * long as the code that reads a course's status is what it was when the text was written.
 *
 * Inventory 2026-11-18 (5.16.0). Code that reads or writes learning_courses.status:
 *   - CourseMapper::findStudentExamCourses   active only → the Dashboard widget's exam countdown
 *   - ReminderService::getExamReminderTargets active only → the exam date reminders
 *   - DataExportService::exportCourses       exports the status (GDPR export), no effect
 *   - CourseService::create                  writes 'active' for a new course
 *   - CourseService::update                  writes it (the "Archive"/"Activate" menu)
 *   - CourseArchiveService::markArchived     writes it (POST /archive, occ, course merge)
 * Nothing else: training, Leitner, exams (counting and practice), certificate issuance, renewal,
 * recertification reminders, materials, enrolment, the calendar feed, activities and every
 * instructor tab ignore the status. app/tests/Integration/CourseArchiveEffects99Test.php checks
 * the behaviour on a real database.
 *
 * A new reader fails this test. Then decide what it means for an archived course and update
 * the text in src/components/CourseArchiveExplanation.vue together with the list below.
 */
final class CourseArchiveReadersInventoryTest extends TestCase {
    private const KNOWN = [
        'Db/CourseMapper.php::findStudentExamCourses',
        'Service/ReminderService.php::getExamReminderTargets',
        'Service/DataExportService.php::exportCourses',
        'Service/CourseService.php::create',
        'Service/CourseService.php::update',
        'Service/CourseArchiveService.php::markArchived',
    ];

    public function testEveryReaderOfTheCourseStatusIsKnown(): void {
        $found = $this->scan();
        sort($found);
        $known = self::KNOWN;
        sort($known);

        $this->assertSame($known, $found,
            'The code reading or writing learning_courses.status changed. Check what it means for an '
            . 'archived course and update CourseArchiveExplanation.vue (GitHub #99) and this list.');
    }

    /**
     * Finds, per file and enclosing function:
     *   - "<alias>.status" where <alias> names learning_courses in that file
     *     (->from('learning_courses', 'c'), ->innerJoin('x', 'learning_courses', 'c', …),
     *     CourseMapper's getTableName(), 'c'),
     *   - a 'status' literal in CourseMapper or next to an unaliased learning_courses query,
     *   - the literal 'archived' outside the support tickets (their own archive) and migrations,
     *   - getStatus()/setStatus() on a variable named like a course.
     * Comment lines are skipped.
     *
     * @return list<string>
     */
    private function scan(): array {
        $root = realpath(__DIR__ . '/../../../lib');
        $this->assertNotFalse($root);
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            if (str_starts_with($relative, 'Migration/') || str_contains($relative, 'SupportTicket')) {
                continue;
            }
            $source = (string)file_get_contents($path);
            if (!str_contains($source, 'learning_courses') && !str_contains($source, 'status')) {
                continue;
            }

            preg_match_all("/'learning_courses',\\s*'(\\w+)'/", $source, $m);
            $aliases = $m[1];
            if ($relative === 'Db/CourseMapper.php') {
                preg_match_all("/getTableName\\(\\),\\s*'(\\w+)'/", $source, $m2);
                $aliases = array_merge($aliases, $m2[1]);
            }
            $aliases = array_values(array_unique($aliases));
            $unaliased = $relative === 'Db/CourseMapper.php'
                || preg_match("/(from|update|insert)\\('learning_courses'\\)/", $source) === 1;

            $function = '(file)';
            foreach (preg_split('/\R/', $source) as $line) {
                if (preg_match('/function\s+(\w+)\s*\(/', $line, $fm) === 1) {
                    $function = $fm[1];
                }
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
                    continue;
                }
                $hit = false;
                foreach ($aliases as $alias) {
                    if (preg_match('/[\'"]' . preg_quote($alias, '/') . '\.status\b/', $line) === 1) {
                        $hit = true;
                    }
                }
                if ($unaliased && preg_match("/'status'/", $line) === 1) {
                    $hit = true;
                }
                if (preg_match("/'archived'/", $line) === 1) {
                    $hit = true;
                }
                if (preg_match('/\$\w*course\w*->(get|set)Status\(/i', $line) === 1) {
                    $hit = true;
                }
                if ($hit) {
                    $hits[$relative . '::' . $function] = true;
                }
            }
        }
        return array_keys($hits);
    }
}
