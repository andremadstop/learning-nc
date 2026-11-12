<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\PublicAiNotice;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {
    private const VIDEO_FRAME_HOSTS = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];
    // www.youtube.com serves the IFrame Player API (iframe_api + www-widgetapi.js); the player
    // frame itself stays on youtube-nocookie.com.
    private const VIDEO_SDK_HOSTS = ['https://www.youtube.com', 'https://player.vimeo.com'];

    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private ConnectionStore $connectionStore,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        $response = new TemplateResponse('learning', 'index');
        // Vimeo / YouTube course videos play in the provider's player once the learner consented
        // (VideoConsentOverlay). Nothing loads before that; the policy only has to allow the player
        // frames (YouTube only from its privacy-enhanced host) and the provider player SDKs that
        // report the watch progress. Under NC's nonce + strict-dynamic the SDK scripts are already
        // trusted as inserted by our bundle; the script hosts cover browsers without strict-dynamic.
        $policy = new ContentSecurityPolicy();
        foreach (self::VIDEO_FRAME_HOSTS as $host) {
            $policy->addAllowedFrameDomain($host);
        }
        foreach (self::VIDEO_SDK_HOSTS as $host) {
            $policy->addAllowedScriptDomain($host);
        }
        $response->setContentSecurityPolicy($policy);
        return $response;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function privacy(): PublicTemplateResponse {
        return new PublicTemplateResponse('learning', 'privacy', [
            'appUrl' => $this->urlGenerator->linkToRoute('learning.page.index'),
            'imprintUrl' => $this->urlGenerator->linkToRoute('learning.page.impressum'),
            // who receives AI data right now, without any server address (public page)
            'aiNotice' => PublicAiNotice::describe($this->connectionStore->load()),
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
