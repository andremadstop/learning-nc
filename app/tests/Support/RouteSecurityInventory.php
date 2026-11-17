<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Support;

/**
 * What Nextcloud's security middlewares see on every public controller method (GitHub #65).
 *
 * Nextcloud reads each of these markers either as a PHP attribute or, deprecated, as a PHPDoc
 * annotation (ControllerMethodReflector::reflect(): every docblock line "* @Name…" with an
 * upper-case first letter, prose after the name included). The inventory joins both forms the way
 * the middlewares do, so it describes behaviour, not spelling: a route converted from annotation
 * to attribute must produce the identical entry. RouteSecurityInventoryTest holds the app against
 * tests/Support/route-security.json; scripts/route-security-inventory.php runs the same scan
 * against any checkout, which is how the file was taken from the annotation-era code.
 *
 * Plain PHP with Reflection only, no PHPUnit, so the script can load it next to another tree.
 */
final class RouteSecurityInventory {
    private const NS = 'OCP\\AppFramework\\Http\\Attribute\\';

    /**
     * Flag markers: entry key => [annotation name Nextcloud reads, attribute short name or null].
     * Names and pairs as in Nextcloud 33 lib/private (SecurityMiddleware, CORSMiddleware,
     * SessionMiddleware, PasswordConfirmationMiddleware, SameSiteCookieMiddleware,
     * TwoFactorMiddleware, FederatedShareMiddleware).
     */
    public const FLAGS = [
        'PublicPage' => ['PublicPage', 'PublicPage'],
        'NoCSRFRequired' => ['NoCSRFRequired', 'NoCSRFRequired'],
        'NoAdminRequired' => ['NoAdminRequired', 'NoAdminRequired'],
        'SubAdminRequired' => ['SubAdminRequired', 'SubAdminRequired'],
        'NoSubAdminRequired' => ['NoSubAdminRequired', null],
        'AuthorizedAdminSetting' => ['AuthorizedAdminSetting', 'AuthorizedAdminSetting'],
        'PasswordConfirmationRequired' => ['PasswordConfirmationRequired', 'PasswordConfirmationRequired'],
        'StrictCookieRequired' => ['StrictCookieRequired', 'StrictCookiesRequired'],
        'UseSession' => ['UseSession', 'UseSession'],
        'CORS' => ['CORS', 'CORS'],
        'ExAppRequired' => ['ExAppRequired', 'ExAppRequired'],
        'NoSameSiteCookieRequired' => ['NoSameSiteCookieRequired', null],
        'NoTwoFactorRequired' => ['NoTwoFactorRequired', null],
        'TwoFactorSetUpDoneRequired' => ['TwoFactorSetUpDoneRequired', null],
        'NoIncomingFederatedSharingRequired' => ['NoIncomingFederatedSharingRequired', null],
        'NoOutgoingFederatedSharingRequired' => ['NoOutgoingFederatedSharingRequired', null],
    ];

    /** Annotation names with an attribute replacement; the app must not use them as annotations. */
    public const DEPRECATED_ANNOTATIONS = [
        'PublicPage', 'NoCSRFRequired', 'NoAdminRequired', 'SubAdminRequired', 'AuthorizedAdminSetting',
        'PasswordConfirmationRequired', 'StrictCookieRequired', 'UseSession', 'CORS', 'ExAppRequired',
        'BruteForceProtection', 'UserRateThrottle', 'AnonRateThrottle',
    ];

    /**
     * Annotations exactly as ControllerMethodReflector::reflect() extracts them (lower-cased name
     * => raw text after the name).
     *
     * @return array<string, string>
     */
    public static function annotations(\ReflectionMethod $m): array {
        $doc = $m->getDocComment();
        if ($doc === false) {
            return [];
        }
        preg_match_all('/^\h+\*\h+@(?P<annotation>[A-Z]\w+)((?P<parameter>.*))?$/m', $doc, $matches);
        $out = [];
        foreach ($matches['annotation'] as $i => $name) {
            $out[strtolower($name)] = $matches['parameter'][$i];
        }
        return $out;
    }

    /**
     * "(action=foo, x=y)" => ['action' => 'foo', 'x' => 'y'], as the reflector splits it.
     *
     * @return array<string, string>
     */
    private static function annotationParams(string $raw): array {
        if (!str_starts_with($raw, '(') || !str_ends_with($raw, ')')) {
            return [];
        }
        $params = [];
        foreach (explode(',', str_replace(' ', '', substr($raw, 1, -1))) as $pair) {
            $kv = explode('=', $pair, 2);
            $params[$kv[0]] = $kv[1] ?? '';
        }
        return $params;
    }

    /**
     * Effective security markers of one method, in the form the middlewares act on.
     *
     * @return array<string, mixed>
     */
    public static function describe(\ReflectionMethod $m): array {
        $ann = self::annotations($m);
        $attrs = [];
        foreach ($m->getAttributes() as $a) {
            $attrs[$a->getName()][] = $a;
        }
        $entry = [];
        foreach (self::FLAGS as $key => [$annotation, $attribute]) {
            $byAttr = $attribute !== null && isset($attrs[self::NS . $attribute]);
            if ($byAttr || array_key_exists(strtolower($annotation), $ann)) {
                $entry[$key] = true;
            }
        }

        // BruteForceMiddleware: the annotation wins; only without it are the attributes read.
        if (array_key_exists('bruteforceprotection', $ann)) {
            $entry['BruteForceProtection'] = [self::annotationParams($ann['bruteforceprotection'])['action'] ?? ''];
        } elseif (isset($attrs[self::NS . 'BruteForceProtection'])) {
            $actions = [];
            foreach ($attrs[self::NS . 'BruteForceProtection'] as $a) {
                $args = $a->getArguments();
                $actions[] = (string)($args['action'] ?? $args[0] ?? '');
            }
            sort($actions);
            $entry['BruteForceProtection'] = $actions;
        }

        // RateLimitingMiddleware: an annotation with limit and period first, else the first attribute.
        foreach (['User' => 'user', 'Anon' => 'anon'] as $prefix => $kind) {
            $limit = null;
            $annName = strtolower($prefix . 'RateThrottle');
            $p = array_key_exists($annName, $ann) ? self::annotationParams($ann[$annName]) : [];
            if (($p['limit'] ?? '') !== '' && ($p['period'] ?? '') !== '') {
                $limit = [(int)$p['limit'], (int)$p['period']];
            } else {
                $found = $m->getAttributes(self::NS . $prefix . 'RateLimit', \ReflectionAttribute::IS_INSTANCEOF);
                if ($found !== []) {
                    $args = $found[0]->getArguments();
                    $limit = [(int)($args['limit'] ?? $args[0]), (int)($args['period'] ?? $args[1])];
                }
            }
            if ($limit !== null) {
                $entry[$kind . 'RateLimit'] = $limit;
            }
        }
        ksort($entry);
        return $entry;
    }

    /**
     * Every public method declared by a concrete controller in $controllerDir whose classes are
     * loadable under OCA\Learning\Controller.
     *
     * @return array<string, \ReflectionMethod> "FooController::bar" => method
     */
    public static function controllerMethods(string $controllerDir): array {
        $methods = [];
        $files = glob(rtrim($controllerDir, '/') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $class = 'OCA\\Learning\\Controller\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue; // traits and helpers without a class of that name
            }
            $ref = new \ReflectionClass($class);
            if (!$ref->isSubclassOf('OCP\\AppFramework\\Controller') || $ref->isAbstract()) {
                continue;
            }
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() === $class && !$m->isConstructor() && !$m->isStatic()) {
                    $methods[$ref->getShortName() . '::' . $m->getName()] = $m;
                }
            }
        }
        ksort($methods);
        return $methods;
    }

    /** @return array<string, array<string, mixed>> */
    public static function scan(string $controllerDir): array {
        $out = [];
        foreach (self::controllerMethods($controllerDir) as $key => $m) {
            $out[$key] = self::describe($m);
        }
        return $out;
    }
}
