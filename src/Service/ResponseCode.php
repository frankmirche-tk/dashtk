<?php

declare(strict_types=1);

namespace App\Service;

final class ResponseCode
{
    // Response "type" (UI Rendering)
    public const TYPE_ANSWER  = 'answer';
    public const TYPE_CONFIRM = 'confirm';
    public const TYPE_ERROR   = 'error';

    // Business / Validation Codes
    public const OK = 'ok';
    public const ERROR = 'error';

    public const NEED_INPUT = 'need_input';
    public const NEED_DRIVE = 'need_drive';

    public const NEEDS_CONFIRMATION = 'needs_confirmation';

    public const INVALID_FILENAME = 'invalid_filename';

    public const UNSUPPORTED_FILE_TYPE = 'unsupported_file_type';
    public const UNSUPPORTED_TEMPLATE  = 'unsupported_template';

    public const PDF_SCANNED_NEEDS_OCR = 'pdf_scanned_needs_ocr';

    public const DRIVE_URL_INVALID     = 'drive_url_invalid';
    public const DRIVE_URL_UNREACHABLE = 'drive_url_unreachable';

    /**
     * Standard response builder: always returns type, answer, trace_id
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function answer(
        string $traceId,
        string $answer,
        string $code = self::OK,
        array $extra = []
    ): array {
        return array_merge([
            'type' => self::TYPE_ANSWER,
            'answer' => $answer,
            'trace_id' => $traceId,
            'code' => $code,
        ], $extra);
    }

    /**
     * Confirm response builder: always returns type, answer, trace_id plus draft + card.
     *
     * @param array<string,mixed> $confirmCard
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function confirm(
        string $traceId,
        string $answer,
        string $draftId,
        array $confirmCard,
        string $code = self::NEEDS_CONFIRMATION,
        array $extra = []
    ): array {
        return array_merge([
            'type' => self::TYPE_CONFIRM,
            'answer' => $answer,
            'trace_id' => $traceId,
            'code' => $code,
            'draftId' => $draftId,
            'confirmCard' => $confirmCard,
        ], $extra);
    }

    /**
     * Error response builder: always returns type, answer, trace_id
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function error(
        string $traceId,
        string $answer,
        string $code = self::ERROR,
        array $extra = []
    ): array {
        return array_merge([
            'type' => self::TYPE_ERROR,
            'answer' => $answer,
            'trace_id' => $traceId,
            'code' => $code,
        ], $extra);
    }
}
