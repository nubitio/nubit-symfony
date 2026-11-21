<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Security;

use LogicException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The application's say over a route the bundle registers.
 *
 * API Platform operations take a `security:` expression; the bundle's own routes (media upload,
 * audit trail, documents, imports, queued exports) are plain controllers, so on their own they only
 * ask for the firewall's ROLE_USER. Each of those modules has a `security` key under its
 * `nubit_admin` config that takes a Symfony expression, evaluated here, so an application can say
 * who may use it with the permissions it already has:
 *
 *     nubit_admin:
 *         media:
 *             upload_security: "is_granted('catalog.edit') or is_granted('settings.edit')"
 *
 * The expression sees what Symfony's expression voter gives (`user`, `is_granted()`, `role_names`…)
 * plus `subject`: an array describing what is being asked for (`resource` and `id` for the audit
 * trail and documents, for instance). A null or empty expression changes nothing.
 */
final readonly class RouteGuard
{
    public function __construct(
        private AuthorizationCheckerInterface $checker,
    ) {}

    /**
     * @param array<string, mixed> $subject what the route is about, readable in the expression as `subject`
     *
     * @throws AccessDeniedHttpException when the expression is not satisfied
     */
    public function assert(?string $expression, array $subject = []): void
    {
        if (null === $expression || '' === trim($expression)) {
            return;
        }

        if (!$this->checker->isGranted(new Expression($expression), $subject)) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Refuses to compile a configured expression nothing could evaluate, instead of failing on the
     * first request in production.
     */
    public static function assertAvailable(?string $expression, string $configKey): void
    {
        if (null === $expression || '' === trim($expression) || class_exists(ExpressionLanguage::class)) {
            return;
        }

        throw new LogicException(sprintf(
            '%s is an expression, which needs symfony/expression-language. Install it, or remove the key.',
            $configKey,
        ));
    }
}
