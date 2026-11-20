<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    if (! $user->is_active) {
        return false;
    }

    return (string) $user->id === (string) $id;
});

Broadcast::channel('workspace.{workspaceId}.user.{id}', function (User $user, string $workspaceId, $id) {
    if (! $user->is_active || ! $user->belongsToWorkspace($workspaceId)) {
        return false;
    }

    return (string) $user->id === (string) $id;
});

Broadcast::channel('workspace.{workspaceId}.inbox', function (User $user, string $workspaceId) {
    if (! $user->is_active || ! $user->belongsToWorkspace($workspaceId)) {
        return false;
    }

    return $user->hasAnyPermission([
        'conversations.view_assigned',
        'conversations.view_all',
    ], $workspaceId);
});

Broadcast::channel('workspace.{workspaceId}.conversation.{conversationId}', function (User $user, string $workspaceId, $conversationId) {
    if (! $user->is_active || ! $user->belongsToWorkspace($workspaceId)) {
        return false;
    }

    $conversation = Conversation::query()
        ->whereKey($conversationId)
        ->where('workspace_id', $workspaceId)
        ->first();
    if (! $conversation) {
        return false;
    }

    return (int) $conversation->assigned_user_id === (int) $user->id
        || $user->hasPermission('conversations.view_all', $workspaceId);
});

Broadcast::channel('webchat.conversation.{conversationId}', function ($user, $conversationId) {
    if ($user instanceof User) {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('conversations.view_all');
    }

    return false;
});
