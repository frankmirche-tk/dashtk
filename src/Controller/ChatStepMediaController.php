<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SupportSolutionStepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ChatStepMediaController extends AbstractController
{
    private const ALLOWED_MIME = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'application/pdf',
        'video/mp4',
    ];

    #[Route('/api/support_solution_steps/{id}/media', name: 'step_media_upload', methods: ['POST'])]
    public function upload(
        string $id,
        Request $request,
        SupportSolutionStepRepository $repo,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $step = $repo->find($id);
        if (!$step) {
            return new JsonResponse(['error' => 'Step not found'], 404);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded (field name must be "file")'], 400);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse([
                'error' => 'Upload failed',
                'detail' => $file->getErrorMessage(),
            ], 422);
        }

        // ✅ ALLES VOR move() lesen
        $origName = $file->getClientOriginalName() ?: 'upload';
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $size = $file->getSize() ?? 0;

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return new JsonResponse([
                'error' => 'File type not allowed',
                'mime' => $mime,
            ], 422);
        }

        $solutionId = $step->getSolution()?->getId();
        if (!$solutionId) {
            return new JsonResponse(['error' => 'Step has no solution'], 422);
        }

        $publicDir = $this->getParameter('kernel.project_dir') . '/public';
        $targetRelDir = sprintf('guides/solution-%s/step-%s', $solutionId, $step->getId());
        $targetAbsDir = $publicDir . '/' . $targetRelDir;

        if (!is_dir($targetAbsDir) && !mkdir($targetAbsDir, 0775, true) && !is_dir($targetAbsDir)) {
            return new JsonResponse(['error' => 'Cannot create directory'], 500);
        }

        // alte Datei ersetzen
        if ($step->getMediaPath()) {
            $old = $publicDir . '/' . ltrim($step->getMediaPath(), '/');
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $safeBase = $slugger->slug(pathinfo($origName, PATHINFO_FILENAME))->lower();
        $ext = $file->guessExtension()
            ?: pathinfo($origName, PATHINFO_EXTENSION)
                ?: 'bin';

        $finalName = sprintf('%s.%s', $safeBase ?: 'file', strtolower($ext));

        // ✅ move NACH Metadaten
        $file->move($targetAbsDir, $finalName);

        $step->setMediaPath($targetRelDir . '/' . $finalName);
        $step->setMediaOriginalName($origName);
        $step->setMediaMimeType($mime);
        $step->setMediaSize((int) $size);
        $step->setMediaUpdatedAt(new \DateTimeImmutable());

        $em->flush();

        return new JsonResponse([
            'id' => $step->getId(),
            'mediaPath' => $step->getMediaPath(),
            'mediaUrl' => $step->getMediaUrl(),
            'mediaMimeType' => $step->getMediaMimeType(),
            'mediaOriginalName' => $step->getMediaOriginalName(),
            'mediaSize' => $step->getMediaSize(),
        ]);
    }

    #[Route('/api/support_solution_steps/{id}/media', name: 'step_media_delete', methods: ['DELETE'])]
    public function delete(
        string $id,
        SupportSolutionStepRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        $step = $repo->find($id);
        if (!$step) {
            return new JsonResponse(['error' => 'Step not found'], 404);
        }

        $publicDir = $this->getParameter('kernel.project_dir') . '/public';

        if ($step->getMediaPath()) {
            $abs = $publicDir . '/' . ltrim($step->getMediaPath(), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        $step->clearMedia();
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
