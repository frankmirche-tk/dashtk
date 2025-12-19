<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DefaultController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('base.html.twig');
    }

    // Catch-All fürs SPA (alles außer /api, /build, Symfony intern)
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
