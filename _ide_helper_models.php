<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property string $actor_id
 * @property ContentAccess $access
 * @property string $set_by_actor_id
 * @property CarbonImmutable $set_at
 * @property string|null $reason
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentAccessOverride newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentAccessOverride newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentAccessOverride query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperContentAccessOverride {}
}
