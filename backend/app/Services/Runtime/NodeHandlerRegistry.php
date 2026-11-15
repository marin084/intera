<?php

namespace App\Services\Runtime;

use App\Enums\FlowNodeType;
use App\Services\Runtime\Contracts\NodeHandler;
use App\Services\Runtime\Handlers\ConditionNodeHandler;
use App\Services\Runtime\Handlers\EndNodeHandler;
use App\Services\Runtime\Handlers\FileNodeHandler;
use App\Services\Runtime\Handlers\GotoNodeHandler;
use App\Services\Runtime\Handlers\HttpRequestNodeHandler;
use App\Services\Runtime\Handlers\ImageNodeHandler;
use App\Services\Runtime\Handlers\MenuNodeHandler;
use App\Services\Runtime\Handlers\MessageNodeHandler;
use App\Services\Runtime\Handlers\QuestionNodeHandler;
use App\Services\Runtime\Handlers\SetVariableNodeHandler;
use App\Services\Runtime\Handlers\StartNodeHandler;
use App\Services\Runtime\Handlers\TransferToAgentNodeHandler;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class NodeHandlerRegistry
{
    /**
     * @var array<string, class-string<NodeHandler>>
     */
    protected array $handlers = [];

    public function __construct(
        protected Container $container
    ) {
        $this->registerDefaultHandlers();
    }

    protected function registerDefaultHandlers(): void
    {
        $this->register(FlowNodeType::START->value, StartNodeHandler::class);
        $this->register(FlowNodeType::MESSAGE->value, MessageNodeHandler::class);
        $this->register(FlowNodeType::IMAGE->value, ImageNodeHandler::class);
        $this->register(FlowNodeType::FILE->value, FileNodeHandler::class);
        $this->register(FlowNodeType::QUESTION->value, QuestionNodeHandler::class);
        $this->register(FlowNodeType::CONDITION->value, ConditionNodeHandler::class);
        $this->register(FlowNodeType::MENU->value, MenuNodeHandler::class);
        $this->register(FlowNodeType::HTTP_REQUEST->value, HttpRequestNodeHandler::class);
        $this->register(FlowNodeType::GOTO->value, GotoNodeHandler::class);
        $this->register(FlowNodeType::SET_VARIABLE->value, SetVariableNodeHandler::class);
        $this->register(FlowNodeType::TRANSFER_TO_AGENT->value, TransferToAgentNodeHandler::class);
        $this->register(FlowNodeType::END->value, EndNodeHandler::class);
    }

    /**
     * @param  class-string<NodeHandler>  $handlerClass
     */
    public function register(string $type, string $handlerClass): self
    {
        $this->handlers[$type] = $handlerClass;

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function get(string $type): NodeHandler
    {
        if (! isset($this->handlers[$type])) {
            throw new InvalidArgumentException("No se encontró ningún NodeHandler registrado para el tipo: '{$type}'.");
        }

        return $this->container->make($this->handlers[$type]);
    }
}
