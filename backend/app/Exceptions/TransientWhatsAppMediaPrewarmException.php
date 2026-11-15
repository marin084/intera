<?php

namespace App\Exceptions;

/**
 * Transient error that should trigger job retries (e.g. network timeout, 5xx server error, rate limit).
 */
class TransientWhatsAppMediaPrewarmException extends WhatsAppMediaPrewarmException {}
