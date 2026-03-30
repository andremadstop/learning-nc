<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('learning', 'index');
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function privacy(): PublicTemplateResponse {
        return new PublicTemplateResponse('learning', 'privacy', [
            'appUrl' => $this->urlGenerator->linkToRoute('learning.page.index'),
            'imprintUrl' => $this->urlGenerator->linkToRoute('learning.page.impressum'),
        ]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function impressum(): PublicTemplateResponse {
        return new PublicTemplateResponse('learning', 'impressum', [
            'appUrl' => $this->urlGenerator->linkToRoute('learning.page.index'),
            'privacyUrl' => $this->urlGenerator->linkToRoute('learning.page.privacy'),
        ]);
    }
}
