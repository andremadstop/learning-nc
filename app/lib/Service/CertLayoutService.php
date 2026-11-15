<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;

/**
 * GitHub #43: stamp and signature images of a course's certificates, and the presentation a
 * certificate is drawn with.
 *
 * Images come from the instructor's own Nextcloud files (file picker). They are read through the
 * instructor's user folder, so only files that person can read are reachable, checked to be a real
 * PNG within the size and dimension limits, and copied into learning_cert_assets under their
 * sha256. A stored image is never overwritten: a certificate issued with stamp A keeps showing
 * stamp A after the course switched to stamp B. An image no course layout and no certificate
 * points to any more is removed.
 *
 * The bytes leave the server only as data URLs inside the certificate's own show response (and the
 * instructor's settings preview), so whoever may see the certificate sees its images and nobody
 * else; there is no separate image URL to guess or share.
 */
class CertLayoutService {
    public const MAX_BYTES = 1048576;
    public const MAX_DIMENSION = 3000;
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private CourseMapper $courseMapper,
        private ITimeFactory $timeFactory,
    ) {
    }

    /**
     * Take a PNG from the user's files as the course's stamp or signature.
     *
     * The caller has checked that $userId manages the course.
     *
     * @throws NotFoundException          the path is not a file the user can read
     * @throws \InvalidArgumentException  wrong kind, not a PNG, too large (code in the message)
     */
    public function importImage(Course $course, string $kind, string $userId, string $path): Course {
        if (!in_array($kind, CertLayout::IMAGE_KINDS, true)) {
            throw new \InvalidArgumentException('invalid_kind');
        }
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new NotFoundException('empty path');
        }
        try {
            $node = $this->rootFolder->getUserFolder($userId)->get($path);
        } catch (NotPermittedException $e) {
            throw new NotFoundException('not readable');
        }
        if (!($node instanceof File) || !$node->isReadable()) {
            throw new NotFoundException('not a readable file');
        }
        if ($node->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('too_large');
        }
        try {
            $bytes = $node->getContent();
        } catch (NotPermittedException $e) {
            throw new NotFoundException('not readable');
        }
        $this->assertPng($bytes);

        $sha = hash('sha256', $bytes);
        $this->storeAsset((int)$course->getId(), $sha, $bytes);

        $layout = CertLayout::decode($course->getCertLayout());
        $previous = $layout[$kind . '_sha'];
        $layout[$kind . '_sha'] = $sha;
        $course->setCertLayout(CertLayout::encode($layout));
        $course->setUpdatedAt($this->timeFactory->getTime());
        /** @var Course $course */
        $course = $this->courseMapper->update($course);

        if ($previous !== null && $previous !== $sha) {
            $this->deleteIfUnreferenced($course, $previous);
        }
        return $course;
    }

    /**
     * Remove the course's stamp or signature. Certificates issued with it keep it.
     *
     * @throws \InvalidArgumentException wrong kind
     */
    public function removeImage(Course $course, string $kind): Course {
        if (!in_array($kind, CertLayout::IMAGE_KINDS, true)) {
            throw new \InvalidArgumentException('invalid_kind');
        }
        $layout = CertLayout::decode($course->getCertLayout());
        $previous = $layout[$kind . '_sha'];
        if ($previous === null) {
            return $course;
        }
        $layout[$kind . '_sha'] = null;
        $course->setCertLayout(CertLayout::encode($layout));
        $course->setUpdatedAt($this->timeFactory->getTime());
        /** @var Course $course */
        $course = $this->courseMapper->update($course);
        $this->deleteIfUnreferenced($course, $previous);
        return $course;
    }

    /**
     * The presentation an issued certificate is drawn with, or null for the standard card
     * (every certificate issued before 5.13.0, and every one of a course with the standard layout).
     *
     * @return array<string, mixed>|null
     */
    public function presentationForCertificate(Certificate $cert): ?array {
        $json = $cert->getLayoutJson();
        if ($json === null || $json === '') {
            return null;
        }
        return $this->presentation(CertLayout::decode($json), (int)$cert->getCourseId());
    }

    /**
     * The course's current presentation, for the preview in the course settings.
     *
     * @return array<string, mixed>
     */
    public function presentationForCourse(Course $course): array {
        return $this->presentation(CertLayout::decode($course->getCertLayout()), (int)$course->getId());
    }

    /**
     * @param array<string, mixed> $layout decoded
     * @return array<string, mixed>
     */
    private function presentation(array $layout, int $courseId): array {
        return [
            'intro' => $layout['intro'],
            'show' => $layout['show'],
            'signer_name' => $layout['signer_name'],
            'signer_role' => $layout['signer_role'],
            'stamp' => $layout['stamp_sha'] !== null ? $this->dataUrl($courseId, $layout['stamp_sha']) : null,
            'signature' => $layout['signature_sha'] !== null ? $this->dataUrl($courseId, $layout['signature_sha']) : null,
        ];
    }

    /**
     * @throws \InvalidArgumentException not_png / too_large
     */
    public function assertPng(string $bytes): void {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('too_large');
        }
        if (!str_starts_with($bytes, self::PNG_MAGIC)) {
            throw new \InvalidArgumentException('not_png');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            throw new \InvalidArgumentException('not_png');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('not_png');
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('too_large');
        }
    }

    private function dataUrl(int $courseId, string $sha): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('mime', 'data_b64')
            ->from('learning_cert_assets')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (!is_array($row) || !is_string($row['data_b64'] ?? null)) {
            return null;
        }
        return 'data:' . (string)$row['mime'] . ';base64,' . $row['data_b64'];
    }

    private function storeAsset(int $courseId, string $sha, string $bytes): void {
        if ($this->assetExists($courseId, $sha)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_cert_assets')
            ->values([
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'sha256' => $qb->createNamedParameter($sha),
                'mime' => $qb->createNamedParameter('image/png'),
                'byte_size' => $qb->createNamedParameter(strlen($bytes), IQueryBuilder::PARAM_INT),
                'data_b64' => $qb->createNamedParameter(base64_encode($bytes)),
                'created_at' => $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            // Two uploads of the same file at once: the other one stored it.
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }
    }

    private function assetExists(int $courseId, string $sha): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('learning_cert_assets')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }

    /**
     * Delete an image nobody uses any more: not the course's stamp or signature, and in no
     * certificate of the course. The sha256 is 64 hex characters, so a LIKE on it cannot match
     * anything else in the JSON.
     */
    private function deleteIfUnreferenced(Course $course, string $sha): void {
        $layout = CertLayout::decode($course->getCertLayout());
        if ($layout['stamp_sha'] === $sha || $layout['signature_sha'] === $sha) {
            return;
        }
        $courseId = (int)$course->getId();
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('learning_certificates')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('layout_json', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($sha) . '%')))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $used = $result->fetchOne() !== false;
        $result->closeCursor();
        if ($used) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_cert_assets')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha)));
        $qb->executeStatement();
    }
}
