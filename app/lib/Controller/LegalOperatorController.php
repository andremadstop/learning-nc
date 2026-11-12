<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\OperatorInfo;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Operator details for the imprint and privacy statement (audit 2026-11): entered by an admin
 * instead of the app author's company shipped in privacy-info.json.
 */
class LegalOperatorController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private OperatorInfo $operatorInfo,
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
        $this->operatorInfo->save($values);

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
