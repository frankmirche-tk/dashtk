<?php

namespace App\Controller;

use App\Service\GoogleDriveService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleDriveBrowserController
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly string $rootFolderId,
    ) {}

    #[Route('/api/forms/google-drive/folders', methods: ['GET'])]
    public function folders(): JsonResponse
    {
        $folders = $this->drive->listFoldersInFolder($this->rootFolderId);

        return new JsonResponse([
            'provider' => 'google_drive',
            'rootFolderId' => $this->rootFolderId,
            'count' => count($folders),
            'folders' => $folders,
        ]);
    }

    #[Route('/api/forms/google-drive', methods: ['GET'])]
    public function files(Request $request): JsonResponse
    {
        $folderId = trim((string) $request->query->get('folderId', ''));
        if ($folderId === '') {
            $folderId = $this->rootFolderId;
        }

        $files = $this->drive->listFilesInFolder($folderId);

        return new JsonResponse([
            'provider' => 'google_drive',
            'folderId' => $folderId,
            'count' => count($files),
            'files' => $files,
        ]);
    }
}
