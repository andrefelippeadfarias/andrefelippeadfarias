<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * Reference vocabulary (vigia_seguranca.classificar): "origem desconhecida", "reserva sem
 * valor", "reserva alterada sem valor", "rajada de cancelamentos".
 */
enum SecurityFindingKind: string
{
    case UnknownOrigin = 'unknown_origin';
    case ZeroValue = 'zero_value';
    case ZeroValueModified = 'zero_value_modified';
    case CancellationBurst = 'cancellation_burst';
}
