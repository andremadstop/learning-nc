<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ComplianceEventTypes;
use PHPUnit\Framework\TestCase;

class ComplianceEventTypesTest extends TestCase {
    public function testAllConstants(): void {
        $this->assertSame('course.passed',         ComplianceEventTypes::COURSE_PASSED);
        $this->assertSame('cert.issued',           ComplianceEventTypes::CERT_ISSUED);
        $this->assertSame('cert.revoked',          ComplianceEventTypes::CERT_REVOKED);
        $this->assertSame('course.video.completed', ComplianceEventTypes::VIDEO_COMPLETED);
    }
}
