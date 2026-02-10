<?php

namespace App\Service;

final class ResponseCode
{
    // Input / Guard
    public const NEED_DRIVE = 'need_drive';
    public const NEED_INPUT = 'need_input';

    public const INVALID_FILENAME = 'invalid_filename';
    // URL
    public const INVALID_URL = 'invalid_url';
    public const UNREACHABLE_URL = 'unreachable_url';

    // File
    public const INVALID_FILE_TYPE = 'invalid_file_type';
    public const UNSUPPORTED_FILE_TYPE = 'unsupported_file_type';
    public const PDF_SCANNED_NEEDS_OCR = 'pdf_scanned_needs_ocr';

    // Success / Flow
    public const NEEDS_CONFIRMATION = 'needs_confirmation';
    public const ERROR = 'error';


}
