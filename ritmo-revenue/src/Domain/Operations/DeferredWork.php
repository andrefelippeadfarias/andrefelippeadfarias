<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * What a light run never does and leaves to the next full run (reference SKILL.md: the light
 * routine "não reconstrói âncoras", "não varre relações", stay beyond the first week waits).
 */
enum DeferredWork: string
{
    /** Pace steps of nights without a new sale (the daily descents). */
    case PaceWithoutSale = 'pace_without_sale';
    /** Pace steps of rooms without recalculation quota for the confirmation. */
    case PaceWithoutQuota = 'pace_without_quota';
    /** The sweep of the room relations over the whole horizon. */
    case RelationsSweep = 'relations_sweep';
    /** Sold-out protection of nights outside the light filter. */
    case ProtectionOutsideFilter = 'protection_outside_filter';
    /** The inventory audit and stay plan beyond the orphan window. */
    case StayBeyondWindow = 'stay_beyond_window';
    /** Market anchors. */
    case Anchors = 'anchors';
    /** Listing settings (minimum, base, maximum): never in a light run. */
    case ListingSettings = 'listing_settings';
    /** The weekly review. */
    case WeeklyReview = 'weekly_review';
}
