<?php

namespace App\Services\Runtime\Handlers;

use App\Enums\FlowFailureCode;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Events\MessageCreated;
use App\Models\FlowExecution;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\ExecutionContext;
use App\Services\Runtime\Results\NodeExecutionResult;
use App\Services\Runtime\VariableInterpolator;
use Illuminate\Support\Facades\DB;

class ImageNodeHandler implements NodeHandler
{
    public function __construct(
        protected VariableInterpolator $interpolator
    ) {}

    public function execute(FlowNode $node, FlowExecution $execution, ExecutionContext $context): NodeExecutionResult
    {
        $config = $node->configuration ?? [];
        $rawUrl = $config['url'] ?? null;

        if ($rawUrl === null || ! is_string($rawUrl) || trim($rawUrl) === '') {
            return NodeExecutionResult::failed(
                FlowFailureCode::INVALID_NODE_CONFIG->value,
                "El nodo de imagen '{$node->node_key}' no contiene una URL válida configurada."
            );
        }

        // Interpolate URL in case it uses dynamic variables
        $interpolatedUrl = $this->interpolator->interpolate(trim($rawUrl), $context)['text'];

        // Caption interpolation
        $rawCaption = $config['caption'] ?? '';
        $caption = '';
        $usedVariables = [];
        $missingVariables = [];

        if (is_string($rawCaption) && trim($rawCaption) !== '') {
            $interpolatedCaption = $this->interpolator->interpolate($rawCaption, $context);
            $caption = $interpolatedCaption['text'];
            $usedVariables = $interpolatedCaption['used_variables'];
            $missingVariables = $interpolatedCaption['missing_variables'];
        }

        // Determine filename
        $rawFileName = $config['file_name'] ?? null;
        if (! is_string($rawFileName) || trim($rawFileName) === '') {
            $parsedPath = parse_url($interpolatedUrl, PHP_URL_PATH);
            $baseName = $parsedPath ? basename($parsedPath) : 'image.png';
            $fileName = $baseName ?: 'image.png';
        } else {
            $fileName = trim($rawFileName);
        }

        // Determine MIME type
        $rawMime = $config['mime_type'] ?? null;
        if (! is_string($rawMime) || trim($rawMime) === '') {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $mimeMap = [
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
            ];
            $mimeType = $mimeMap[$ext] ?? 'image/jpeg';
        } else {
            $mimeType = trim($rawMime);
        }

        $attachment = [
            'url' => $interpolatedUrl,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'caption' => $caption,
        ];

        $message = Message::create([
            'conversation_id' => $execution->conversation_id,
            'flow_execution_id' => $execution->id,
            'flow_node_id' => $node->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::IMAGE,
            'content' => $caption,
            'payload' => [
                'attachment' => $attachment,
            ],
            'metadata' => [
                'node_key' => $node->node_key,
                'attachment' => $attachment,
                'used_variables' => $usedVariables,
                'missing_variables' => $missingVariables,
            ],
            'sent_at' => now(),
        ]);

        $execution->conversation?->update(['last_message_at' => now()]);

        DB::afterCommit(function () use ($message) {
            broadcast(new MessageCreated($message));
        });

        return NodeExecutionResult::completed(
            output: [
                'message_id' => $message->id,
                'url' => $interpolatedUrl,
                'caption' => $caption,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
            ],
            metadata: [
                'used_variables' => $usedVariables,
                'missing_variables' => $missingVariables,
            ]
        );
    }

    public function resume(FlowNode $node, FlowExecution $execution, ExecutionContext $context, Message $message): NodeExecutionResult
    {
        return NodeExecutionResult::failed(
            FlowFailureCode::EXECUTION_STATE_ERROR->value,
            'El nodo de imagen no espera respuestas del usuario.'
        );
    }
}
