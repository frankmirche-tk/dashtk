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

    public function getFileMeta(string $fileId): array
    {
        $file = $this->drive->files->get($fileId, [
            'fields' => 'id,name,mimeType,webViewLink',
            'supportsAllDrives' => true,
        ]);

        return [
            'id' => (string)$file->getId(),
            'name' => (string)$file->getName(),
            'mimeType' => (string)$file->getMimeType(),
            'webViewLink' => $file->getWebViewLink(),
        ];
    }

    public function downloadFileContent(string $fileId): string
    {
        $response = $this->drive->files->get($fileId, [
            'alt' => 'media',
            'supportsAllDrives' => true,
        ]);

        // Google API liefert meist PSR-7 Stream
        $body = $response->getBody();

        if (is_object($body) && method_exists($body, 'getContents')) {
            return (string)$body->getContents();
        }

        return (string)$body;
    }

    public function exportFile(string $fileId, string $mimeType = 'application/pdf'): string
    {
        $response = $this->drive->files->export($fileId, $mimeType, [
            'supportsAllDrives' => true,
        ]);

        $body = $response->getBody();

        if (is_object($body) && method_exists($body, 'getContents')) {
            return (string)$body->getContents();
        }

        return (string)$body;
    }

    /**
     * Lädt eine Datei (binary) als Stream in eine lokale Datei (z.B. Temp).
     * Gibt die Ziel-Pfad-Location zurück.
     */
    public function downloadFileToPath(string $fileId, string $targetPath): string
    {
        $response = $this->drive->files->get($fileId, [
            'alt' => 'media',
            'supportsAllDrives' => true,
        ]);

        $body = $response->getBody();

        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot open targetPath for writing: '.$targetPath);
        }

        try {
            if (is_object($body) && method_exists($body, 'read')) {
                // PSR-7 Stream
                while (!$body->eof()) {
                    fwrite($out, $body->read(1024 * 1024)); // 1MB chunks
                }
            } else {
                // Fallback
                fwrite($out, (string)$body);
            }
        } finally {
            fclose($out);
        }

        return $targetPath;
    }

    /**
     * Falls es KEIN echtes PDF ist (Google Doc/Sheet/etc), exportiert als PDF und streamt in Datei.
     */
    public function exportFileToPath(string $fileId, string $targetPath, string $mimeType = 'application/pdf'): string
    {
        $response = $this->drive->files->export($fileId, $mimeType, [
            'supportsAllDrives' => true,
        ]);

        $body = $response->getBody();

        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot open targetPath for writing: '.$targetPath);
        }

        try {
            if (is_object($body) && method_exists($body, 'read')) {
                while (!$body->eof()) {
                    fwrite($out, $body->read(1024 * 1024));
                }
            } else {
                fwrite($out, (string)$body);
            }
        } finally {
            fclose($out);
        }

        return $targetPath;
    }

}
