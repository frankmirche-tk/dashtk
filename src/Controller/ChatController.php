<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ExternalUrlInspector;
use App\Service\ResponseCode;
use App\Service\SupportChatService;
use App\Tracing\TraceContext;
use App\Tracing\TraceManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly SupportChatService $chatService,
        private readonly TraceManager $traceManager,
        private readonly LoggerInterface $logger,
        private readonly ExternalUrlInspector $externalUrlInspector,
    ) {}

    #[Route('/api/chat', name: 'app_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat');
        TraceContext::set($trace);

        // UI span
        $uiSpan  = (string) $request->headers->get('X-UI-Span', '');
        $uiAt    = (string) $request->headers->get('X-UI-At', '');
        $uiReqId = (string) $request->headers->get('X-UI-Req-Id', '');

        if ($uiSpan !== '') {
            $trace->span($uiSpan, static fn () => null, [
                'at' => $uiAt !== '' ? $uiAt : null,
                'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        // axios/http span from UI
        $uiHttpSpan = (string) $request->headers->get('X-UI-Http-Span', '');
        $uiHttpAt   = (string) $request->headers->get('X-UI-Http-At', '');

        if ($uiHttpSpan !== '') {
            $trace->span($uiHttpSpan, static fn () => null, [
                'at' => $uiHttpAt !== '' ? $uiHttpAt : null,
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
        }

        $routeName = (string) $request->attributes->get('_route', '');
        $trace->span('controller.ChatController::chat', static fn () => null, [
            'route' => $routeName,
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string) ($payload['sessionId'] ?? '');
            $message   = (string) ($payload['message'] ?? '');

            $dbOnly = $payload['dbOnlySolutionId'] ?? null;
            $dbOnlySolutionId = is_numeric($dbOnly) ? (int) $dbOnly : null;

            $provider = (string) ($payload['provider'] ?? 'gemini');
            $model = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;
            $context = (isset($payload['context']) && is_array($payload['context'])) ? $payload['context'] : [];

            $this->logger->info('chat.request', [
                'trace_id' => $trace->getTraceId(),
                'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                'route' => $routeName,
                'sessionId' => $sessionId,
                'provider' => $provider,
                'model' => $model,
                'db_only' => $dbOnlySolutionId !== null,
                'message_len' => mb_strlen($message),
            ]);

            $result = $trace->span('support_chat.ask', function () use (
                $sessionId,
                $message,
                $dbOnlySolutionId,
                $provider,
                $model,
                $context,
                $trace
            ) {
                return $this->chatService->ask(
                    sessionId: $sessionId,
                    message: $message,
                    dbOnlySolutionId: $dbOnlySolutionId,
                    provider: $provider,
                    model: $model,
                    context: $context,
                    trace: $trace,
                );
            }, [
                'provider' => $provider,
                'model' => $model,
                'db_only' => $dbOnlySolutionId !== null,
            ]);

            // --- Mini-Contract: type, answer, trace_id immer setzen ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            // provider/model nur dann zurückgeben, wenn AI wirklich genutzt wurde
            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $result['provider'] ?? $provider;
                if ($model !== null && trim($model) !== '') {
                    $result['model'] = $result['model'] ?? $model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('chat.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'provider' => $result['provider'] ?? $provider,
                'model' => $result['model'] ?? $model,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
                'modeHint' => $result['modeHint'] ?? null,
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('chat.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), 'Interner Fehler. Bitte später erneut versuchen.', ResponseCode::ERROR),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }

    #[Route('/api/chat/newsletter/analyze', name: 'app_chat_newsletter_analyze', methods: ['POST'])]
    public function newsletterAnalyze(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.newsletter.analyze');
        TraceContext::set($trace);

        $uiReqId = (string) $request->headers->get('X-UI-Req-Id', '');

        try {
            $sessionId = (string) $request->request->get('sessionId', '');
            $message   = (string) $request->request->get('message', '');
            $model     = $request->request->get('model');

            $driveUrl = (string)($request->request->get('drive_url', '') ?: $request->request->get('driveUrl', ''));

            /** @var UploadedFile|null $file */
            $file = $request->files->get('file');

            // ✅ Pflicht: mindestens File ODER Drive-Link
            if (!$file instanceof UploadedFile && trim($driveUrl) === '') {
                return new JsonResponse(
                    ResponseCode::error(
                        $trace->getTraceId(),
                        'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
                        ResponseCode::NEED_INPUT
                    ),
                    422
                );
            }

            // ✅ Drive-Link nur prüfen, wenn vorhanden
            // ✅ Drive-Link nur prüfen, wenn vorhanden (und kein Upload als Fallback)
            if (trim($driveUrl) !== '' && !$file instanceof UploadedFile) {

                // 1) Normalisieren (z.B. "/drive.google.com/..." -> fileId -> uc?export=download)
                $norm = $this->normalizeDriveUrl($driveUrl);

                if (($norm['ok'] ?? false) !== true) {
                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.',
                            ResponseCode::DRIVE_URL_INVALID
                        ),
                        422
                    );
                }

                // Ordner-Link: für Newsletter NICHT zulassen (Newsletter braucht File)
                if (($norm['type'] ?? '') !== 'file' || !is_string($norm['inspect_url'] ?? null) || $norm['inspect_url'] === '') {
                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            'Bitte nutze einen Google-Drive-Dateilink (PDF). Ordner-Links sind hier nicht zulässig.',
                            ResponseCode::DRIVE_URL_INVALID
                        ),
                        422
                    );
                }

                // 2) Inspector soll den Direktdownload prüfen, nicht die /view Seite
                $check = $this->externalUrlInspector->inspect((string)$norm['inspect_url']);

                if (($check['ok'] ?? false) !== true) {
                    $invalid = ($check['reason'] ?? '') === 'invalid_url' || ($check['reason'] ?? '') === 'invalid_scheme';

                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            $invalid
                                ? 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.'
                                : 'Der Google-Drive-Link ist nicht abrufbar (Berechtigung/kein Direktdownload). Bitte Freigabe prüfen oder eine Datei hochladen.',
                            $invalid ? ResponseCode::DRIVE_URL_INVALID : ResponseCode::DRIVE_URL_UNREACHABLE
                        ),
                        422
                    );
                }
            }

            $provider = 'openai';

            $result = $this->chatService->newsletterAnalyze(
                sessionId: $sessionId,
                message: $message,
                driveUrl: trim($driveUrl), // darf leer sein
                file: $file,
                provider: $provider,
                model: is_string($model) ? $model : null,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if (is_string($model) && trim((string)$model) !== '') {
                    $result['model'] = (string)$model;
                }
            }

            unset($result['_meta']);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('newsletter.analyze.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), $e->getMessage(), ResponseCode::UNSUPPORTED_TEMPLATE),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }





    #[Route('/api/chat/newsletter/patch', name: 'app_chat_newsletter_patch', methods: ['POST'])]
    public function newsletterPatch(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.newsletter.patch');
        TraceContext::set($trace);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string)($payload['sessionId'] ?? '');
            $draftId   = (string)($payload['draftId'] ?? '');
            $message   = (string)($payload['message'] ?? '');
            $provider  = (string)($payload['provider'] ?? 'openai');
            $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

            $this->logger->info('newsletter.patch.request', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'provider' => $provider,
                'model' => $model,
                'message_len' => mb_strlen($message),
            ]);

            $result = $this->chatService->newsletterPatch(
                sessionId: $sessionId,
                draftId: $draftId,
                message: $message,
                provider: $provider,
                model: $model,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if ($model !== null && trim($model) !== '') {
                    $result['model'] = $model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('newsletter.patch.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $result['draftId'] ?? $draftId,
                'type' => $result['type'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('newsletter.patch.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), 'Interner Fehler. Bitte später erneut versuchen.', ResponseCode::ERROR),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }

    #[Route('/api/chat/newsletter/confirm', name: 'app_chat_newsletter_confirm', methods: ['POST'])]
    public function newsletterConfirm(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.newsletter.confirm');
        TraceContext::set($trace);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string)($payload['sessionId'] ?? '');
            $draftId   = (string)($payload['draftId'] ?? '');
            $provider  = (string)($payload['provider'] ?? 'openai');
            $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

            $this->logger->info('newsletter.confirm.request', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
            ]);

            $result = $this->chatService->newsletterConfirm(
                sessionId: $sessionId,
                draftId: $draftId,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if ($model !== null && trim($model) !== '') {
                    $result['model'] = $model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('newsletter.confirm.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'type' => $result['type'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('newsletter.confirm.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), 'Interner Fehler. Bitte später erneut versuchen.', ResponseCode::ERROR),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }

    #[Route('/api/chat/form/analyze', name: 'app_chat_document_analyze', methods: ['POST'])]
    public function documentAnalyze(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.document.analyze');
        TraceContext::set($trace);

        $uiReqId = (string) $request->headers->get('X-UI-Req-Id', '');

        try {
            $sessionId = (string) $request->request->get('sessionId', '');
            $message   = (string) $request->request->get('message', '');
            $model     = $request->request->get('model');

            // akzeptiere beide Keys aus UI: drive_url oder driveUrl
            $driveUrl = (string)($request->request->get('drive_url', '') ?: $request->request->get('driveUrl', ''));

            /** @var UploadedFile|null $file */
            $file = $request->files->get('file');

            $this->logger->info('document.analyze.debug_upload', [
                'trace_id' => $trace->getTraceId(),
                'files_keys' => array_keys($request->files->all()),
                'has_file' => $file instanceof UploadedFile,
                'content_type' => $request->headers->get('content-type'),
                'content_length' => $request->headers->get('content-length'),
                'drive_url_len' => mb_strlen(trim($driveUrl)),
            ]);

            // ✅ Pflicht: mindestens File ODER Drive-Link
            if (!$file instanceof UploadedFile && trim($driveUrl) === '') {
                return new JsonResponse(
                    ResponseCode::error(
                        $trace->getTraceId(),
                        'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
                        ResponseCode::NEED_INPUT
                    ),
                    422
                );
            }

            // ✅ Drive-Link nur prüfen, wenn er wirklich mitgegeben wurde
            // ✅ Drive-Link nur prüfen, wenn vorhanden (und kein Upload als Fallback)
            if (trim($driveUrl) !== '' && !$file instanceof UploadedFile) {

                // 1) Normalisieren (z.B. "/drive.google.com/..." -> fileId -> uc?export=download)
                $norm = $this->normalizeDriveUrl($driveUrl);

                if (($norm['ok'] ?? false) !== true) {
                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.',
                            ResponseCode::DRIVE_URL_INVALID
                        ),
                        422
                    );
                }

                // Ordner-Link: für Newsletter NICHT zulassen (Newsletter braucht File)
                if (($norm['type'] ?? '') !== 'file' || !is_string($norm['inspect_url'] ?? null) || $norm['inspect_url'] === '') {
                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            'Bitte nutze einen Google-Drive-Dateilink (PDF). Ordner-Links sind hier nicht zulässig.',
                            ResponseCode::DRIVE_URL_INVALID
                        ),
                        422
                    );
                }

                // 2) Inspector soll den Direktdownload prüfen, nicht die /view Seite
                $check = $this->externalUrlInspector->inspect((string)$norm['inspect_url']);

                if (($check['ok'] ?? false) !== true) {
                    $invalid = ($check['reason'] ?? '') === 'invalid_url' || ($check['reason'] ?? '') === 'invalid_scheme';

                    return new JsonResponse(
                        ResponseCode::error(
                            $trace->getTraceId(),
                            $invalid
                                ? 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.'
                                : 'Der Google-Drive-Link ist nicht abrufbar (Berechtigung/kein Direktdownload). Bitte Freigabe prüfen oder eine Datei hochladen.',
                            $invalid ? ResponseCode::DRIVE_URL_INVALID : ResponseCode::DRIVE_URL_UNREACHABLE
                        ),
                        422
                    );
                }
            }


            // Dokumente: Analyze läuft OpenAI-only
            $provider = 'openai';

            $this->logger->info('document.analyze.request', [
                'trace_id' => $trace->getTraceId(),
                'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                'sessionId' => $sessionId,
                'provider' => $provider,
                'model' => is_string($model) ? $model : null,
                'drive_url_present' => trim($driveUrl) !== '',
                'file_present' => $file instanceof UploadedFile,
                'file_name' => $file?->getClientOriginalName(),
                'file_size' => $file?->getSize(),
            ]);

            $result = $this->chatService->documentAnalyze(
                sessionId: $sessionId,
                message: $message,
                driveUrl: trim($driveUrl),           // kann leer sein -> muss Service akzeptieren
                file: $file,                         // kann null sein
                provider: $provider,
                model: is_string($model) ? $model : null,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if (is_string($model) && trim((string)$model) !== '') {
                    $result['model'] = (string)$model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('document.analyze.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'type' => $result['type'] ?? null,
                'draftId' => $result['draftId'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('document.analyze.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), $e->getMessage(), ResponseCode::UNSUPPORTED_TEMPLATE),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }





    #[Route('/api/chat/form/patch', name: 'app_chat_document_patch', methods: ['POST'])]
    public function documentPatch(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.document.patch');
        TraceContext::set($trace);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string)($payload['sessionId'] ?? '');
            $draftId   = (string)($payload['draftId'] ?? '');
            $message   = (string)($payload['message'] ?? '');
            $provider  = (string)($payload['provider'] ?? 'openai');
            $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

            $this->logger->info('document.patch.request', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'provider' => $provider,
                'model' => $model,
                'message_len' => mb_strlen($message),
            ]);

            $result = $this->chatService->documentPatch(
                sessionId: $sessionId,
                draftId: $draftId,
                message: $message,
                provider: $provider,
                model: $model,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if ($model !== null && trim($model) !== '') {
                    $result['model'] = $model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('document.patch.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $result['draftId'] ?? $draftId,
                'type' => $result['type'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('document.patch.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), 'Interner Fehler. Bitte später erneut versuchen.', ResponseCode::ERROR),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }

    #[Route('/api/chat/form/confirm', name: 'app_chat_document_confirm', methods: ['POST'])]
    public function documentConfirm(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat.document.confirm');
        TraceContext::set($trace);

        try {
            $payload = json_decode((string) $request->getContent(), true) ?: [];

            $sessionId = (string)($payload['sessionId'] ?? '');
            $draftId   = (string)($payload['draftId'] ?? '');
            $provider  = (string)($payload['provider'] ?? 'openai');
            $model     = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

            $this->logger->info('document.confirm.request', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
            ]);

            $result = $this->chatService->documentConfirm(
                sessionId: $sessionId,
                draftId: $draftId,
                trace: $trace,
            );

            // --- Mini-Contract ---
            $result['trace_id'] ??= $trace->getTraceId();
            $result['type'] ??= ResponseCode::TYPE_ANSWER;
            $result['answer'] = (string)($result['answer'] ?? '');

            $aiUsed = (bool)(($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if ($model !== null && trim($model) !== '') {
                    $result['model'] = $model;
                }
            }

            unset($result['_meta']);

            $this->logger->info('document.confirm.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'draftId' => $draftId,
                'type' => $result['type'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('document.confirm.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new JsonResponse(
                ResponseCode::error($trace->getTraceId(), 'Interner Fehler. Bitte später erneut versuchen.', ResponseCode::ERROR),
                500
            );
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }

    private function parseGoogleDriveLink(string $url): array
    {
        $u = trim((string) $url);
        if ($u === '') {
            return ['type' => 'empty', 'id' => null, 'download' => null, 'original' => ''];
        }

        $u = ltrim($u);          // whitespace
        $u = ltrim($u, '/');     // <-- wichtig: führenden Slash entfernen
        if (!str_starts_with($u, 'http://') && !str_starts_with($u, 'https://')) {
            $u = 'https://' . $u; // <-- falls UI ohne Schema liefert
        }



        $u = preg_replace('~#.*$~', '', $u) ?? $u;

        // Folder link: /drive/u/0/folders/<ID> or /drive/folders/<ID>
        if (preg_match('~/(?:drive/)?folders/([a-zA-Z0-9_-]+)~', $u, $m)) {
            return ['type' => 'folder', 'id' => $m[1], 'download' => null, 'original' => $u];
        }

        // File link: /file/d/<ID>/
        if (preg_match('~/file/d/([a-zA-Z0-9_-]+)~', $u, $m)) {
            $id = $m[1];
            return [
                'type' => 'file',
                'id' => $id,
                'download' => 'https://drive.google.com/uc?export=download&id=' . $id,
                'original' => $u,
            ];
        }

        // Alternate: ?id=<ID>
        $parts = parse_url($u);
        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $q);
            if (isset($q['id']) && is_string($q['id']) && $q['id'] !== '') {
                $id = $q['id'];
                return [
                    'type' => 'file',
                    'id' => $id,
                    'download' => 'https://drive.google.com/uc?export=download&id=' . $id,
                    'original' => $u,
                ];
            }
        }

        return ['type' => 'unknown', 'id' => null, 'download' => null, 'original' => $u];
    }

    private function normalizeDriveUrl(string $driveUrl): array
    {
        $parsed = $this->parseGoogleDriveLink($driveUrl);

        // We keep original for business logic, but provide download URL for inspection if it's a file.
        if ($parsed['type'] === 'file' && is_string($parsed['download']) && $parsed['download'] !== '') {
            return [
                'ok' => true,
                'type' => 'file',
                'id' => $parsed['id'],
                'original_url' => $parsed['original'],
                'inspect_url' => $parsed['download'], // <- only for ExternalUrlInspector
            ];
        }

        if ($parsed['type'] === 'folder') {
            return [
                'ok' => true,
                'type' => 'folder',
                'id' => $parsed['id'],
                'original_url' => $parsed['original'],
                'inspect_url' => null, // <- don't inspect folder with HTTP download logic
            ];
        }

        return [
            'ok' => false,
            'type' => $parsed['type'],
            'id' => $parsed['id'],
            'original_url' => $parsed['original'],
            'inspect_url' => null,
        ];
    }


}
