<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Http\Middleware\ForceAppUrl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class CanonicalDomainRedirectTest extends TestCase
{
    public function test_www_root_redirects_to_the_public_website_on_the_canonical_domain(): void
    {
        $response = $this->runMiddleware('https://www.bscn.pt/');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://bscn.pt/', $response->headers->get('Location'));
    }

    public function test_www_preserves_the_login_path_and_query_string(): void
    {
        $response = $this->runMiddleware('https://www.bscn.pt/login?source=access-email');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://bscn.pt/login?source=access-email', $response->headers->get('Location'));
    }

    public function test_canonical_domain_continues_to_the_requested_page(): void
    {
        $response = $this->runMiddleware('https://bscn.pt/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('continued', $response->getContent());
    }

    private function runMiddleware(string $url): Response
    {
        config()->set('app.url', 'https://bscn.pt');

        return app(ForceAppUrl::class)->handle(
            Request::create($url, 'GET'),
            static fn (): Response => response('continued'),
        );
    }
}
