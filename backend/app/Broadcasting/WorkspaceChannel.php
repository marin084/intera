<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Centralizes the names used by authenticated realtime channels.
 *
 * Including the Workspace id in every private channel makes the tenant
 * boundary explicit at the transport layer as well as in authorization.
 */
final class WorkspaceChannel
{
    public static function inbox(string $workspaceId): PrivateChannel
    {
        return new PrivateChannel("workspace.{$workspaceId}.inbox");
    }

    public static function user(string $workspaceId, int|string $userId): PrivateChannel
    {
        return new PrivateChannel("workspace.{$workspaceId}.user.{$userId}");
    }

    public static function conversation(string $workspaceId, int|string $conversationId): PrivateChannel
    {
        return new PrivateChannel("workspace.{$workspaceId}.conversation.{$conversationId}");
    }
}
