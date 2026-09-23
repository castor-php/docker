<?php

namespace App\Controller;

use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes to the Mercure hub the application is linked to, and shows what
 * it receives: open /mercure in a browser, then call /mercure/publish.
 */
#[AsController]
final class MercureController
{
    public const TOPIC = 'https://example.com/castor/demo';

    #[Route('/mercure', methods: ['GET'])]
    public function subscribe(HubInterface $hub): Response
    {
        $url = json_encode($hub->getPublicUrl() . '?topic=' . rawurlencode(self::TOPIC));

        return new Response(<<<HTML
            <!DOCTYPE html>
            <title>Mercure</title>
            <p>Subscribed to <code>{$hub->getPublicUrl()}</code>, waiting for updates…</p>
            <ul id="updates"></ul>
            <script>
                const source = new EventSource({$url});
                source.onmessage = (event) => {
                    const item = document.createElement('li');
                    item.textContent = JSON.parse(event.data).message;
                    document.getElementById('updates').append(item);
                };
            </script>
            HTML);
    }

    #[Route('/mercure/publish', methods: ['GET', 'POST'])]
    public function publish(HubInterface $hub, Request $request): JsonResponse
    {
        $message = $request->query->getString('message', 'Hello from ' . gethostname());

        $id = $hub->publish(new Update(self::TOPIC, json_encode(['message' => $message])));

        return new JsonResponse(['id' => $id, 'hub' => $hub->getUrl(), 'message' => $message]);
    }
}
