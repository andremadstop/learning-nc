<?php
// route-security-inventory.php — print what Nextcloud's security middlewares see on every
// controller method of an app tree (PublicPage, NoCSRFRequired, NoAdminRequired, brute-force
// actions, rate limits, ...), annotation and attribute form joined (GitHub #65).
//
// Usage (inside a Nextcloud image, e.g. via scripts/route-security-inventory.sh):
//   php route-security-inventory.php <app-dir> <ocp-dir> [<helper-file>]
// <app-dir>     the app/ directory of the tree to scan (its lib/ is autoloaded as OCA\Learning)
// <ocp-dir>     Nextcloud's lib/public (autoloaded as OCP)
// <helper-file> RouteSecurityInventory.php to use; default <app-dir>/tests/Support/… — pass the
//               current one to scan an older tree that does not have it.
//
// When Nextcloud's own server code is next to <ocp-dir> (the Nextcloud image), every entry is also
// checked against the real OC\AppFramework\Utility\ControllerMethodReflector, read the way the
// middlewares read it; any disagreement fails the run. So the inventory is not only the helper's
// opinion: a helper that misread an annotation or attribute would misread both trees alike and
// still compare "identical", the real reflector would not.
declare(strict_types=1);

[$self, $appDir, $ocpDir] = array_pad($argv, 3, null);
$helper = $argv[3] ?? ($appDir . '/tests/Support/RouteSecurityInventory.php');
if ($appDir === null || $ocpDir === null || !is_dir($appDir . '/lib/Controller') || !is_dir($ocpDir)) {
	fwrite(STDERR, "usage: php route-security-inventory.php <app-dir> <ocp-dir> [<helper-file>]\n");
	exit(2);
}
spl_autoload_register(static function (string $class) use ($appDir, $ocpDir): void {
	foreach (['OCA\\Learning\\' => $appDir . '/lib/', 'OCP\\' => $ocpDir . '/'] as $prefix => $base) {
		if (strncmp($class, $prefix, strlen($prefix)) === 0) {
			$path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require_once $path;
			}
			return;
		}
	}
});
require_once $helper;
$ncRoot = dirname($ocpDir, 2);
$crossCheck = is_file($ncRoot . '/lib/composer/autoload.php') && is_file($ncRoot . '/3rdparty/autoload.php');
if ($crossCheck) {
	require_once $ncRoot . '/3rdparty/autoload.php';
	require_once $ncRoot . '/lib/composer/autoload.php';
}

$inventory = \OCA\Learning\Tests\Support\RouteSecurityInventory::scan($appDir . '/lib/Controller');
if (count($inventory) < 40) {
	fwrite(STDERR, 'route-security-inventory: only ' . count($inventory) . " controller methods found\n");
	exit(1);
}
if ($crossCheck) {
	$ns = 'OCP\\AppFramework\\Http\\Attribute\\';
	$disagree = [];
	foreach (\OCA\Learning\Tests\Support\RouteSecurityInventory::controllerMethods($appDir . '/lib/Controller') as $key => $m) {
		$r = new \OC\AppFramework\Utility\ControllerMethodReflector(new \Psr\Log\NullLogger());
		$r->reflect($m->class, $m->getName());
		$real = [];
		foreach (\OCA\Learning\Tests\Support\RouteSecurityInventory::FLAGS as $flag => [$annotation, $attribute]) {
			$has = $attribute !== null
				? $r->hasAnnotationOrAttribute($annotation, $ns . $attribute)
				: $r->hasAnnotation($annotation);
			if ($has) {
				$real[$flag] = true;
			}
		}
		// BruteForceMiddleware::beforeController
		if ($r->hasAnnotation('BruteForceProtection')) {
			$real['BruteForceProtection'] = [$r->getAnnotationParameter('BruteForceProtection', 'action')];
		} else {
			$actions = [];
			foreach ($m->getAttributes(\OCP\AppFramework\Http\Attribute\BruteForceProtection::class) as $a) {
				$actions[] = $a->newInstance()->getAction();
			}
			if ($actions !== []) {
				sort($actions);
				$real['BruteForceProtection'] = $actions;
			}
		}
		// RateLimitingMiddleware::readLimitFromAnnotationOrAttribute (without ratelimit_overwrite)
		foreach (['user' => ['UserRateThrottle', \OCP\AppFramework\Http\Attribute\UserRateLimit::class],
			'anon' => ['AnonRateThrottle', \OCP\AppFramework\Http\Attribute\AnonRateLimit::class]] as $kind => [$annotation, $class]) {
			$limit = $r->getAnnotationParameter($annotation, 'limit');
			$period = $r->getAnnotationParameter($annotation, 'period');
			if ($limit !== '' && $period !== '') {
				$real[$kind . 'RateLimit'] = [(int)$limit, (int)$period];
			} elseif (($a = current($m->getAttributes($class, \ReflectionAttribute::IS_INSTANCEOF))) !== false) {
				$o = $a->newInstance();
				$real[$kind . 'RateLimit'] = [$o->getLimit(), $o->getPeriod()];
			}
		}
		ksort($real);
		if ($real !== $inventory[$key]) {
			$disagree[] = $key . ': helper ' . json_encode($inventory[$key]) . ', Nextcloud ' . json_encode($real);
		}
	}
	if ($disagree !== []) {
		fwrite(STDERR, "route-security-inventory: the helper disagrees with Nextcloud's ControllerMethodReflector:\n  " . implode("\n  ", $disagree) . "\n");
		exit(1);
	}
	fwrite(STDERR, 'route-security-inventory: ' . count($inventory) . " methods, Nextcloud's ControllerMethodReflector agrees\n");
}
echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
