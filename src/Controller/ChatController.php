<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SupportChatService;
use App\Tracing\TraceContext;
use App\Tracing\TraceManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly SupportChatService $chatService,
        private readonly TraceManager $traceManager,
        private readonly LoggerInterface $logger,
        private readonly \App\Service\ExternalUrlInspector $externalUrlInspector,
    ) {}

    #[Route('/api/chat', name: 'app_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $trace = $this->traceManager->start('http.api.chat');
        TraceContext::set($trace);

        // UI span
        $uiSpan = (string) $request->headers->get('X-UI-Span', '');
        $uiAt   = (string) $request->headers->get('X-UI-At', '');
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

            $result['trace_id'] = $trace->getTraceId();

// provider/model nur dann zurückgeben, wenn AI wirklich genutzt wurde
            $aiUsed = (bool) (($result['_meta']['ai_used'] ?? false));

// falls der Service selbst provider/model setzt (bei AI), respektieren wir das.
            // ansonsten setzen wir provider/model nur, wenn aiUsed=true
            if ($aiUsed) {
                $result['provider'] = $result['provider'] ?? $provider;

                if ($model !== null) {
                    $result['model'] = $result['model'] ?? $model;
                }
            }

            // Meta nicht an Client ausgeben
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
            throw $e;
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
            $driveUrl  = (string) $request->request->get('drive_url', '');
            $file      = $request->files->get('file'); // UploadedFile|null

            // ✅ Guard: mindestens file ODER drive_url
            if ($file === null && $driveUrl === '') {
                $this->logger->info('newsletter.analyze.missing_input', [
                    'trace_id' => $trace->getTraceId(),
                    'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                    'sessionId' => $sessionId,
                ]);

                return new JsonResponse([
                    'type' => 'need_input',
                    'answer' => 'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
                ], 422);
            }
            // ✅ Drive/External URL vorhanden, aber kein Upload-File: Erreichbarkeit prüfen
            if ($file === null && $driveUrl !== '') {
                $check = $this->externalUrlInspector->inspect($driveUrl);

                if (($check['ok'] ?? false) !== true) {
                    $this->logger->info('newsletter.analyze.drive_url_unreachable', [
                        'trace_id' => $trace->getTraceId(),
                        'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                        'sessionId' => $sessionId,
                        'reason' => $check['reason'] ?? null,
                        'status' => $check['status'] ?? null,
                        'contentType' => $check['contentType'] ?? null,
                    ]);

                    $type = ($check['reason'] ?? '') === 'invalid_url' || ($check['reason'] ?? '') === 'invalid_scheme'
                        ? 'drive_url_invalid'
                        : 'drive_url_unreachable';

                    return new JsonResponse([
                        'type' => $type,
                        'answer' => $type === 'drive_url_invalid'
                            ? 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.'
                            : 'Der Google-Drive-Link ist nicht abrufbar (Berechtigung/kein Direktdownload). Bitte Freigabe prüfen oder eine Datei hochladen.',
                    ], 422);
                }
            }




            // 🔒 Newsletter immer OpenAI
            $provider = 'openai';

            $this->logger->info('newsletter.analyze.request', [
                'trace_id' => $trace->getTraceId(),
                'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                'sessionId' => $sessionId,
                'provider' => $provider,
                'model' => is_string($model) ? $model : null,
                'drive_url_present' => $driveUrl !== '',
                'file_present' => $file !== null,
                'file_name' => $file?->getClientOriginalName(),
                'file_size' => $file?->getSize(),
            ]);

            $result = $this->chatService->newsletterAnalyze(
                sessionId: $sessionId,
                message: $message,
                driveUrl: $driveUrl,
                file: $file,
                provider: $provider,
                model: is_string($model) ? $model : null,
                trace: $trace,
            );

            $result['trace_id'] = $trace->getTraceId();

            // provider/model nur dann zurückgeben, wenn AI wirklich genutzt wurde
            $aiUsed = (bool) (($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if (is_string($model)) {
                    $result['model'] = $model;
                }
            }

            // Meta nicht an Client ausgeben
            unset($result['_meta']);


            $this->logger->info('newsletter.analyze.response', [
                'trace_id' => $trace->getTraceId(),
                'sessionId' => $sessionId,
                'type' => $result['type'] ?? null,
                'draftId' => $result['draftId'] ?? null,
                'answer_len' => mb_strlen((string)($result['answer'] ?? '')),
            ]);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('newsletter.analyze.error', [
                'trace_id' => $trace->getTraceId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            throw $e;
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

            $result['trace_id'] = $trace->getTraceId();

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
            throw $e;
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

            $result['trace_id'] = $trace->getTraceId();

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
            throw $e;
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
            $driveUrl  = (string) $request->request->get('drive_url', '');
            $file      = $request->files->get('file'); // UploadedFile|null

            $this->logger->info('document.analyze.debug_upload', [
                'files_keys' => array_keys($request->files->all()),
                'has_file' => $file instanceof UploadedFile,
                'content_type' => $request->headers->get('content-type'),
                'content_length' => $request->headers->get('content-length'),
            ]);


            // ✅ Guard: mindestens file ODER drive_url
            if ($file === null && $driveUrl === '') {
                $this->logger->info('document.analyze.missing_input', [
                    'trace_id' => $trace->getTraceId(),
                    'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                    'sessionId' => $sessionId,
                ]);

                return new JsonResponse([
                    'type' => 'need_input',
                    'answer' => 'Bitte lade eine Datei hoch oder füge einen Google-Drive-Link ein.',
                ], 422);
            }

            // ✅ Drive/External URL vorhanden, aber kein Upload-File: Erreichbarkeit prüfen
            if ($file === null && $driveUrl !== '') {
                $check = $this->externalUrlInspector->inspect($driveUrl);

                if (($check['ok'] ?? false) !== true) {
                    $this->logger->info('document.analyze.drive_url_unreachable', [
                        'trace_id' => $trace->getTraceId(),
                        'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                        'sessionId' => $sessionId,
                        'reason' => $check['reason'] ?? null,
                        'status' => $check['status'] ?? null,
                        'contentType' => $check['contentType'] ?? null,
                    ]);

                    $type = ($check['reason'] ?? '') === 'invalid_url' || ($check['reason'] ?? '') === 'invalid_scheme'
                        ? 'drive_url_invalid'
                        : 'drive_url_unreachable';

                    return new JsonResponse([
                        'type' => $type,
                        'answer' => $type === 'drive_url_invalid'
                            ? 'Der Google-Drive-Link ist ungültig. Bitte prüfe den Link.'
                            : 'Der Google-Drive-Link ist nicht abrufbar (Berechtigung/kein Direktdownload). Bitte Freigabe prüfen oder eine Datei hochladen.',
                    ], 422);
                }
            }



            // 🔒 Dokumente auch immer OpenAI (wie Newsletter)
            $provider = 'openai';

            $this->logger->info('document.analyze.request', [
                'trace_id' => $trace->getTraceId(),
                'ui_req_id' => $uiReqId !== '' ? $uiReqId : null,
                'sessionId' => $sessionId,
                'provider' => $provider,
                'model' => is_string($model) ? $model : null,
                'drive_url_present' => $driveUrl !== '',
                'file_present' => $file !== null,
                'file_name' => $file?->getClientOriginalName(),
                'file_size' => $file?->getSize(),
            ]);

            $result = $this->chatService->documentAnalyze(
                sessionId: $sessionId,
                message: $message,
                driveUrl: $driveUrl,
                file: $file,
                provider: $provider,
                model: is_string($model) ? $model : null,
                trace: $trace,
            );

            $result['trace_id'] = $trace->getTraceId();

            // provider/model nur dann zurückgeben, wenn AI wirklich genutzt wurde
            $aiUsed = (bool) (($result['_meta']['ai_used'] ?? false));
            if ($aiUsed) {
                $result['provider'] = $provider;
                if (is_string($model)) {
                    $result['model'] = $model;
                }
            }

            // Meta nicht an Client ausgeben
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
            throw $e;
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

            $result['trace_id'] = $trace->getTraceId();

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
            throw $e;
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

            $result['trace_id'] = $trace->getTraceId();

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
            throw $e;
        } finally {
            TraceContext::set(null);
            $trace->finish();
        }
    }


}
