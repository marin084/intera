<?php

namespace App\Exceptions;

/**
 * Permanent error that should abort immediately without retrying (e.g. invalid MIME, file too large, 4xx client errors).
 */
class PermanentWhatsAppMediaPrewarmException extends WhatsAppMediaPrewarmException {}
