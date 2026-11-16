<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Service\CertIssuerName;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\OperatorInfo;
use OCP\Defaults;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/** GitHub #77: course name → operator's legal name → Theming name. */
#[AllowMockObjectsWithoutExpectations]
class CertIssuerNameTest extends TestCase {
    private function resolver(string $operatorName, string $themingName = 'T-Solutions'): CertIssuerName {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = ''): string => $key === 'operator_name' ? $operatorName : $default
        );
        $theming = $this->createMock(Defaults::class);
        $theming->method('getName')->willReturn($themingName);
        return new CertIssuerName(new OperatorInfo($config), $theming);
    }

    private function course(string $issuerName): Course {
        $layout = CertLayout::defaults();
        $layout['issuer_name'] = $issuerName;
        $course = new Course();
        $course->setCertLayout(CertLayout::encode($layout));
        return $course;
    }

    public function testCourseNameWins(): void {
        $this->assertSame('Training Solutions LTD', $this->resolver('Operator GmbH')->forCourse($this->course('Training Solutions LTD')));
    }

    public function testOperatorNameWhenTheCourseHasNone(): void {
        $this->assertSame('Operator GmbH', $this->resolver('Operator GmbH')->forCourse($this->course('')));
        $this->assertSame('Operator GmbH', $this->resolver('Operator GmbH')->fallback());
    }

    public function testThemingNameLast(): void {
        $this->assertSame('T-Solutions', $this->resolver('')->forCourse(new Course()));
        $this->assertSame('T-Solutions', $this->resolver('   ')->fallback());
    }
}
