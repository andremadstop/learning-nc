<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm\Provider;

use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmResult;

/**
 * One backend (spec 2.1/2.2). Replaces the 5.5 OCA\Learning\Service\LlmProviderInterface
 * (generateText), which Task B4 removed together with the 5.5 transport.
 *
 * Contract for every implementation:
 * - exactly one HTTP request per call, through LlmHttpClient — never a retry;
 * - target, model and key come from the given connection snapshot, nothing is read live;
 * - no logging of prompt, answer or provider error body;
 * - the answer text is normalised (LlmOutputNormalizer); no text left → category `empty`;
 * - every failure is an LlmProviderException with a category.
 * Only LlmGateway calls complete() (architecture test in B3).
 */
interface LlmProviderInterface {
    /**
     * @throws LlmProviderException
     */
    public function complete(LlmConnection $connection, LlmRequest $request): LlmResult;
}
