<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\DataExportController;
use OCA\Learning\Controller\ExportController;
use OCA\Learning\Service\DataExportService;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Audit #199: both JSON exports passed json_encode()'s result straight to DataDownloadResponse,
 * whose constructor takes `string`. One invalid UTF-8 byte in stored text (a pasted PDF snippet,
 * an imported pool) made json_encode return false, and under strict_types that was a TypeError,
 * i.e. a bare 500. The unit stub accepted any $data, so no test could see it.
 */
class JsonExportEncodingTest extends TestCase {

    private const BROKEN_UTF8 = "Frage \xC3\x28 kaputt";

    private function exportController(DataMobilityService $mobility): ExportController {
        return new ExportController(
            'learning',
            $this->createStub(IRequest::class),
            $this->createStub(QuestionService::class),
            $mobility,
            $this->createStub(IcsService::class),
            'alice'
        );
    }

    public function testPoolJsonExportSurvivesInvalidUtf8(): void {
        $mobility = $this->createStub(DataMobilityService::class);
        $mobility->method('exportPoolJson')->willReturn(['questions' => [['text' => self::BROKEN_UTF8]]]);

        $resp = $this->exportController($mobility)->exportJson(7);

        self::assertInstanceOf(DataDownloadResponse::class, $resp);
        $decoded = json_decode((string)$resp->render(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Frage \u{FFFD}( kaputt", $decoded['questions'][0]['text']);
    }

    public function testPoolJsonExportThatCannotBeEncodedIsA500WithMessage(): void {
        $mobility = $this->createStub(DataMobilityService::class);
        $mobility->method('exportPoolJson')->willReturn(['score' => NAN]);

        $resp = $this->exportController($mobility)->exportJson(7);

        self::assertInstanceOf(DataResponse::class, $resp);
        self::assertSame(500, $resp->getStatus());
        self::assertStringContainsString('could not be encoded', $resp->getData()['error']);
    }

    public function testPersonalDataExportSurvivesInvalidUtf8(): void {
        $service = $this->createStub(DataExportService::class);
        $service->method('exportForUser')->willReturn(['notes' => [self::BROKEN_UTF8]]);

        $resp = (new DataExportController('learning', $this->createStub(IRequest::class), $service, 'alice'))->myData();

        self::assertInstanceOf(DataDownloadResponse::class, $resp);
        $decoded = json_decode((string)$resp->render(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Frage \u{FFFD}( kaputt", $decoded['notes'][0]);
    }

    public function testPersonalDataExportThatCannotBeEncodedIsA500WithMessage(): void {
        $service = $this->createStub(DataExportService::class);
        $service->method('exportForUser')->willReturn(['ratio' => INF]);

        $resp = (new DataExportController('learning', $this->createStub(IRequest::class), $service, 'alice'))->myData();

        self::assertInstanceOf(DataResponse::class, $resp);
        self::assertSame(500, $resp->getStatus());
    }
}
