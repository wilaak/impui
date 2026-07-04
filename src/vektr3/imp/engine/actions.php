<?php

namespace vektr3\imp\engine;

use vektr3\imp\bus;

//
// The single-writer command path. Any worker can receive an action
// request; only the owning worker may touch the context. So actions
// travel as bus events on the session's topic, and the owner drains
// them each tick. Same code path when the owner receives the action
// itself: one ingress, no special case.
//

function action_topic(string $session_id): string
{
    return "session/$session_id/actions";
}

/**
 * The session's sync channel: a signal topic anything may bump to say
 * "this session should re-render". The out-of-band repaint for
 * background tasks and other sessions; in Poll mode it is also what
 * makes a version check see the change.
 */
function sync_topic(string $session_id): string
{
    return "session/$session_id/sync";
}

function wake(bus\Bus $bus, string $session_id): true|bus\FaultKind
{
    $seq = $bus->signal(sync_topic($session_id));

    return $seq instanceof bus\FaultKind ? $seq : true;
}

/**
 * Called by any worker's transport handler. Fire-and-forget from the
 * client's view; Busy under publisher contention is rare (publishes
 * are microseconds) and the transport just retries once or drops.
 */
function dispatch_action(
    bus\Bus $bus,
    string $session_id,
    string $name,
    mixed $params = true
): true|bus\FaultKind
{
    $seq = $bus->publish(action_topic($session_id), \json_encode([
        'name'   => $name,
        'params' => $params,
    ]));

    return $seq instanceof bus\FaultKind ? $seq : true;
}

/**
 * Called by the owning worker each tick. Applies pending actions to
 * the context and schedules a render when any arrived.
 */
function drain_actions(
    bus\Bus $bus,
    Context $context
): int|bus\FaultKind
{
    $topic = action_topic($context->session->id);

    $head = $bus->head($topic);
    if ($head instanceof bus\FaultKind) {
        return $head;
    }

    if ($context->action_cursor === null) {
        $context->action_cursor = $head;
        return 0;
    }

    if ($head <= $context->action_cursor) {
        return 0;
    }

    $batch = $bus->since($topic, $context->action_cursor);
    if ($batch instanceof bus\FaultKind) {
        return $batch;
    }

    $applied = 0;
    foreach ($batch->events as $event) {
        $action = \json_decode($event->bytes, true);
        if (\is_array($action) && \is_string($action['name'] ?? null)) {
            // One event per name per render: a repeat stays on the bus
            // (cursor not advanced) and applies on the next drain, so
            // rapid same-name actions are never lost to coalescing.
            if (\array_key_exists($action['name'], $context->action_map)) {
                break;
            }
            $context->action_map[$action['name']] = $action['params'] ?? true;
            $applied++;
        }
        $context->action_cursor = $event->seq;
    }

    if ($applied > 0) {
        $context->sync_pending = true;
    }

    return $applied;
}

/**
 * Schedule a render when the session's sync channel moved. The bus
 * half of repaint(): background tasks and other sessions wake this
 * one through the store, never by touching its memory.
 */
function drain_sync(bus\Bus $bus, Context $context): true|bus\FaultKind
{
    $head = $bus->head(sync_topic($context->session->id));
    if ($head instanceof bus\FaultKind) {
        return $head;
    }

    if ($context->sync_cursor === null) {
        $context->sync_cursor = $head;
        return true;
    }

    if ($head > $context->sync_cursor) {
        $context->sync_cursor  = $head;
        $context->sync_pending = true;
    }

    return true;
}
