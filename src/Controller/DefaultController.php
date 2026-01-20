<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DefaultController
 *
 * Purpose:
 * - Provides the main entry point for the web frontend.
 * - Renders the single base Twig template that bootstraps the UI (e.g. a SPA).
 *
 * Routes:
 * - GET /              -> index(): initial page load / homepage
 * - GET /{route}       -> spa(): catch-all fallback for client-side routing (SPA)
 *
 * SPA fallback behavior:
 * - The catch-all route serves the same base template for arbitrary paths so that
 *   the frontend router (Vue/React/etc.) can handle navigation client-side.
 * - Certain paths are explicitly excluded to avoid intercepting:
 *   - API routes (/api…)
 *   - Webpack/Vite build assets (/build…)
 *   - Symfony internal/debug tooling (/_profiler, /_wdt)
 *   - common static endpoints (favicon.ico, robots.txt)
 *
 * Notes:
 * - Both actions return the same template (base.html.twig) on purpose.
 * - If you later add more server-rendered pages, ensure the catch-all route
 *   does not shadow them (adjust the requirements regex accordingly).
 */
final class DefaultController extends AbstractController
{
    /**
     * Homepage / initial SPA shell.
     *
     * This route is typically hit on the first load of the application.
     * The template should include the frontend assets and the DOM mount node
     * for the SPA.
     *
     * @return Response Rendered base template response.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('base.html.twig');
    }

    /**
     * Catch-all route for SPA client-side navigation.
     *
     * This action renders the same SPA shell as the homepage, but only for routes
     * that are NOT part of the backend (API), asset pipeline, or Symfony internals.
     *
     * The route parameter name is "route" by convention here and can contain slashes
     * depending on Symfony's matching rules. The requirements regex prevents this
     * route from matching excluded prefixes/endpoints.
     *
     * @return Response Rendered base template response.
     */
    #[Route(
        '/{route}',
        name: 'spa_fallback',
        requirements: ['route' => '^(?!api|build|_profiler|_wdt|favicon\.ico|robots\.txt).*$'],
        methods: ['GET']
    )]
    public function spa(): Response
    {
        return $this->render('base.html.twig');
    }
}
