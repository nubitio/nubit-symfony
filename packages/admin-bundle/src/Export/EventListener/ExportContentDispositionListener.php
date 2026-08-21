<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export\EventListener;

use Nubit\AdminBundle\Export\XlsxEncoder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API Platform sets Content-Type from the negotiated format but never
 * Content-Disposition — without it browsers try to render the XLSX bytes
 * inline instead of downloading them.
 */
final class ExportContentDispositionListener implements EventSubscriberInterface
{
    private const array FORMAT_EXTENSIONS = [
        XlsxEncoder::FORMAT => 'xlsx',
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // `_format` is read with get(), not getString(): API Platform routes
        // declare an optional `{._format}` suffix, so a request that omits it
        // leaves the attribute present but null. getString() only falls back to
        // its default for a *missing* key and throws on a null one, which turned
        // every plain collection request into a 500 once this listener existed.
        $format = $request->attributes->get('_format');
        $extension = is_string($format) ? self::FORMAT_EXTENSIONS[$format] ?? null : null;
        if ($extension === null) {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->has('Content-Disposition')) {
            return;
        }

        $resourceClass = $request->attributes->get('_api_resource_class');
        $shortName = is_string($resourceClass) && $resourceClass !== '' ? $this->shortName($resourceClass) : 'export';
        $filename = sprintf('%s-%s.%s', $this->slug($shortName), date('Y-m-d'), $extension);

        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
    }

    private function shortName(string $class): string
    {
        $segments = explode('\\', $class);

        return end($segments);
    }

    private function slug(string $value): string
    {
        $snake = (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value);

        return strtolower($snake);
    }
}
