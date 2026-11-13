<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Editors\RequestFields;
use Ritmo\Admin\Notice;
use Ritmo\Application\Market\MarketExport;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\MarketSettings;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Support\Days;

/**
 * The form actions of the Market screen (spec 05, appendix B; spec 06, 1.9): competitors,
 * typed prices, the two steps of a price file import, and the CSV downloads. Market data never
 * changes a price by itself; nothing here calls an external system.
 */
final class MarketActions
{
    /** The example file of the import tab: invented competitors and dates. */
    public const TEMPLATE = [
        ['comp-01', '2027-01-15', '2027-01-17', '1840.00', 'total', 'cheapest', 'BRL', '2027-01-02 09:30', 'booking', '1'],
        ['comp-02', '2027-01-15', '2027-01-17', '1125.50', 'per_night', 'comparable', 'BRL', '2027-01-02', 'booking', '1'],
    ];

    private const TEMPLATE_HEADER = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        $r = Capability::MANAGE_RULES;
        $v = Capability::VIEW;
        $page = Menu::MARKET;

        return [
            new FormAction('ritmo_competitor_save', $r, $page, self::saveCompetitor(...)),
            new FormAction('ritmo_competitor_archive', $r, $page, self::archive(...), true, 'competitor'),
            new FormAction('ritmo_competitors_create', $r, $page, self::createMissing(...)),
            new FormAction('ritmo_sample_add', $r, $page, self::addSample(...)),
            new FormAction('ritmo_market_csv_preview', $r, $page, self::preview(...)),
            new FormAction('ritmo_market_csv_import', $r, $page, self::import(...)),
            new FormAction('ritmo_market_csv_cancel', $r, $page, self::cancel(...)),
            new FormAction('ritmo_market_export', $v, $page, self::export(...), false, '', true),
            new FormAction('ritmo_market_template', $v, $page, self::template(...), false, '', true),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function saveCompetitor(Input $form): array
    {
        $id = $form->int('competitor', 0, 0);
        $fields = RequestFields::posted('competitor_fields');
        $fields['is_own'] = ($fields['is_own'] ?? '') === '1';
        $fields['active'] = ($fields['active'] ?? '1') === '1';
        try {
            MarketServices::instance()->competitorService()->save($form->text('property', 64), $id > 0 ? $id : null, $fields, get_current_user_id());
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused));

            return ['tab' => 'competitors'] + ($id > 0 ? ['edit' => $id] : []);
        }
        Notice::flash(Notice::SUCCESS, __('Competitor saved; the market by night was updated.', 'ritmo-revenue'));

        return ['tab' => 'competitors'];
    }

    /**
     * @return array<string, string>
     */
    private static function archive(Input $form): array
    {
        try {
            MarketServices::instance()->competitorService()->archive($form->text('property', 64), $form->int('competitor', 0, 1), get_current_user_id());
            Notice::flash(Notice::SUCCESS, __('Competitor archived: it no longer counts; its prices are kept.', 'ritmo-revenue'));
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused));
        }

        return ['tab' => 'competitors'];
    }

    /**
     * @return array<string, string>
     */
    private static function createMissing(Input $form): array
    {
        $names = array_values(array_filter(array_map(static fn (mixed $n): string => is_string($n) ? $n : '', RequestFields::posted('names')), 'strlen'));
        try {
            $created = MarketServices::instance()->competitorService()->createMissing($form->text('property', 64), $names, get_current_user_id());
            /* translators: %d: number of competitors */
            Notice::flash(Notice::SUCCESS, sprintf(_n('%d competitor created. Upload the file again to import its prices.', '%d competitors created. Upload the file again to import their prices.', $created, 'ritmo-revenue'), $created));
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused));
        }

        return ['tab' => 'import'];
    }

    /**
     * @return array<string, string>
     */
    private static function addSample(Input $form): array
    {
        try {
            MarketServices::instance()->import()->addOne($form->text('property', 64), [
                'competitor' => $form->text('sample_competitor', 64),
                'check_in' => $form->text('check_in', 10),
                'check_out' => $form->text('check_out', 10),
                'price' => $form->text('price', 16),
                'price_basis' => $form->choice('price_basis', ['total', 'per_night'], 'per_night'),
                'room' => $form->choice('room', ['cheapest', 'comparable'], 'cheapest'),
                'available' => $form->bool('available') ? '1' : '0',
            ], get_current_user_id());
            Notice::flash(Notice::SUCCESS, __('Price recorded; the market by night was updated.', 'ritmo-revenue'));
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused));
        }

        return ['tab' => 'prices'];
    }

    /**
     * @return array<string, string>
     */
    private static function preview(Input $form): array
    {
        $propertyId = $form->text('property', 64);
        $config = self::config();
        $max = MarketScreen::maxBytes($config);
        $file = $_FILES['prices_file'] ?? []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the action dispatcher
        try {
            $upload = MarketServices::instance()->uploads()->read(is_array($file) ? $file : [], $max, $config->csvMaxRows);
            $preview = MarketServices::instance()->import()->preview($propertyId, $upload, get_current_user_id());
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused, $max));

            return ['tab' => 'import'];
        }

        return ['tab' => 'import', 'preview' => $preview->token];
    }

    /**
     * @return array<string, string>
     */
    private static function import(Input $form): array
    {
        try {
            $result = MarketServices::instance()->import()->commit($form->text('property', 64), $form->text('token', 64), get_current_user_id());
        } catch (MarketRefused $refused) {
            Notice::flash(Notice::ERROR, MarketText::refusal($refused));

            return ['tab' => 'import'];
        }
        $details = $result['replaced'] > 0 ? [sprintf(/* translators: %d: number of prices */ _n('%d price was already known and was updated.', '%d prices were already known and were updated.', $result['replaced'], 'ritmo-revenue'), $result['replaced'])] : [];
        Notice::flash(Notice::SUCCESS, sprintf(
            /* translators: 1: number of prices, 2: number of nights */
            _n('%1$d price recorded; market values updated for %2$d nights.', '%1$d prices recorded; market values updated for %2$d nights.', $result['imported'], 'ritmo-revenue'),
            $result['imported'],
            $result['nights']
        ), $details);

        return ['tab' => 'summary'];
    }

    /**
     * @return array<string, string>
     */
    private static function cancel(Input $form): array
    {
        MarketServices::instance()->import()->cancel($form->text('property', 64), $form->text('token', 64), get_current_user_id());
        Notice::flash(Notice::SUCCESS, __('Import cancelled: nothing was imported.', 'ritmo-revenue'));

        return ['tab' => 'import'];
    }

    /**
     * @return array<string, string|int>
     */
    private static function export(Input $form): array
    {
        $services = MarketServices::instance()->services();
        $property = $services->properties()->find($form->text('property', 64));
        if ($property === null) {
            Notice::flash(Notice::ERROR, __('Choose an existing property.', 'ritmo-revenue'));

            return ['tab' => 'export', 'failed' => 1];
        }
        $kind = $form->choice('kind', ['ruler', 'samples', 'competitors'], 'ruler');
        $from = $form->date('from') ?? $services->clock()->today($property->timezone);
        $to = $form->date('to') ?? Days::add($from, 89);
        $export = MarketServices::instance()->export();
        $rules = $services->ruleSets()->current() ?? RuleSet::defaults();
        $name = sprintf('market-%s-%s-%s.csv', $kind, $property->id, Days::key($from));
        match ($kind) {
            'samples' => CsvWriter::send($name, MarketExport::SAMPLES_HEADER, $export->samples($property->id, ['from' => $from, 'to' => $to])),
            'competitors' => CsvWriter::send($name, MarketExport::COMPETITORS_HEADER, $export->competitors($property->id)),
            default => CsvWriter::send($name, MarketExport::RULER_HEADER, $export->ruler($property->id, $property->currency, $from, $to, static fn (DateTimeImmutable $n): string => $rules->nightType($n)->value)),
        };

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function template(Input $form): array
    {
        CsvWriter::send('market-prices-example.csv', self::TEMPLATE_HEADER, self::TEMPLATE);

        return [];
    }

    private static function config(): MarketConfig
    {
        try {
            return MarketSettings::of(MarketServices::instance()->services()->ruleSets()->current());
        } catch (InvalidConfig) {
            return MarketConfig::neutral();
        }
    }
}
