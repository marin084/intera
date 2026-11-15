<?php

namespace App\Exceptions;

use RuntimeException;

class ConversationConflictException extends RuntimeException
{
    public function __construct(
        string $message = 'Esta conversación ya fue tomada por otro agente.',
        public string $errorCode = 'conversation_already_claimed',
        int $code = 409,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
