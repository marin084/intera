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

Broadcast::channel('user.{id}', function (User $user, $id) {
    if (! $user->is_active) {
        return false;
    }

    return (string) $user->id === (string) $id;
});

Broadcast::channel('inbox', function (User $user) {
    if (! $user->is_active) {
        return false;
    }

    return $user->hasPermission('conversations.view_assigned') || $user->hasPermission('conversations.view_all');
});

Broadcast::channel('conversation.{conversationId}', function (User $user, $conversationId) {
    if (! $user->is_active) {
        return false;
    }

    if ($user->hasRole(['super_admin', 'admin']) || $user->hasPermission('conversations.view_all')) {
        return true;
    }

    $conversation = Conversation::find($conversationId);
    if (! $conversation) {
        return false;
    }

    return (int) $conversation->assigned_user_id === (int) $user->id;
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
