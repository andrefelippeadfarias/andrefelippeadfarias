<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Editors;

require_once __DIR__ . '/stubs.php';
require_once dirname(__DIR__) . '/Screens/WpStubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\AdminScreens;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Editors\EditorScreens;
use Ritmo\Admin\Market\MarketActions;
use Ritmo\Admin\Screens\Screens;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;

/**
 * Rules of the Rules, Orders and Market screens that hold by construction and are checked on the
 * source (spec 05, 4.1, 6.1-6.6, 11.3): every action named, protected and registered once, the
 * destructive ones with a nonce per object, every form with the nonce of a known action, every
 * REST route behind a capability and the REST nonce, form input and uploads read in known
 * places, nothing loaded from another host, and no path from these screens to an external
 * system or the write gate.
 */
final class EditorsSourceTest extends TestCase
{
    private const FOLDERS = [
        'src/Admin/Editors', 'src/Admin/Market', 'src/Rest/Editors', 'src/Rest/Market', 'src/Application/Rules',
        'src/Application/Market', 'src/Domain/Market/Series', 'src/Domain/Market/Csv', 'templates/admin/editors', 'templates/admin/market',
    ];

    private const REPOSITORIES = [
        'CompetitorRepository', 'EditorEventRepository', 'LadderReferenceRepository', 'MarketImportRepository',
        'MarketSeriesRepository', 'OrderLedgerRepository', 'StaySampleRepository',
    ];

    /** Actions that change or remove one object: their nonce names that object. */
    private const PER_OBJECT = ['ritmo_rules_restore', 'ritmo_order_end', 'ritmo_order_freeze_release', 'ritmo_competitor_archive'];

    /** Actions that answer a file instead of a screen. */
    private const DOWNLOADS = ['ritmo_rules_export', 'ritmo_market_export', 'ritmo_market_template'];

    public function testEveryFileDeclaresStrictTypes(): void
    {
        $files = $this->sources();
        self::assertGreaterThan(120, count($files));
        foreach ($files as $file) {
            self::assertTrue(SourceScan::declaresStrictTypes((string) file_get_contents($file)), $file);
        }
    }

    public function testEveryFormActionIsNamedCheckedAndRegisteredOnce(): void
    {
        $actions = [...EditorScreens::actions(), ...MarketActions::actions()];
        $names = array_map(static fn ($a): string => $a->action, $actions);
        self::assertSame(array_values(array_unique($names)), $names, 'an action is registered once');
        $all = array_map(static fn ($a): string => $a->action, AdminScreens::actions());
        self::assertSame(array_values(array_unique($all)), $all, 'no name taken from the other screens');

        $pages = array_column(Menu::entries(), 'slug');
        foreach ($actions as $action) {
            self::assertMatchesRegularExpression('/^ritmo_[a-z_]+$/', $action->action);
            self::assertContains($action->capability, Capability::ALL, $action->action);
            self::assertContains($action->page, [Menu::RULES, Menu::ORDERS, Menu::MARKET], $action->action);
            self::assertContains($action->page, $pages, $action->action);
            self::assertSame(in_array($action->action, self::PER_OBJECT, true), $action->perObjectNonce, $action->action);
            self::assertSame(in_array($action->action, self::DOWNLOADS, true), $action->download, $action->action);
            if ($action->perObjectNonce) {
                self::assertNotSame('', $action->objectField, $action->action);
            }
            if (!$action->download) {
                self::assertNotSame(Capability::VIEW, $action->capability, $action->action . ': a change needs more than seeing');
            }
        }
        $by = array_combine($names, $actions);
        self::assertSame(Capability::APPROVE, $by['ritmo_order_freeze_release']->capability, 'releasing a freeze is an approval');
        self::assertSame(Capability::MANAGE_RULES, $by['ritmo_market_csv_preview']->capability);
        self::assertSame(Capability::MANAGE_RULES, $by['ritmo_rules_save']->capability, 'safety fields are checked again by the use case');
    }

    public function testEveryFormCarriesTheNonceOfAKnownActionAndNoInlineScript(): void
    {
        $known = array_map(static fn ($a): string => $a->action, AdminScreens::actions());
        $seen = 0;
        foreach ($this->templates() as $file) {
            $template = (string) file_get_contents($file);
            self::assertSame(substr_count($template, 'name="action"'), substr_count($template, 'wp_nonce_field('), basename($file));
            self::assertSame(0, preg_match('/\son[a-z]+\s*=/i', $template), basename($file) . ': no inline event handler');
            self::assertSame(0, preg_match('#(src|href|action)="https?://#i', $template), basename($file) . ': nothing from another host');
            self::assertStringNotContainsString('<script', $template, basename($file));
            preg_match_all("/wp_nonce_field\\('(ritmo_[a-z_]+)(:?)'/", $template, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, $colon]) {
                ++$seen;
                self::assertContains($name, $known, basename($file));
                self::assertSame(in_array($name, self::PER_OBJECT, true), $colon === ':', basename($file) . ' ' . $name);
            }
            foreach (['method="get"', "method='get'"] as $get) {
                if (str_contains(strtolower($template), $get)) {
                    self::assertStringNotContainsString('wp_nonce_field(', self::getForms($template), basename($file) . ': a GET form changes nothing and carries no nonce');
                }
            }
        }
        self::assertGreaterThan(20, $seen);
    }

    public function testTheAssetsLoadNothingFromAnotherHostAndBuildNoMarkupFromText(): void
    {
        $script = $this->read('assets/editors.js');
        $style = $this->read('assets/editors.css');
        self::assertSame(0, preg_match('#https?://#', $script . $style));
        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('insertAdjacentHTML', $script);
        self::assertStringNotContainsString('eval(', $script);
        self::assertStringNotContainsString('@import', $style);
        self::assertStringContainsString(':focus-visible', $style);
        self::assertStringContainsString('prefers-reduced-motion', $style);
    }

    public function testFormInputAndUploadsAreReadInKnownPlacesOnly(): void
    {
        $allowed = [
            'src/Admin/Editors/RequestFields.php' => ['$_POST'],
            'src/Admin/Editors/PresetFile.php' => ['$_FILES'],
            'src/Admin/Market/MarketActions.php' => ['$_FILES'],
        ];
        $root = Fixtures::pluginRoot() . '/';
        foreach ($this->sources() as $file) {
            $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
            preg_match_all('/\$_(GET|POST|REQUEST|FILES|SERVER|COOKIE)\b/', (string) $code, $matches);
            $relative = substr($file, strlen($root));
            self::assertSame([], array_values(array_diff(array_unique($matches[0]), $allowed[$relative] ?? [])), $relative);
        }
        $upload = $this->read('src/Admin/Market/CsvUploadReader.php');
        self::assertStringNotContainsString('move_uploaded_file', $upload, 'the upload is read in place, never moved');
        self::assertStringNotContainsString('wp_handle_upload', $upload);
        self::assertStringContainsString('fgetcsv(', $upload);
    }

    public function testEveryRouteChecksACapabilityAndTheRestNonce(): void
    {
        foreach (['src/Rest/Editors/EditorRoutes.php' => 2, 'src/Rest/Market/MarketRoutes.php' => 7] as $file => $endpoints) {
            $source = $this->read($file);
            self::assertSame($endpoints, substr_count($source, "'methods' =>"), $file);
            self::assertSame($endpoints, preg_match_all("/'permission_callback' => RestRoutes::allowed\\(Roles::[A-Z_]+\\)/", $source), $file);
            self::assertStringNotContainsString('__return_true', $source);
        }
        $market = $this->read('src/Rest/Market/MarketRoutes.php');
        self::assertSame(4, substr_count($market, 'RestRoutes::allowed(Roles::MANAGE_RULES)'), 'every change of the market needs ritmo_manage_rules');
        self::assertSame(2, substr_count($this->read('src/Rest/Editors/EditorRoutes.php'), 'RestRoutes::allowed(Roles::VIEW)'), 'the rules routes only read');
    }

    public function testNoEditorReachesAnExternalSystemOrTheWriteGate(): void
    {
        $forbidden = ['WriteGate', 'WriteToken', 'StepSender', 'Connector', '->writeCalendar(', '->writeOverrides(', '->deleteOverrides(', '->updateListing(', '->requestRecalculation(', 'wp_remote_', 'curl_', 'file_get_contents(\'http', 'fsockopen'];
        foreach ([...$this->sources(), ...$this->repositories()] as $file) {
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString($word, $source, basename($file) . ' mentions ' . $word);
            }
        }
    }

    public function testTheDomainPartsStayPureAndTheRepositoriesUsePreparedStatements(): void
    {
        foreach ([...SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Domain/Market/Series'), ...SourceScan::phpFiles(Fixtures::pluginRoot() . '/src/Domain/Market/Csv')] as $file) {
            self::assertSame([], SourceScan::wordPressMentions((string) file_get_contents($file)), basename($file));
            self::assertSame(0, preg_match('/new DateTime(Immutable)?\(\s*\)|(?<!function |::|->)\b(time|date)\(/', (string) file_get_contents($file)), basename($file) . ': the clock comes from outside');
        }
        foreach ($this->repositories() as $file) {
            $source = (string) file_get_contents($file);
            self::assertSame(0, preg_match('/->(query|exec)\(\s*["\']?(SELECT|INSERT|UPDATE|DELETE)[^)]*\$/i', $source), basename($file) . ': no value glued into SQL');
            self::assertStringNotContainsString('$wpdb', $source, basename($file) . ': through the Database port only');
        }
    }

    /**
     * The PHP files of these screens, their use cases, domain parts and templates.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $files = [];
        foreach (self::FOLDERS as $folder) {
            array_push($files, ...SourceScan::phpFiles(Fixtures::pluginRoot() . '/' . $folder));
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function repositories(): array
    {
        return array_map(static fn (string $name): string => Fixtures::pluginRoot() . '/src/Infrastructure/Db/Repository/' . $name . '.php', self::REPOSITORIES);
    }

    /**
     * @return list<string>
     */
    private function templates(): array
    {
        $files = [...SourceScan::phpFiles(Fixtures::pluginRoot() . '/templates/admin/editors'), ...SourceScan::phpFiles(Fixtures::pluginRoot() . '/templates/admin/market')];
        self::assertGreaterThan(14, count($files));

        return $files;
    }

    /**
     * The text of the GET forms of a template.
     */
    private static function getForms(string $template): string
    {
        preg_match_all('#<form[^>]*method=["\']get["\'][^>]*>.*?</form>#is', $template, $forms);

        return implode("\n", $forms[0]);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(Fixtures::pluginRoot() . '/' . $relative);
    }
}
