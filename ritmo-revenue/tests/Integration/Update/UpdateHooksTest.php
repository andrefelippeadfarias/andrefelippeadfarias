<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Update;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Infrastructure\Update\UpdateConstants;
use Ritmo\Infrastructure\Update\UpdateHooks;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;
use Ritmo\Tests\Unit\Application\Update\SignedRelease;
use stdClass;
use WP_Error;

require_once __DIR__ . '/wp-stubs.php';

/**
 * The WordPress update filters over the update checker: what WordPress is offered, what the
 * details window shows, and that WordPress gets a package for this plugin only after its
 * verification (a temporary file of exactly the signed bytes) and an error otherwise.
 */
final class UpdateHooksTest extends TestCase
{
    private const BASENAME = 'ritmo-revenue/ritmo-revenue.php';

    private SignedRelease $keys;

    private ScriptedHttpClient $http;

    private UpdateHooks $hooks;

    private string $zip;

    protected function setUp(): void
    {
        $this->keys = new SignedRelease();
        $this->http = new ScriptedHttpClient();
        $this->zip = SignedRelease::zip('the 1.2.0 package');
        UpdateHooksStubs::$filters = [];
        $this->hooks = $this->hooks($this->keys->settings());
    }

    protected function tearDown(): void
    {
        foreach (UpdateHooksStubs::$tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        UpdateHooksStubs::$tempFiles = [];
    }

    private function hooks(UpdateSettings $settings): UpdateHooks
    {
        $checker = new UpdateChecker($this->http, new ArrayStore(), new MovableClock('2026-11-15T10:00:00Z'), new RecordingLogger(), $settings);

        return UpdateHooks::register(static fn (): UpdateChecker => $checker, static fn (string $reason): string => 'refused: ' . $reason);
    }

    private function publish(string $envelope): void
    {
        $this->http->queue(new HttpResponse(200, ['Content-Type' => 'application/json'], $envelope));
    }

    private static function transient(): stdClass
    {
        $transient = new stdClass();
        $transient->checked = [self::BASENAME => '1.1.0', 'other/other.php' => '2.0'];
        $transient->response = [
            // A plugin of the same name elsewhere must never be offered in its place.
            self::BASENAME => (object) ['slug' => 'ritmo-revenue', 'new_version' => '7.0.0', 'package' => 'https://downloads.wordpress.org/plugin/ritmo-revenue.7.0.0.zip'],
            'other/other.php' => (object) ['slug' => 'other', 'new_version' => '2.1'],
        ];
        $transient->no_update = [];

        return $transient;
    }

    public function testTheThreeFiltersAreRegistered(): void
    {
        self::assertSame(['pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_pre_download'], array_keys(UpdateHooksStubs::$filters));
        self::assertSame(3, UpdateHooksStubs::$filters['plugins_api'][2]);
        self::assertSame(4, UpdateHooksStubs::$filters['upgrader_pre_download'][2]);
    }

    public function testTheVerifiedReleaseIsOfferedInPlaceOfAnyOther(): void
    {
        $this->publish($this->keys->envelope($this->zip, ['requires' => '6.5', 'requires_php' => '8.1', 'tested' => '6.8']));

        $transient = $this->hooks->offer(self::transient());

        $offer = $transient->response[self::BASENAME];
        self::assertSame('1.2.0', $offer->new_version);
        self::assertSame(SignedRelease::PACKAGE_URL, $offer->package);
        self::assertSame('ritmo-revenue', $offer->slug);
        self::assertSame(self::BASENAME, $offer->plugin);
        self::assertSame('8.1', $offer->requires_php);
        self::assertSame('2.1', $transient->response['other/other.php']->new_version, 'other plugins are left alone');
        self::assertArrayNotHasKey(self::BASENAME, $transient->no_update);
    }

    public function testWithoutAVerifiedReleaseNothingIsOfferedForThisPlugin(): void
    {
        $this->publish((new SignedRelease())->envelope($this->zip));

        $transient = $this->hooks->offer(self::transient());

        self::assertArrayNotHasKey(self::BASENAME, $transient->response, 'the foreign offer is removed too');
        self::assertSame('1.1.0', $transient->no_update[self::BASENAME]->new_version);
        self::assertSame('', $transient->no_update[self::BASENAME]->package);
        self::assertSame(false, $this->hooks->offer(false));
    }

    public function testTheDetailsWindowShowsTheVerifiedReleaseEscaped(): void
    {
        $this->publish($this->keys->envelope($this->zip, ['changelog' => ['<script>alert(1)</script> fixed', 'Signed updates.']]));
        $this->hooks->offer(self::transient());

        $details = $this->hooks->details(false, 'plugin_information', (object) ['slug' => 'ritmo-revenue']);

        self::assertIsObject($details);
        self::assertSame('1.2.0', $details->version);
        self::assertSame(SignedRelease::PACKAGE_URL, $details->download_link);
        self::assertStringNotContainsString('<script>', $details->sections['changelog']);
        self::assertStringContainsString('&lt;script&gt;', $details->sections['changelog']);
        self::assertFalse($this->hooks->details(false, 'plugin_information', (object) ['slug' => 'another-plugin']));
        self::assertSame('kept', $this->hooks->details('kept', 'query_plugins', (object) ['slug' => 'ritmo-revenue']));
    }

    public function testWordPressGetsTheVerifiedPackageAsAFile(): void
    {
        $this->publish($this->keys->envelope($this->zip));
        $this->hooks->offer(self::transient());
        $this->http->queue(new HttpResponse(200, ['Content-Type' => 'application/zip'], $this->zip));

        $file = $this->hooks->download(false, SignedRelease::PACKAGE_URL, null, ['plugin' => self::BASENAME]);

        self::assertIsString($file);
        self::assertSame($this->zip, file_get_contents($file));
    }

    public function testATamperedPackageNeverReachesWordPress(): void
    {
        $this->publish($this->keys->envelope($this->zip));
        $this->hooks->offer(self::transient());
        $this->http->queue(new HttpResponse(200, [], substr($this->zip, 0, -1) . 'X'));

        $result = $this->hooks->download(false, SignedRelease::PACKAGE_URL, null, ['plugin' => self::BASENAME]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ritmo_update_refused', $result->get_error_code());
        self::assertSame('refused: hash', $result->get_error_message());
        self::assertSame([], array_filter(UpdateHooksStubs::$tempFiles, 'is_file'), 'no file is handed to WordPress');
    }

    public function testAnyOtherPackageForThisPluginIsRefusedWithoutDownloading(): void
    {
        $this->publish($this->keys->envelope($this->zip));
        $this->hooks->offer(self::transient());

        $result = $this->hooks->download(false, 'https://downloads.wordpress.org/plugin/ritmo-revenue.7.0.0.zip', null, ['plugin' => self::BASENAME]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('refused: unknown_package', $result->get_error_message());
        self::assertSame(['GET /ritmo-revenue/latest.json'], $this->http->lines());
    }

    public function testAPackageOfTheUpdateServerIsCheckedWhateverWordPressSaysItIs(): void
    {
        $this->publish($this->keys->envelope($this->zip));
        $this->hooks->offer(self::transient());

        $result = $this->hooks->download(false, 'https://updates.example.com/ritmo-revenue/other.zip', null, []);

        self::assertInstanceOf(WP_Error::class, $result);
    }

    public function testOtherDownloadsAreLeftToWordPress(): void
    {
        self::assertFalse($this->hooks->download(false, 'https://downloads.wordpress.org/plugin/other.2.1.zip', null, ['plugin' => 'other/other.php']));
        self::assertFalse($this->hooks->download(false, '/tmp/uploaded-by-hand.zip', null, ['type' => 'plugin', 'action' => 'install']));
        self::assertSame('/already/decided.zip', $this->hooks->download('/already/decided.zip', SignedRelease::PACKAGE_URL, null, ['plugin' => self::BASENAME]));
        self::assertSame([], $this->http->lines());
    }

    public function testAnOffChannelOffersNothingAndInstallsNothingForThisPlugin(): void
    {
        UpdateHooksStubs::$filters = [];
        $hooks = $this->hooks(UpdateConstants::from('', '', '1.1.0', self::BASENAME, false));

        $transient = $hooks->offer(self::transient());

        self::assertArrayNotHasKey(self::BASENAME, $transient->response);
        self::assertInstanceOf(WP_Error::class, $hooks->download(false, 'https://downloads.wordpress.org/plugin/ritmo-revenue.7.0.0.zip', null, ['plugin' => self::BASENAME]));
        self::assertSame([], $this->http->lines());
    }

    public function testTheConstantsNeverTurnOnAChannelWithABadKey(): void
    {
        $settings = UpdateConstants::from('https://updates.example.com/latest.json', 'not-a-key', '1.1.0', self::BASENAME, false);
        self::assertFalse($settings->enabled());
        self::assertSame('ritmo-revenue', $settings->slug);

        $plainHttp = UpdateConstants::from('http://updates.example.com/latest.json', $this->keys->public, '1.1.0', self::BASENAME, false);
        self::assertFalse($plainHttp->enabled(), 'plain http only for the local simulator in development mode');

        $on = UpdateConstants::from('https://updates.example.com/latest.json', $this->keys->public, '1.1.0', 'renamed-folder/ritmo-revenue.php', false);
        self::assertTrue($on->enabled());
        self::assertSame('ritmo-revenue', $on->slug, 'the product slug, whatever the folder');
        self::assertSame('renamed-folder/ritmo-revenue.php', $on->basename);
    }
}
