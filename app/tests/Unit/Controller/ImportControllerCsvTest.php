<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * CSV import in the interface (GitHub #34 round 2): a quoted field may contain line breaks
 * (RFC 4180). The importer used to split the text at every "\n" first, so such a question
 * was torn into several broken lines and none of them imported.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportControllerCsvTest extends TestCase {
    use ImportControllerHarness;

    public function testQuotedFieldsKeepTheirLineBreaks(): void {
        $csv = "question,answer1,answer2,answer3,correct,explanation\n"
            . "\"Look at this output:\nPING 10.0.0.1\n100% loss\nWhat is wrong?\",\"Cable\",\"DNS, probably\",\"Nothing\",1,\"Line one\r\nline two\"\n"
            . "Plain one,a,b,2\n";

        $r = $this->importController()->importCsv(7, $csv);

        $this->assertSame(201, $r->getStatus(), json_encode($r->getData()));
        $this->assertSame(2, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame([], $r->getData()['errors']);
        $this->assertSame("Look at this output:\nPING 10.0.0.1\n100% loss\nWhat is wrong?", $this->questions[0]->getText());
        $this->assertSame([
            ['text' => 'Cable', 'correct' => true],
            ['text' => 'DNS, probably', 'correct' => false],
            ['text' => 'Nothing', 'correct' => false],
        ], $this->storedAnswers($this->questions[0]));
        $this->assertSame("Line one\nline two", $this->questions[0]->getExplanation());
        $this->assertSame('Plain one', $this->questions[1]->getText());
        $this->assertSame([['text' => 'a', 'correct' => false], ['text' => 'b', 'correct' => true]], $this->storedAnswers($this->questions[1]));
    }

    public function testSemicolonBomDoubledQuotesAndABackslashAtTheEndOfAField(): void {
        $csv = "\xEF\xBB\xBFFrage;Antwort1;Antwort2;Korrekt\r\n"
            . "\"Er sagte \"\"Hallo\"\";\nwas nun?\";\"C:\\\";\"D:\\\";2\r\n"
            . "\r\n"
            . "Zweite; ja ; nein ;1\r\n";

        $r = $this->importController()->importCsv(7, $csv);

        $this->assertSame(2, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame("Er sagte \"Hallo\";\nwas nun?", $this->questions[0]->getText());
        $this->assertSame([['text' => 'C:\\', 'correct' => false], ['text' => 'D:\\', 'correct' => true]], $this->storedAnswers($this->questions[0]));
        $this->assertSame([['text' => 'ja', 'correct' => true], ['text' => 'nein', 'correct' => false]], $this->storedAnswers($this->questions[1]));
    }

    public function testOpenQuestionWithAMultiLineModelAnswerAndLineNumbersCountRecords(): void {
        $csv = "\"Explain TCP.\",\"Reliable,\nordered\",open\n"
            . "Broken line without enough fields,a\n";

        $r = $this->importController()->importCsv(7, $csv);

        $this->assertSame(1, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame('open', $this->questions[0]->getQuestionType());
        $this->assertSame([['text' => "Reliable,\nordered", 'correct' => true]], $this->storedAnswers($this->questions[0]));
        // "Line" counts records (questions), not physical lines: the second record is line 2.
        $this->assertSame(['Line 2: Not enough fields (need: question, answers, correct number)'], $r->getData()['errors']);
    }

    public function testThe500LimitCountsRecordsNotPhysicalLines(): void {
        $record = "\"Q\nwith\nfour lines\",a,b,1\n";
        $r = $this->importController()->importCsv(7, str_repeat($record, 200));

        $this->assertSame(200, $r->getData()['imported'], json_encode(array_slice($r->getData(), 0, 2)));
    }

    public function testTheHeaderDoesNotCountTowardThe500Limit(): void {
        $r = $this->importController()->importCsv(7, "question,answer1,answer2,correct\n" . str_repeat("Q,a,b,1\n", 500));

        $this->assertSame(500, $r->getData()['imported'] ?? null, json_encode(array_slice($r->getData(), 0, 2)));
    }

    public function testMoreThan500RecordsAreRefusedWithOrWithoutHeader(): void {
        $c = $this->importController();
        $this->assertSame('Maximum 500 items per import', $c->importCsv(7, str_repeat("Q,a,b,1\n", 501))->getData()['error'] ?? null);
        $this->assertSame('Maximum 500 items per import', $c->importCsv(7, "question,answer1,answer2,correct\n" . str_repeat("Q,a,b,1\n", 501))->getData()['error'] ?? null);
        $this->assertSame([], $this->questions);
    }

    public function testCsvRecordsStopsReadingAtItsLimit(): void {
        $method = new \ReflectionMethod(\OCA\Learning\Controller\ImportController::class, 'csvRecords');
        $records = $method->invoke(null, str_repeat("Q,a,b,1\n", 900), ',', 502);
        $this->assertCount(502, $records);
    }

    public function testATruncatedAnswerIsReportedAsAWarning(): void {
        $long = str_repeat('y', 2001);
        $r = $this->importController()->importCsv(7, "Q,{$long},b,1\nOpen?,{$long},open\n");

        $this->assertSame(2, $r->getData()['imported'], json_encode(array_slice($r->getData(), 0, 2)));
        $this->assertSame(2000, mb_strlen((string)$this->storedAnswers($this->questions[0])[0]['text']));
        $this->assertSame(['Line 1: Answer text truncated to 2000 chars', 'Line 2: Answer text truncated to 2000 chars'], $r->getData()['warnings'] ?? null);
    }

    /**
     * importFile() decides the format like the dialog: the extension .json/.csv first; without
     * one of them, JSON only when the content really decodes to a list or object. A CSV whose
     * first field starts with "[" or "{" used to go to the JSON reader and fail.
     */
    private function importUpload(string $name, string $content): \OCP\AppFramework\Http\DataResponse {
        $tmp = tempnam(sys_get_temp_dir(), 'learning-upload-');
        file_put_contents($tmp, $content);
        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getUploadedFile')->willReturnCallback(static fn (string $key) => $key === 'file'
            ? ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK]
            : null);
        try {
            return $this->importController($request)->importFile(7);
        } finally {
            @unlink($tmp);
        }
    }

    public function testACsvFileStartingWithABracketIsImportedAsCsv(): void {
        $r = $this->importUpload('network.csv', "[Network] Which port does SSH use?,21,22,23,2\n{Linux} Which command lists files?,ls,cd,pwd,1\n");

        $this->assertSame(2, $r->getData()['imported'] ?? null, json_encode($r->getData()));
        $this->assertSame('[Network] Which port does SSH use?', $this->questions[0]->getText());
        $this->assertSame([['text' => '21', 'correct' => false], ['text' => '22', 'correct' => true], ['text' => '23', 'correct' => false]], $this->storedAnswers($this->questions[0]));
    }

    public function testWithoutAClearExtensionOnlyRealJsonIsReadAsJson(): void {
        $r = $this->importUpload('pool.txt', "[Network] Which port does SSH use?,21,22,23,2\n");
        $this->assertSame(1, $r->getData()['imported'] ?? null, json_encode($r->getData()));
        $this->assertSame('[Network] Which port does SSH use?', $this->questions[0]->getText());

        $r = $this->importUpload('pool.txt', '[{"text":"Q?","answers":["a","b"],"answer":1}]');
        $this->assertSame(1, $r->getData()['imported'] ?? null, json_encode($r->getData()));
        $this->assertSame('Q?', $this->questions[0]->getText());
        $this->assertSame([['text' => 'a', 'correct' => false], ['text' => 'b', 'correct' => true]], $this->storedAnswers($this->questions[0]));
    }
}
