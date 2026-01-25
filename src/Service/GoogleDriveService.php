<?php

namespace App\Service;

use Google\Client;
use Google\Service\Drive;

final class GoogleDriveService
{
    private Drive $drive;

    public function __construct(string $credentialsPath)
    {
        if (!is_file($credentialsPath)) {
            throw new \RuntimeException(sprintf('Google Drive credentials file not found: %s', $credentialsPath));
        }

        $client = new Client();
        $client->setApplicationName('DashTK');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Drive::DRIVE_READONLY]);

        $this->drive = new Drive($client);
    }

    /**
     * Listet Dateien in einem Ordner (PDF/MP4/etc.) inkl. webViewLink.
     *
     * @return array<int, array{id:string,name:string,webViewLink:?string,mimeType:string}>
     */
    public function listFilesInFolder(string $folderId, int $limit = 200): array
    {
        $response = $this->drive->files->listFiles([
            'q' => sprintf("'%s' in parents and trashed = false", $folderId),
            'fields' => 'files(id,name,webViewLink,mimeType)',
            'orderBy' => 'name',
            'pageSize' => $limit,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        $files = [];
        foreach ($response->getFiles() as $file) {
            $files[] = [
                'id' => (string) $file->getId(),
                'name' => (string) $file->getName(),
                'mimeType' => (string) $file->getMimeType(),
                'webViewLink' => $file->getWebViewLink(), // kann null sein
            ];
        }

        return $files;
    }

    /**
     * Listet Unterordner eines Ordners.
     *
     * @return array<int, array{id:string,name:string,mimeType:string}>
     */
    public function listFoldersInFolder(string $folderId, int $limit = 200): array
    {
        $response = $this->drive->files->listFiles([
            'q' => sprintf("'%s' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'", $folderId),
            'fields' => 'files(id,name,mimeType)',
            'orderBy' => 'name',
            'pageSize' => $limit,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        $folders = [];
        foreach ($response->getFiles() as $file) {
            $folders[] = [
                'id' => (string) $file->getId(),
                'name' => (string) $file->getName(),
                'mimeType' => (string) $file->getMimeType(),
            ];
        }

        return $folders;
    }
}
