<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use InvalidArgumentException;
use Ritmo\Connectors\Licence\HttpLicenceServer;
use Ritmo\Connectors\Licence\LicenceServer;
use Ritmo\Connectors\Licence\LicenceSignature;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\LicenceState;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Licence\CheckDue;
use Ritmo\Domain\Licence\LicenceEvaluator;
use Ritmo\Domain\Licence\LicenceKey;
use Ritmo\Domain\Licence\LicenceRecord;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Domain\Licence\LicenceVerdict;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Secrets\SecretStatus;
use Ritmo\Infrastructure\Secrets\SecretUnavailable;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use SensitiveParameter;
use Throwable;

/**
 * The licence of the site: the key (in the secrets vault, write-only for the screen), the answers
 * of the provider (the record, in the settings) and where the licence stands now.
 *
 * The provider is asked only here: when a key is saved (activate), from the licence screen
 * ("Check now", at most every 30 seconds), by the scheduled daily check (daily()) and when the key
 * is removed (deactivate). Never during a pricing run: the run asks verdict(), which reads what is
 * stored. A provider out of reach never takes anything away before the grace period ends.
 */
final class LicenceService implements LicenceVerdictSource
{
    public const VAULT_NAME = 'licence_key';

    public const STATE_KEY = 'licence_state';

    public const COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly SodiumVault $vault,
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ProviderChoice $providers,
        private readonly LicenceSettings $settings,
    ) {
    }

    /**
     * Where the licence stands, from the stored record as far as it can be trusted: a record that
     * is not for the saved key counts as nothing answered, and an answer "valid" of the licence
     * server counts only while its signature (kept with the record) still verifies for the saved
     * key and this site. A record edited in the database therefore never grants a licence.
     */
    public function verdict(): LicenceVerdict
    {
        $key = $this->savedKey();
        $record = $key === null ? new LicenceRecord() : $this->trusted($this->record(), $key);

        return (new LicenceEvaluator($this->settings->grace))->evaluate($record, $this->clock->now(), $this->settings->devMode);
    }

    public function record(): LicenceRecord
    {
        return LicenceRecord::fromArray($this->store->getFresh(self::STATE_KEY));
    }

    /**
     * Whether a key is saved and when; never the key.
     */
    public function keyStatus(): SecretStatus
    {
        return $this->vault->status(self::VAULT_NAME);
    }

    public function settings(): LicenceSettings
    {
        return $this->settings;
    }

    /**
     * Saves a key (replacing the saved one, which is freed on its provider first) and activates
     * it on this site.
     *
     * @throws LicenceRefused when the text cannot be a key or the vault cannot store it
     */
    public function save(#[SensitiveParameter] string $typed): LicenceVerdict
    {
        try {
            $key = LicenceKey::parse($typed);
        } catch (InvalidArgumentException $refused) {
            throw new LicenceRefused(LicenceRefused::BAD_KEY, $refused->getMessage());
        }
        $old = $this->savedKey();
        if ($old !== null && !$this->record()->isFor($key)) {
            $this->free($old);
        }
        try {
            $this->vault->put(self::VAULT_NAME, $key->value());
        } catch (SecretUnavailable $unavailable) {
            throw new LicenceRefused(LicenceRefused::VAULT, $unavailable->getMessage());
        }
        $record = $this->record()->isFor($key) ? $this->record() : LicenceRecord::forKey($key);
        $this->store->set(self::STATE_KEY, $record->toArray());
        $this->logger->info('A licence key was saved.', ['licence' => $key->fingerprint()]);

        return $this->ask($key, $record, true);
    }

    /**
     * "Check now" on the licence screen.
     *
     * @throws LicenceRefused when the last check was less than COOLDOWN_SECONDS ago
     */
    public function check(): LicenceVerdict
    {
        $key = $this->savedKey();
        if ($key === null) {
            return $this->verdict();
        }
        $record = $this->recordFor($key);
        $last = $record->lastAttemptAt;
        $elapsed = $last === null ? PHP_INT_MAX : $this->clock->now()->getTimestamp() - $last->getTimestamp();
        if ($elapsed >= 0 && $elapsed < self::COOLDOWN_SECONDS) {
            throw new LicenceRefused(LicenceRefused::COOLDOWN, sprintf('The licence was checked moments ago; try again in %d seconds.', self::COOLDOWN_SECONDS - $elapsed));
        }

        return $this->ask($key, $record, false);
    }

    /**
     * The scheduled check (twice a day; it asks only when CheckDue says so). Never throws.
     *
     * @return LicenceVerdict|null the verdict after asking; null when nothing was due
     */
    public function daily(): ?LicenceVerdict
    {
        try {
            $key = $this->savedKey();
            if ($key === null) {
                return null;
            }
            $record = $this->recordFor($key);
            if (!CheckDue::isDue($record, $this->clock->now())) {
                return null;
            }

            return $this->ask($key, $record, false);
        } catch (Throwable $failure) {
            $this->logger->warning('The scheduled licence check failed.', ['error' => $failure::class]);

            return null;
        }
    }

    /**
     * Frees the key on its provider (when it can be reached) and forgets it on this site.
     *
     * @return bool true when the provider confirmed the key was freed
     */
    public function remove(): bool
    {
        $key = $this->savedKey();
        $freed = $key !== null && $this->free($key);
        $this->vault->forget(self::VAULT_NAME);
        $this->store->delete(self::STATE_KEY);
        $this->logger->info('The licence key was removed.', ['freed_on_provider' => $freed]);

        return $freed;
    }

    private function ask(LicenceKey $key, LicenceRecord $record, bool $activate): LicenceVerdict
    {
        $provider = $this->providers->for($key);
        $answer = $this->answer($provider, $key, $activate);
        $after = $record->withAnswer($provider->id(), $answer->status, $answer->expiresAt, $answer->checkedAt, $answer->message, $answer->proof);
        $this->store->set(self::STATE_KEY, $after->toArray());
        $verdict = $this->verdict();
        $level = $verdict->standing === LicenceStanding::Active ? 'info' : 'warning';
        $this->logger->log($level, 'The licence was checked.', [
            'provider' => $provider->id(),
            'answer' => $answer->status,
            'standing' => $verdict->standing->value,
            'reason' => $verdict->reason,
            'licence' => $key->fingerprint(),
        ]);

        return $verdict;
    }

    private function answer(LicenceServer $provider, LicenceKey $key, bool $activate): LicenceState
    {
        try {
            return $activate ? $provider->activate($key->value(), $this->settings->siteUrl) : $provider->check($key->value(), $this->settings->siteUrl);
        } catch (Throwable) {
            // A provider never throws by contract; one that does counts as not reached.
            return new LicenceState(LicenceState::UNREACHABLE, null, $this->clock->now(), '');
        }
    }

    private function free(LicenceKey $key): bool
    {
        try {
            return $this->providers->for($key)->deactivate($key->value(), $this->settings->siteUrl);
        } catch (Throwable) {
            return false;
        }
    }

    private function savedKey(): ?LicenceKey
    {
        if (!$this->vault->has(self::VAULT_NAME)) {
            return null;
        }
        try {
            return LicenceKey::parse($this->vault->reveal(self::VAULT_NAME));
        } catch (SecretUnavailable | InvalidArgumentException) {
            return null;
        }
    }

    private function trusted(LicenceRecord $record, LicenceKey $key): LicenceRecord
    {
        if (!$record->isFor($key)) {
            return LicenceRecord::forKey($key);
        }
        if ($record->lastValidAt === null || $record->providerId === LicenceRecord::TEST_PROVIDER) {
            // Nothing to prove, or the test provider, whose answers LicenceEvaluator accepts only in development mode.
            return $record;
        }
        $proof = $record->proof;
        $checkedAt = $proof?->checkedInstant();
        if ($proof === null || $checkedAt === null || $record->providerId !== HttpLicenceServer::ID
            || ($proof->expiresAt !== '' && $proof->expiresInstant() === null)
            || !LicenceSignature::verifies($this->settings->publicKeys, $proof, $key->value(), $this->settings->siteUrl)) {
            return $record->withoutValid();
        }

        $signedEnd = $proof->expiresInstant();
        $graceAfterCheck = $this->settings->grace->endsAt($checkedAt);
        $latest = $signedEnd !== null && $signedEnd > $checkedAt && $signedEnd < $graceAfterCheck ? $signedEnd : $graceAfterCheck;

        return $record->withSignedValid($checkedAt, $signedEnd, $latest);
    }

    /**
     * The stored record when it belongs to the saved key; a fresh one otherwise (a key saved by
     * another copy of the site, a damaged record).
     */
    private function recordFor(LicenceKey $key): LicenceRecord
    {
        $record = $this->record();
        if ($record->isFor($key)) {
            return $record;
        }
        $fresh = LicenceRecord::forKey($key);
        $this->store->set(self::STATE_KEY, $fresh->toArray());

        return $fresh;
    }
}
