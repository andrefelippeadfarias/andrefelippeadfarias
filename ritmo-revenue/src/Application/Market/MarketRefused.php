<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use RuntimeException;

/**
 * A market action refused before anything was stored: the field (or '') and a reason code the
 * screen turns into a sentence, with its plain parameters. The message is English and safe to
 * log; it carries no cell of a file and no guest data.
 */
final class MarketRefused extends RuntimeException
{
    public const PROPERTY_UNKNOWN = 'property_unknown';
    public const COMPETITOR_UNKNOWN = 'competitor_unknown';
    public const REQUIRED = 'required';
    public const TOO_LONG = 'too_long';
    public const PRIVACY = 'privacy';
    public const BAD_CODE = 'bad_code';
    public const CODE_TAKEN = 'code_taken';
    public const BAD_TAG = 'bad_tag';
    public const BAD_FACTOR = 'bad_factor';
    public const BAD_RATING = 'bad_rating';
    public const ALREADY_IMPORTED = 'already_imported';
    public const FILE_ERROR = 'file_error';
    public const NOTHING_TO_IMPORT = 'nothing_to_import';
    public const PREVIEW_EXPIRED = 'preview_expired';
    public const PREVIEW_TOO_LARGE = 'preview_too_large';
    public const BAD_PRICE = 'bad_price';

    /**
     * @param array<string, int|string> $params
     */
    public function __construct(public readonly string $reason, public readonly string $field = '', public readonly array $params = [])
    {
        parent::__construct(sprintf('Market action refused: %s%s.', $reason, $field === '' ? '' : ' (' . $field . ')'));
    }
}
