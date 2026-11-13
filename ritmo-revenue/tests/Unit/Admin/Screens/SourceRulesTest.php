<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Screens;

require_once __DIR__ . '/WpStubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Admin\AdminActions;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Screens\Screens;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\SourceScan;
use Ritmo\WordPress\Roles;

/**
 * Rules of the central screens that hold by construction and are checked on the source (admin
 * spec 4.1, 4.5, 6.1, 10.3): protected actions and routes, no inline script, nothing from another
 * host, form input read in one place, and no path from a screen to an external system.
 */
final class SourceRulesTest extends TestCase
{
    private const FOLDERS = ['src/Admin/Screens', 'src/Admin/Components', 'src/Rest/Screens', 'src/Application/Dashboard', 'src/Application/Grid', 'src/Infrastructure/Db/Query/Screens'];

    /** Actions that change or remove one object: their nonce names that object. */
    private const PER_OBJECT = ['ritmo_approval_decide', 'ritmo_alert_acknowledge', 'ritmo_alert_resolve', 'ritmo_wave_release', 'ritmo_freeze_release', 'ritmo_todo_close', 'ritmo_todo_reopen'];

    public function testEveryFileDeclaresStrictTypes(): void
    {
        $files = $this->sources();
        self::assertGreaterThan(90, count($files));
        foreach ($files as $file) {
            self::assertTrue(SourceScan::declaresStrictTypes((string) file_get_contents($file)), $file);
        }
    }

    public function testEveryFormActionIsNamedCheckedAndUnique(): void
    {
        $actions = Screens::actions();
        $names = array_map(static fn ($a): string => $a->action, $actions);
        self::assertSame(array_values(array_unique($names)), $names, 'an action is registered once');
        self::assertSame([], array_intersect($names, AdminActions::names()), 'a new action never takes the name of an old one');

        $capabilities = [...Roles::CAPABILITIES, Capability::MANAGE_SAFETY];
        $pages = array_column(Menu::entries(), 'slug');
        foreach ($actions as $action) {
            self::assertMatchesRegularExpression('/^ritmo_[a-z_]+$/', $action->action);
            self::assertContains($action->capability, $capabilities, $action->action);
            self::assertContains($action->page, $pages, $action->action);
            self::assertSame(in_array($action->action, self::PER_OBJECT, true), $action->perObjectNonce, $action->action);
            if ($action->perObjectNonce) {
                self::assertNotSame('', $action->objectField, $action->action);
                self::assertSame($action->action . ':42', $action->nonceAction('42'));
            }
        }
        $by = array_combine($names, $actions);
        self::assertSame(Capability::MANAGE_SAFETY, $by['ritmo_wizard_confirm_safety']->capability, 'safety limits need their own capability');
        self::assertSame(Capability::APPROVE, $by['ritmo_approval_decide']->capability);
        self::assertSame(Capability::MANAGE_CONNECTIONS, $by['ritmo_download_support_bundle']->capability);
        self::assertTrue($by['ritmo_changes_export']->download);
    }

    public function testTheHandlerChecksMethodCapabilityAndNonceBeforeTheForm(): void
    {
        $source = $this->read('src/Admin/Screens/ScreenActions.php');
        $handle = substr($source, (int) strpos($source, 'public static function handle('));
        $method = strpos($handle, "'REQUEST_METHOD'");
        $can = strpos($handle, 'current_user_can($action->capability)');
        $nonce = strpos($handle, 'check_admin_referer(');
        $run = strpos($handle, '($action->handler)(');
        self::assertIsInt($method);
        self::assertIsInt($can);
        self::assertIsInt($nonce);
        self::assertIsInt($run);
        self::assertLessThan($can, $method);
        self::assertLessThan($nonce, $can);
        self::assertLessThan($run, $nonce);
    }

    public function testEveryFormOfTheScreensCarriesTheNonceOfAKnownAction(): void
    {
        $known = [...array_map(static fn ($a): string => $a->action, Screens::actions()), ...AdminActions::names()];
        $templates = SourceScan::phpFiles(Fixtures::pluginRoot() . '/templates/admin/screens');
        self::assertGreaterThan(20, count($templates));
        $seen = 0;
        foreach ($templates as $file) {
            $template = (string) file_get_contents($file);
            self::assertSame(substr_count($template, 'name="action"'), substr_count($template, 'wp_nonce_field('), basename($file));
            self::assertSame(0, preg_match('/\son[a-z]+\s*=/i', $template), basename($file) . ': no inline event handler');
            self::assertSame(0, preg_match('#(src|href|action)="https?://#i', $template), basename($file) . ': nothing from another host');
            preg_match_all("/wp_nonce_field\\('(ritmo_[a-z_]+)(:?)'/", $template, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, $colon]) {
                ++$seen;
                self::assertContains($name, $known, basename($file));
                self::assertSame(in_array($name, self::PER_OBJECT, true), $colon === ':', basename($file) . ' ' . $name);
            }
        }
        self::assertGreaterThan(15, $seen);
    }

    public function testTheAssetsLoadNothingFromAnotherHostAndBuildNoMarkupFromText(): void
    {
        $script = $this->read('assets/screens.js');
        $style = $this->read('assets/screens.css');
        self::assertSame(0, preg_match('#https?://#', $script . $style));
        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('eval(', $script);
        self::assertStringNotContainsString('@import', $style);
        self::assertStringContainsString(':focus-visible', $style);
        self::assertStringContainsString('prefers-reduced-motion', $style);
    }

    public function testFormInputIsReadInOnePlace(): void
    {
        $allowed = [
            'src/Admin/Components/Input.php' => ['$_GET', '$_POST'],
            'src/Admin/Screens/WizardActions.php' => ['$_FILES'],
            'src/Admin/Screens/ScreenActions.php' => ['$_SERVER'],
        ];
        $root = Fixtures::pluginRoot() . '/';
        foreach ($this->sources() as $file) {
            preg_match_all('/\$_(GET|POST|REQUEST|FILES|SERVER|COOKIE)\b/', (string) file_get_contents($file), $matches);
            $relative = substr($file, strlen($root));
            $found = array_values(array_unique($matches[0]));
            self::assertSame([], array_diff($found, $allowed[$relative] ?? []), $relative);
        }
    }

    public function testEveryRouteOfTheScreensChecksACapability(): void
    {
        $source = $this->read('src/Rest/Screens/ScreenRoutes.php');
        $routes = substr_count($source, 'register_rest_route(');
        self::assertSame(5, $routes);
        self::assertSame($routes, preg_match_all("/'permission_callback' => RestRoutes::allowed\\(Roles::[A-Z_]+\\)/", $source));
        self::assertStringNotContainsString('__return_true', $source);
    }

    public function testNoScreenReachesAnExternalSystemOrTheWriteGate(): void
    {
        $forbidden = ['WriteGate', 'WriteToken', 'StepSender', '->writeCalendar(', '->writeOverrides(', '->deleteOverrides(', '->updateListing(', '->requestRecalculation(', 'wp_remote_', 'curl_'];
        foreach ($this->sources() as $file) {
            if (str_ends_with($file, '.php') === false) {
                continue;
            }
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString($word, $source, basename($file) . ' mentions ' . $word);
            }
        }
    }

    /**
     * The PHP files of the screens, their read models and templates.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $root = Fixtures::pluginRoot();
        $files = [];
        foreach ([...self::FOLDERS, 'templates/admin/screens'] as $folder) {
            array_push($files, ...SourceScan::phpFiles($root . '/' . $folder));
        }

        return $files;
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents(Fixtures::pluginRoot() . '/' . $relative);
    }
}
