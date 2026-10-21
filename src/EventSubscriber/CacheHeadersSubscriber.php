<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CacheHeadersSubscriber implements EventSubscriberInterface
{
    private const SHARED_MAX_AGE = 60;

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    // Browsers must not cache pages; shared caches (Varnish, CloudFront) may keep them for SHARED_MAX_AGE seconds.
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$event->getRequest()->isMethodCacheable() || !$response->isSuccessful()) {
            return;
        }

        $response->setPublic();
        $response->setMaxAge(0);
        $response->setSharedMaxAge(self::SHARED_MAX_AGE);
    }
}
