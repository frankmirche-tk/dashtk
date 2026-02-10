<?php

declare(strict_types=1);

namespace App\Service;

final class ResponseCode
{
    public const NEED_INPUT = 'need_input';
    public const NEED_DRIVE = 'need_drive';

    public const NEEDS_CONFIRMATION = 'needs_confirmation';
    public const OK = 'ok';
    public const ERROR = 'error';

    public const INVALID_FILENAME = 'invalid_filename';

    public const UNSUPPORTED_FILE_TYPE = 'unsupported_file_type';
    public const UNSUPPORTED_TEMPLATE = 'unsupported_template';

    public const PDF_SCANNED_NEEDS_OCR = 'pdf_scanned_needs_ocr';
    public const DRIVE_URL_INVALID = 'drive_url_invalid';
    public const DRIVE_URL_UNREACHABLE = 'drive_url_unreachable';
}
