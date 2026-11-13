<?php

declare(strict_types=1);

namespace Ritmo\Rest;

use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Admin\RefusalText;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The routes of the operation (milestone M3) in ritmo/v1. Every route needs the session, the
 * REST nonce and a capability (RestRoutes::allowed); the use cases check again what each person
 * may do (a change of mode, an approval, an undo). The decision on a plan and the position of the
 * emergency switch are read strictly in the callback, after the permission (OperationInput): a
 * malformed value is a 400 that changes nothing.
 *
 * | Route | Capability |
 * |---|---|
 * | POST /properties/{id}/run | ritmo_manage_rules ("Run now") |
 * | POST /properties/{id}/mode | ritmo_view (the mode service checks the capability of each change) |
 * | GET /properties/{id}/approvals | ritmo_view |
 * | POST /approvals/{id} | ritmo_approve (decision "approve" or "reject", required; optional non-empty list of entry keys) |
 * | GET /properties/{id}/changes | ritmo_view |
 * | POST /properties/{id}/undo | ritmo_approve |
 * | POST /emergency | engage: ritmo_approve or ritmo_manage_connections; release: ritmo_manage_connections (engaged: true or false, required) |
 */
final class OperationRoutes
{
    private const PROPERTY = '(?P<id>[A-Za-z0-9][A-Za-z0-9_-]{0,31})';

    private function __construct()
    {
    }

    public static function register(): void
    {
        $ns = RestRoutes::NAMESPACE;
        register_rest_route($ns, '/properties/' . self::PROPERTY . '/run', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'runNow'], 'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES)]);
        register_rest_route($ns, '/properties/' . self::PROPERTY . '/mode', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'mode'], 'permission_callback' => RestRoutes::allowed(Roles::VIEW)]);
        register_rest_route($ns, '/properties/' . self::PROPERTY . '/approvals', ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'approvals'], 'permission_callback' => RestRoutes::allowed(Roles::VIEW)]);
        register_rest_route($ns, '/approvals/(?P<id>\d+)', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'decide'], 'permission_callback' => RestRoutes::allowed(Roles::APPROVE)]);
        register_rest_route($ns, '/properties/' . self::PROPERTY . '/changes', ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'changes'], 'permission_callback' => RestRoutes::allowed(Roles::VIEW)]);
        register_rest_route($ns, '/properties/' . self::PROPERTY . '/undo', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'undo'], 'permission_callback' => RestRoutes::allowed(Roles::APPROVE)]);
        register_rest_route($ns, '/emergency', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'emergency'], 'permission_callback' => RestRoutes::allowed(Roles::VIEW)]);
    }

    public static function runNow(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = Services::instance()->stack()->runNow()->run((string) $request['id']);
        } catch (OutOfBoundsException) {
            return self::notFound();
        }

        return new WP_REST_Response(['status' => $result->status->value, 'run_id' => $result->runId, 'note' => $result->note], $result->status->value === 'busy' ? 409 : 200);
    }

    public static function mode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $mode = PropertyMode::tryFrom((string) $request->get_param('mode'));
        if ($mode === null) {
            return new WP_Error('ritmo_bad_mode', __('Unknown mode.', 'ritmo-revenue'), ['status' => 400]);
        }
        $stack = Services::instance()->stack();
        try {
            $property = $stack->modes()->change((string) $request['id'], $mode, get_current_user_id(), $stack->rules()->modes(), (string) $request->get_param('confirmation'), (string) $request->get_param('reason'));
        } catch (OutOfBoundsException) {
            return self::notFound();
        } catch (ModeRefused $refused) {
            return new WP_Error('ritmo_' . $refused->reason, RefusalText::mode($refused), ['status' => $refused->reason === ModeRefused::NO_CAPABILITY ? 403 : 409]);
        }

        return new WP_REST_Response(['id' => $property->id, 'mode' => $property->mode->value]);
    }

    public static function approvals(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stack = Services::instance()->stack();
        if ($stack->properties()->find((string) $request['id']) === null) {
            return self::notFound();
        }
        $out = [];
        foreach ($stack->approvalDesk()->pending((string) $request['id'], $stack->rules()->approval()) as $pending) {
            $out[] = ['entries' => array_map(static fn (PlanEntry $e): array => ['key' => $e->key(), 'group' => $e->group()] + $e->toArray(), $pending['entries'])] + $pending;
        }

        return new WP_REST_Response($out);
    }

    public static function decide(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stack = Services::instance()->stack();
        $property = (string) $request->get_param('property');
        try {
            $decision = OperationInput::decision($request->get_param('decision'));
            $keys = OperationInput::keys($request->get_param('keys'));
        } catch (InvalidArgumentException $bad) {
            return new WP_Error('ritmo_bad_decision', __('Send the decision "approve" or "reject" and, to approve only part of the plan, a non-empty list of entry keys.', 'ritmo-revenue'), ['status' => 400]);
        }
        try {
            if ($decision === OperationInput::REJECT) {
                $stack->approvalDesk()->reject($property, (int) $request['id'], get_current_user_id(), $stack->rules()->approval(), sanitize_text_field((string) $request->get_param('note')));

                return new WP_REST_Response(['status' => 'rejected']);
            }
            $grant = $stack->approvalDesk()->approve($property, (int) $request['id'], get_current_user_id(), $keys, $stack->rules()->approval(), sanitize_text_field((string) $request->get_param('note')));
        } catch (OutOfBoundsException) {
            return self::notFound();
        } catch (ApprovalRefused $refused) {
            return new WP_Error('ritmo_' . $refused->reason, RefusalText::approval($refused), ['status' => $refused->reason === ApprovalRefused::NO_CAPABILITY ? 403 : 409]);
        }

        return new WP_REST_Response(['status' => 'approved', 'entries' => count($grant->approvedEntries)]);
    }

    public static function changes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stack = Services::instance()->stack();
        if ($stack->properties()->find((string) $request['id']) === null) {
            return self::notFound();
        }

        return new WP_REST_Response(array_map(static fn (ChangeRecord $r): array => [
            'id' => $r->id,
            'room' => $r->roomCode,
            'night' => $r->night?->format('Y-m-d'),
            'provider' => $r->provider->value,
            'field' => $r->field,
            'old' => $r->oldValue,
            'new' => $r->newValue,
            'actor' => $r->actor,
            'at' => $r->createdAt->format(DATE_ATOM),
            'undone_at' => $r->undoneAt?->format(DATE_ATOM),
        ], array_reverse($stack->changeLog()->latest((string) $request['id'], 100))));
    }

    public static function undo(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stack = Services::instance()->stack();
        $ids = $request->get_param('changes');
        if (!is_array($ids) || $ids === []) {
            return new WP_Error('ritmo_nothing_selected', __('Choose the changes to undo.', 'ritmo-revenue'), ['status' => 400]);
        }
        try {
            $result = $stack->undoWriter()->undo((string) $request['id'], array_values(array_map('intval', $ids)), get_current_user_id(), $stack->rules());
        } catch (OutOfBoundsException) {
            return self::notFound();
        } catch (ApprovalRefused $refused) {
            return new WP_Error('ritmo_' . $refused->reason, RefusalText::approval($refused), ['status' => 403]);
        }

        return new WP_REST_Response($result, match ($result['status']) {
            'busy' => 409,
            'blocked' => 423,
            default => 200,
        });
    }

    public static function emergency(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $engage = OperationInput::engaged($request->get_param('engaged'));
        } catch (InvalidArgumentException) {
            return new WP_Error('ritmo_bad_switch', __('Say whether the emergency switch is engaged: true or false.', 'ritmo-revenue'), ['status' => 400]);
        }
        if (!Roles::maySwitchEmergency($engage)) {
            return new WP_Error('ritmo_forbidden', __('You are not allowed to do this.', 'ritmo-revenue'), ['status' => 403]);
        }

        return new WP_REST_Response(['engaged' => Services::instance()->stack()->emergency()->set($engage, 'REST', 'user:' . get_current_user_id())]);
    }

    private static function notFound(): WP_Error
    {
        return new WP_Error('ritmo_unknown_property', __('There is no such property.', 'ritmo-revenue'), ['status' => 404]);
    }
}
