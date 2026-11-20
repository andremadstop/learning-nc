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
 *
 * GitHub #78: the course's logo works the same way. It comes from the admin's logo library
 * (CertLogoLibrary) instead of the instructor's files, is copied into learning_cert_assets under its
 * library id (the sha256 of the library file) when the course picks it, and a certificate keeps the
 * logo it was issued with, also when the library logo is deleted later. The public verify page shows
 * that copy as well. For a logo stored before 5.17.0 the copy is re-encoded (ZC9), so its bytes no
 * longer hash to the id; nothing compares them.
 *
 * A course on the Theming logo: issuance copies the Theming logo of that moment into
 * learning_cert_assets (ThemingLogoSource; PNG, JPEG or WebP only) and names it in the certificate's
 * snapshot as theming_logo_sha, so replacing the Theming logo later does not redraw issued
 * certificates. Without such a copy (certificates issued before 5.14.0, an SVG logo without raster
 * copy) card and verify page show the live Theming logo.
 *
 * GitHub #93: the course's background works like the logo. A library background (CertBackgroundLibrary,
 * re-encoded on upload) is copied into learning_cert_assets when the course picks it, and a
 * certificate keeps the background it was issued with. The built-in backgrounds are drawn in CSS and
 * only named in the layout. The public verify page does not show backgrounds.
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
        private CertIssuerName $issuerName,
        private CertLogoLibrary $logoLibrary,
        private ThemingLogoSource $themingLogo,
        private CertBackgroundLibrary $backgroundLibrary,
    ) {
    }

    /**
     * Take a PNG from the user's files as the course's stamp or signature.
     *
     * The caller has checked that $userId manages the course.
     *
     * @throws NotFoundException          the path is not a file the user can read
     * @throws \InvalidArgumentException  wrong kind, view-only share, not a PNG, too large (code in the message)
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
        // A file shared with the instructor as "view only" (download disabled) is not theirs to
        // hand out: its bytes would reach every learner of the course inside the certificate,
        // and stay in issued certificates after the share is revoked.
        if (!DocumentService::mayBeDownloaded($node)) {
            throw new \InvalidArgumentException('not_downloadable');
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
        $this->storeAsset((int)$course->getId(), $sha, $bytes, 'image/png');

        $layout = CertLayout::decode($course->getCertLayout());
        $previous = $layout[$kind . '_sha'];
        $layout[$kind . '_sha'] = $sha;
        $course->setCertLayout(CertLayout::encode($layout));
        $course->setUpdatedAt($this->timeFactory->getTime());
        /** @var Course $course */
        $course = $this->courseMapper->update($course);
        // The replaced image is not deleted here: see sweepUnreferencedAssets().
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
        // The removed image is not deleted here: see sweepUnreferencedAssets().
        return $course;
    }

    /**
     * GitHub #78: use a logo of the library on the course's certificates, or the Theming logo again
     * ($logoId null or ''). The logo is copied into the course's certificate images now, so a
     * certificate issued with it keeps it whatever happens to the library afterwards.
     *
     * The caller has checked that the user manages the course.
     *
     * @throws \InvalidArgumentException logo_not_found (not, or no longer, in the library); too_large,
     *         unsupported_type (a logo stored before 5.17.0 that cannot be re-encoded within the limits)
     */
    public function selectLogo(Course $course, ?string $logoId): Course {
        $layout = CertLayout::decode($course->getCertLayout());
        if ($logoId === null || $logoId === '') {
            if ($layout['logo_sha'] === null) {
                return $course;
            }
            $layout['logo_sha'] = null;
        } else {
            if (!CertLogoLibrary::isId($logoId)) {
                throw new \InvalidArgumentException('logo_not_found');
            }
            try {
                // ZC9: a logo stored before 5.17.0 is re-encoded for the course (no EXIF/GPS on
                // the certificates and the verify page). Kept under the library id, which the
                // layout, the choice in the settings, the course count and the reset on deletion
                // all use; a course's existing copy is left as it is (storeAsset()).
                $logo = $this->logoLibrary->courseCopy($logoId);
            } catch (NotFoundException $e) {
                throw new \InvalidArgumentException('logo_not_found');
            }
            $this->storeAsset((int)$course->getId(), $logoId, $logo['bytes'], $logo['mime']);
            $layout['logo_sha'] = $logoId;
        }
        $course->setCertLayout(CertLayout::encode($layout));
        $course->setUpdatedAt($this->timeFactory->getTime());
        /** @var Course $course */
        $course = $this->courseMapper->update($course);
        return $course;
    }

    /**
     * GitHub #78: how many courses use the library logo for their next certificates.
     */
    public function countCoursesUsingLogo(string $logoId): int {
        return count($this->coursesUsing('logo_sha', $logoId));
    }

    /**
     * GitHub #78: a library logo was deleted. Courses that used it fall back to the Theming logo for
     * the certificates they issue from now on; certificates already issued keep their copy.
     *
     * @return int number of courses changed
     */
    public function resetLogo(string $logoId): int {
        $changed = 0;
        foreach ($this->coursesUsing('logo_sha', $logoId) as $course) {
            $layout = CertLayout::decode($course->getCertLayout());
            $layout['logo_sha'] = null;
            $course->setCertLayout(CertLayout::encode($layout));
            $course->setUpdatedAt($this->timeFactory->getTime());
            $this->courseMapper->update($course);
            $changed++;
        }
        return $changed;
    }

    /**
     * GitHub #93: use a background on the course's certificates. $choice is the id of a library
     * background, 'builtin:<id>' for one that ships with the app, or '' for none; $intensity how
     * strongly it shows (10–100 in steps of 10). A library background is copied into the course's
     * certificate images now, so a certificate issued with it keeps it whatever happens to the
     * library afterwards.
     *
     * The caller has checked that the user manages the course.
     *
     * @throws \InvalidArgumentException background_not_found, invalid_intensity
     */
    public function selectBackground(Course $course, string $choice, int $intensity): Course {
        if (!CertLayout::isValidIntensity($intensity)) {
            throw new \InvalidArgumentException('invalid_intensity');
        }
        $layout = CertLayout::decode($course->getCertLayout());
        $layout['background_sha'] = null;
        $layout['background_builtin'] = null;
        if (str_starts_with($choice, 'builtin:')) {
            $builtin = CertLayout::cleanBuiltin(substr($choice, 8));
            if ($builtin === null) {
                throw new \InvalidArgumentException('background_not_found');
            }
            $layout['background_builtin'] = $builtin;
        } elseif ($choice !== '') {
            if (!CertBackgroundLibrary::isId($choice)) {
                throw new \InvalidArgumentException('background_not_found');
            }
            try {
                $background = $this->backgroundLibrary->content($choice);
            } catch (NotFoundException $e) {
                throw new \InvalidArgumentException('background_not_found');
            }
            $this->storeAsset((int)$course->getId(), $choice, $background['bytes'], $background['mime']);
            $layout['background_sha'] = $choice;
        }
        $layout['background_intensity'] = $intensity;
        $encoded = CertLayout::encode($layout);
        if ($encoded === $course->getCertLayout()) {
            return $course;
        }
        $course->setCertLayout($encoded);
        $course->setUpdatedAt($this->timeFactory->getTime());
        /** @var Course $course */
        $course = $this->courseMapper->update($course);
        return $course;
    }

    /**
     * GitHub #93: how many courses use the library background for their next certificates.
     */
    public function countCoursesUsingBackground(string $backgroundId): int {
        return count($this->coursesUsing('background_sha', $backgroundId));
    }

    /**
     * GitHub #93: a library background was deleted. Courses that used it issue their next
     * certificates without background; certificates already issued keep their copy.
     *
     * @return int number of courses changed
     */
    public function resetBackground(string $backgroundId): int {
        $changed = 0;
        foreach ($this->coursesUsing('background_sha', $backgroundId) as $course) {
            $layout = CertLayout::decode($course->getCertLayout());
            $layout['background_sha'] = null;
            $course->setCertLayout(CertLayout::encode($layout));
            $course->setUpdatedAt($this->timeFactory->getTime());
            $this->courseMapper->update($course);
            $changed++;
        }
        return $changed;
    }

    /**
     * Courses whose layout names the library image under $key (logo_sha, background_sha). The
     * sha256 is 64 hex characters, so a LIKE on it cannot match anything else in the JSON; the
     * decoded layout confirms it is the right key.
     *
     * @param 'logo_sha'|'background_sha' $key
     * @return list<Course>
     */
    private function coursesUsing(string $key, string $sha): array {
        if (!CertImageLibrary::isId($sha)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('learning_courses')
            ->where($qb->expr()->like('cert_layout', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($sha) . '%')));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        $courses = [];
        foreach (array_column($rows, 'id') as $id) {
            try {
                $course = $this->courseMapper->findById((int)$id);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                continue;
            }
            if (CertLayout::decode($course->getCertLayout())[$key] === $sha) {
                $courses[] = $course;
            }
        }
        return $courses;
    }

    /**
     * What a certificate issued now stores as its layout (learning_certificates.layout_json): the
     * course's layout of this moment and, when the course shows the Theming logo, a copy of that
     * logo (GitHub #78). NULL for the standard layout when there is nothing to copy, the row shape
     * certificates had before.
     */
    public function snapshotForIssuance(Course $course): ?string {
        $themingSha = null;
        if (CertLayout::decode($course->getCertLayout())['logo_sha'] === null) {
            $logo = $this->themingLogo->current();
            if ($logo !== null) {
                $themingSha = hash('sha256', $logo['bytes']);
                try {
                    $this->storeAsset((int)$course->getId(), $themingSha, $logo['bytes'], $logo['mime']);
                } catch (\Throwable $e) {
                    // Best effort: without the copy the certificate shows the live Theming logo.
                    $themingSha = null;
                }
            }
        }
        return CertLayout::snapshot($course->getCertLayout(), $themingSha);
    }

    /**
     * GitHub #78: the logo a certificate was issued with, as a data URL for the public verify page:
     * the library logo, else its copy of the Theming logo; null when it has neither (every
     * certificate before 5.14.0 included), and the page shows the live Theming logo.
     */
    public function logoForCertificate(Certificate $cert): ?string {
        $json = $cert->getLayoutJson();
        if ($json === null || $json === '') {
            return null;
        }
        return $this->issuedLogo(CertLayout::decode($json), (int)$cert->getCourseId())['logo'];
    }

    /**
     * The logo a layout names: the library logo (library: true), else the copy of the Theming logo
     * kept with a certificate, else none (the live Theming logo).
     *
     * @param array<string, mixed> $layout decoded
     * @return array{logo: ?string, library: bool}
     */
    private function issuedLogo(array $layout, int $courseId): array {
        if (is_string($layout['logo_sha'] ?? null)) {
            $url = $this->dataUrl($courseId, $layout['logo_sha']);
            if ($url !== null) {
                return ['logo' => $url, 'library' => true];
            }
        }
        if (is_string($layout['theming_logo_sha'] ?? null)) {
            return ['logo' => $this->dataUrl($courseId, $layout['theming_logo_sha']), 'library' => false];
        }
        return ['logo' => null, 'library' => false];
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
     * The course's current presentation, for the preview in the course settings, with the names the
     * next certificate would be issued in (GitHub #77): issuer_name is what it gets now,
     * issuer_name_fallback what an empty field would give (operator details, then Theming).
     *
     * @return array<string, mixed>
     */
    public function presentationForCourse(Course $course): array {
        $presentation = $this->presentation(CertLayout::decode($course->getCertLayout()), (int)$course->getId());
        $presentation['issuer_name'] = $this->issuerName->forCourse($course);
        $presentation['issuer_name_fallback'] = $this->issuerName->fallback();
        return $presentation;
    }

    /**
     * @param array<string, mixed> $layout decoded
     * @return array<string, mixed>
     */
    private function presentation(array $layout, int $courseId): array {
        $logo = $this->issuedLogo($layout, $courseId);
        return [
            'intro' => $layout['intro'],
            'show' => $layout['show'],
            'signer_name' => $layout['signer_name'],
            'signer_role' => $layout['signer_role'],
            'stamp' => $layout['stamp_sha'] !== null ? $this->dataUrl($courseId, $layout['stamp_sha']) : null,
            'signature' => $layout['signature_sha'] !== null ? $this->dataUrl($courseId, $layout['signature_sha']) : null,
            // GitHub #78: the library logo copied for this course, else the certificate's copy of the
            // Theming logo; null = the live Theming logo. logo_library: the dark-mode card puts only
            // a library logo on white (the Theming logo is made for the instance's themes).
            'logo' => $logo['logo'],
            'logo_library' => $logo['library'],
            // GitHub #93: a library background as a data URL (its copy for this course), or the id of a
            // built-in one; both null = none. A copy that is gone shows no background rather than failing.
            'background' => $layout['background_sha'] !== null ? $this->dataUrl($courseId, $layout['background_sha']) : null,
            'background_builtin' => $layout['background_builtin'],
            'background_intensity' => $layout['background_intensity'],
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

    private function storeAsset(int $courseId, string $sha, string $bytes, string $mime): void {
        if ($this->assetExists($courseId, $sha)) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_cert_assets')
            ->values([
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'sha256' => $qb->createNamedParameter($sha),
                'mime' => $qb->createNamedParameter($mime),
                'byte_size' => $qb->createNamedParameter(strlen($bytes), IQueryBuilder::PARAM_INT),
                'data_b64' => $qb->createNamedParameter(base64_encode($bytes)),
                'created_at' => $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
            ]);
        // GitHub #78 (review): in its own (nested) transaction. Issuance calls this inside the outer
        // transaction of PassCriteriaService::evaluate(); on PostgreSQL a failed INSERT aborts the
        // whole transaction (25P02) unless it ran in a savepoint that is rolled back, and the
        // certificate INSERT after it would then fail as well.
        $this->db->beginTransaction();
        try {
            $qb->executeStatement();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            // Two uploads (or issuances) of the same file at once: the other one stored it.
            if (!($e instanceof \OCP\DB\Exception) || $e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
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
     * Delete the images nobody uses any more: not the course's stamp, signature, logo or background
     * (GitHub #93), and in no
     * certificate of the course. Run by CertAssetCleanupJob, never inline on a change.
     *
     * Grace period: only courses whose last change (importImage/removeImage set updated_at) is at
     * least $graceSeconds old. An issuance reads the course layout at its start and inserts the
     * certificate with that layout's sha later in the same request; deleting the image the moment
     * the instructor swaps or removes it could drop the bytes of a certificate being inserted at
     * that moment, which would then show without its stamp for good. No request lives an hour.
     *
     * GitHub #78: an image stored less than $graceSeconds ago is spared as well. Issuance copies the
     * Theming logo without changing the course, so the course's updated_at does not cover that copy
     * between storing it and inserting its certificate.
     *
     * The sha256 is 64 hex characters, so a LIKE on it cannot match anything else in the JSON.
     *
     * @return int number of images deleted
     */
    public function sweepUnreferencedAssets(int $graceSeconds = 3600): int {
        $cutoff = $this->timeFactory->getTime() - $graceSeconds;
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.id', 'a.course_id', 'a.sha256', 'c.cert_layout')
            ->from('learning_cert_assets', 'a')
            ->innerJoin('a', 'learning_courses', 'c', $qb->expr()->eq('c.id', 'a.course_id'))
            ->where($qb->expr()->lt('c.updated_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('a.created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $deleted = 0;
        foreach ($rows as $row) {
            $sha = (string)$row['sha256'];
            $courseId = (int)$row['course_id'];
            $layout = CertLayout::decode(is_string($row['cert_layout'] ?? null) ? $row['cert_layout'] : null);
            if ($layout['stamp_sha'] === $sha || $layout['signature_sha'] === $sha || $layout['logo_sha'] === $sha
                || $layout['background_sha'] === $sha) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('learning_certificates')
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->like('layout_json', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($sha) . '%')))
                ->setMaxResults(1);
            $used = $qb->executeQuery();
            $inUse = $used->fetchOne() !== false;
            $used->closeCursor();
            if ($inUse) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_cert_assets')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
            $deleted += $qb->executeStatement();
        }
        return $deleted;
    }
}
