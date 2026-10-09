<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use PHPUnit\Framework\TestCase;

/**
 * Task B3: there is one send path. Source-level checks over app/lib (comments stripped, so doc
 * text can neither hide nor fake a match):
 * - provider adapters' complete() is called only from LlmGateway;
 * - OCA\Learning\Service\Llm\Provider\LlmProviderInterface is implemented only in Llm/Provider/;
 * - the gateway does not know the adapters that are not selectable in 5.7.0.
 *
 * The 5.5 interface OCA\Learning\Service\LlmProviderInterface (generateText) is a different type
 * and is resolved as such; it goes away when the facades move to the gateway (Task B4).
 */
class ArchitectureTest extends TestCase {
    private const PROVIDER_INTERFACE = 'OCA\\Learning\\Service\\Llm\\Provider\\LlmProviderInterface';
    private const GATEWAY = 'Service/Llm/LlmGateway.php';

    /** @return array<string, string> relative path => code without comments */
    private static function libSources(): array {
        $root = dirname(__DIR__, 4) . '/lib';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $out[$rel] = self::stripComments((string)file_get_contents($file->getPathname()));
        }
        ksort($out);
        return $out;
    }

    private static function stripComments(string $source): string {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    /**
     * @param array<string, string> $sources
     * Instance calls only: the adapters' complete() is an instance method, while Nextcloud's static
     * SearchResult::complete() (unified search) is something else.
     *
     * @return list<string> files that call ->complete( (also ?->, and with whitespace)
     */
    public static function filesCallingComplete(array $sources): array {
        $hits = [];
        foreach ($sources as $rel => $code) {
            if (preg_match('/(?:->|\?->)\s*complete\s*\(/i', $code) === 1) {
                $hits[] = $rel;
            }
        }
        return $hits;
    }

    /**
     * Fully qualified names of every interface a class in $code implements, resolved against its
     * namespace and use statements.
     *
     * @return list<string>
     */
    public static function implementedInterfaces(string $code): array {
        $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $m) === 1 ? $m[1] : '';
        $uses = [];
        if (preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $um, PREG_SET_ORDER) > 0) {
            foreach ($um as $u) {
                $fq = ltrim($u[1], '\\');
                $alias = $u[2] ?? '';
                $alias = $alias !== '' ? $alias : substr($fq, (int)strrpos('\\' . $fq, '\\'));
                $uses[strtolower($alias)] = $fq;
            }
        }
        $result = [];
        if (preg_match_all('/\bimplements\s+([\w\\\\\s,]+?)\s*\{/i', $code, $im) > 0) {
            foreach ($im[1] as $list) {
                foreach (array_map('trim', explode(',', $list)) as $name) {
                    if ($name === '') {
                        continue;
                    }
                    if (str_starts_with($name, '\\')) {
                        $result[] = ltrim($name, '\\');
                        continue;
                    }
                    $first = explode('\\', $name)[0];
                    if (isset($uses[strtolower($first)])) {
                        $rest = substr($name, strlen($first));
                        $result[] = $uses[strtolower($first)] . $rest;
                        continue;
                    }
                    $result[] = ($namespace !== '' ? $namespace . '\\' : '') . $name;
                }
            }
        }
        return $result;
    }

    public function testOnlyTheGatewayCallsComplete(): void {
        $this->assertSame([self::GATEWAY], self::filesCallingComplete(self::libSources()));
    }

    public function testCompleteCallDetectionWouldCatchACallElsewhere(): void {
        $sources = self::libSources();
        $sources['Service/SomeFacade.php'] = '<?php $this->provider -> complete ($c, $r);';
        $sources['Service/Other.php'] = '<?php $p?->complete($c, $r);';
        $hits = self::filesCallingComplete($sources);
        sort($hits);
        $this->assertSame(['Service/Llm/LlmGateway.php', 'Service/Other.php', 'Service/SomeFacade.php'], $hits);
    }

    public function testTheProviderInterfaceIsImplementedOnlyInLlmProvider(): void {
        $implementers = [];
        foreach (self::libSources() as $rel => $code) {
            if (in_array(self::PROVIDER_INTERFACE, self::implementedInterfaces($code), true)) {
                $implementers[] = $rel;
            }
        }
        $this->assertNotEmpty($implementers);
        foreach ($implementers as $rel) {
            $this->assertStringStartsWith('Service/Llm/Provider/', $rel, "$rel implements the provider interface");
        }
    }

    public function testInterfaceResolutionDistinguishesTheLegacyInterface(): void {
        $legacy = "<?php\nnamespace OCA\\Learning\\Service;\nclass GeminiProvider implements LlmProviderInterface {\n}";
        $this->assertSame(['OCA\\Learning\\Service\\LlmProviderInterface'], self::implementedInterfaces($legacy));

        $aliased = "<?php\nnamespace OCA\\Learning\\Service;\nuse OCA\\Learning\\Service\\Llm\\Provider\\LlmProviderInterface as P;\n"
            . "class Sneaky implements \\Countable, P {\n}";
        $this->assertSame(['Countable', self::PROVIDER_INTERFACE], self::implementedInterfaces($aliased));

        $qualified = "<?php\nnamespace OCA\\Learning\\Service;\nclass Q implements Llm\\Provider\\LlmProviderInterface {\n}";
        $this->assertSame([self::PROVIDER_INTERFACE], self::implementedInterfaces($qualified));
    }

    public function testTheGatewayDoesNotKnowTheAdaptersOf580(): void {
        $gateway = self::libSources()[self::GATEWAY];
        $this->assertDoesNotMatchRegularExpression('/OpenAiCompatibleProvider|AnthropicProvider/', $gateway);
    }
}
