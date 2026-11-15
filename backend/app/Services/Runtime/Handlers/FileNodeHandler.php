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

class FileNodeHandler implements NodeHandler
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
                "El nodo de archivo '{$node->node_key}' no contiene una URL válida configurada."
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
            $baseName = $parsedPath ? basename($parsedPath) : 'documento.pdf';
            $fileName = $baseName ?: 'documento.pdf';
        } else {
            $fileName = trim($rawFileName);
        }

        // Determine MIME type
        $rawMime = $config['mime_type'] ?? null;
        if (! is_string($rawMime) || trim($rawMime) === '') {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $mimeMap = [
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ];
            $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        } else {
            $mimeType = trim($rawMime);
        }

        $sizeBytes = isset($config['size_bytes']) && is_numeric($config['size_bytes'])
            ? (int) $config['size_bytes']
            : null;

        $attachment = [
            'url' => $interpolatedUrl,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'caption' => $caption,
        ];

        $content = ($caption !== '' && $caption !== null) ? $caption : $fileName;

        $message = Message::create([
            'conversation_id' => $execution->conversation_id,
            'flow_execution_id' => $execution->id,
            'flow_node_id' => $node->id,
            'sender_type' => MessageSenderType::BOT,
            'sender_id' => null,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::FILE,
            'content' => $content,
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
                'size_bytes' => $sizeBytes,
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
            'El nodo de archivo no espera respuestas del usuario.'
        );
    }
}
