<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\OperatorInfo;
use OCA\Learning\Service\ThemingLegalLinkSync;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Operator details for the imprint and privacy statement (audit 2026-11): entered by an admin
 * instead of the app author's company shipped in privacy-info.json.
 */
class LegalOperatorController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private OperatorInfo $operatorInfo,
        private ThemingLegalLinkSync $legalLinks,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Admin form: the stored values, also an incomplete entry.
     */
    public function get(): DataResponse {
        return new DataResponse([
            'operator' => $this->operatorInfo->load(),
            'configured' => $this->operatorInfo->isConfigured(),
        ]);
    }

    /**
     * All fields empty removes the operator. A partial or invalid entry stores nothing and names the
     * fields (field => 'required' | 'invalid' | 'too_long').
     */
    public function save(
        string $name = '',
        string $address = '',
        string $register = '',
        string $email = '',
        string $content_responsible = '',
    ): DataResponse {
        $values = OperatorInfo::normalize([
            'name' => $name,
            'address' => $address,
            'register' => $register,
            'email' => $email,
            'content_responsible' => $content_responsible,
        ]);
        $errors = OperatorInfo::validate($values);
        if ($errors !== []) {
            return new DataResponse(['error' => 'invalid_operator', 'fields' => $errors], Http::STATUS_BAD_REQUEST);
        }
        $wasConfigured = $this->operatorInfo->isConfigured();
        $this->operatorInfo->save($values);
        // The Nextcloud footer links follow the operator entry when it becomes complete (they used
        // to be synced on every request, audit #206). Only then: a later save, e.g. a typo fix,
        // must not refill a link the admin has cleared on purpose since. The details are stored
        // either way; a failing theming write only costs the footer link and is logged.
        if (!$wasConfigured && $this->operatorInfo->isConfigured()) {
            try {
                $this->legalLinks->sync();
            } catch (\Throwable $e) {
                $this->logger->warning('Learning: legal link sync failed: ' . $e->getMessage(), ['app' => 'learning', 'exception' => $e]);
            }
        }

        return new DataResponse([
            'operator' => $this->operatorInfo->load(),
            'configured' => $this->operatorInfo->isConfigured(),
        ]);
    }

    /**
     * The public operator fields for the privacy section of the personal settings (the public
     * pages get them server-rendered); null while no complete entry exists.
     *
     * @NoAdminRequired
     */
    public function show(): DataResponse {
        return new DataResponse(['operator' => $this->operatorInfo->publicView()]);
    }
}
