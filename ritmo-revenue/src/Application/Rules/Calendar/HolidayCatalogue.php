<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Calendar;

use InvalidArgumentException;
use JsonException;

/**
 * The holiday lists shipped with the plugin in presets/holidays/ (one JSON file per country).
 * Read from disk only: never downloaded.
 */
final class HolidayCatalogue
{
    public function __construct(private readonly string $folder)
    {
    }

    /**
     * The country codes of the shipped lists, sorted.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        $codes = [];
        foreach (glob($this->folder . '/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * @throws InvalidArgumentException when the country has no list or its file does not read
     */
    public function load(string $country): HolidayList
    {
        if (!in_array($country, $this->countries(), true)) {
            throw new InvalidArgumentException('There is no holiday list for that country.');
        }
        try {
            $data = json_decode((string) file_get_contents($this->folder . '/' . $country . '.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The holiday list of that country does not read.', 0, $e);
        }
        $list = HolidayList::fromArray(is_array($data) ? $data : []);
        if ($list->country !== $country) {
            throw new InvalidArgumentException('The holiday list names another country.');
        }

        return $list;
    }
}
