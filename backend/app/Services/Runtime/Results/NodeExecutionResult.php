<?php

namespace App\Services\Runtime\Results;

enum NodeResultType: string
{
    case COMPLETED = 'completed';
    case WAITING_INPUT = 'waiting_input';
    case TRANSFERRED = 'transferred';
    case COMPLETED_FLOW = 'completed_flow';
    case FAILED = 'failed';
}

class NodeExecutionResult
{
    public function __construct(
        public readonly NodeResultType $type,
        public readonly ?string $outgoingHandle = null,
        public readonly ?array $output = null,
        public readonly ?array $metadata = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function completed(?string $outgoingHandle = null, ?array $output = null, ?array $metadata = null): self
    {
        return new self(
            type: NodeResultType::COMPLETED,
            outgoingHandle: $outgoingHandle,
            output: $output,
            metadata: $metadata,
        );
    }

    public static function waitingInput(?array $output = null, ?array $metadata = null): self
    {
        return new self(
            type: NodeResultType::WAITING_INPUT,
            output: $output,
            metadata: $metadata,
        );
    }

    public static function transferred(?array $output = null, ?array $metadata = null): self
    {
        return new self(
            type: NodeResultType::TRANSFERRED,
            output: $output,
            metadata: $metadata,
        );
    }

    public static function flowCompleted(?array $output = null, ?array $metadata = null): self
    {
        return new self(
            type: NodeResultType::COMPLETED_FLOW,
            output: $output,
            metadata: $metadata,
        );
    }

    public static function failed(string $failureCode, string $errorMessage, ?array $metadata = null): self
    {
        return new self(
            type: NodeResultType::FAILED,
            failureCode: $failureCode,
            errorMessage: $errorMessage,
            metadata: $metadata,
        );
    }

    public function isCompleted(): bool
    {
        return $this->type === NodeResultType::COMPLETED;
    }

    public function isWaitingInput(): bool
    {
        return $this->type === NodeResultType::WAITING_INPUT;
    }

    public function isTransferred(): bool
    {
        return $this->type === NodeResultType::TRANSFERRED;
    }

    public function isFlowCompleted(): bool
    {
        return $this->type === NodeResultType::COMPLETED_FLOW;
    }

    public function isFailed(): bool
    {
        return $this->type === NodeResultType::FAILED;
    }
}
