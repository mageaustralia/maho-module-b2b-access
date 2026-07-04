<?php

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MageAustralia\B2bAccess\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use MageAustralia\B2bAccess\Api\Provider\B2bAccessConfigProvider;

/**
 * Discovery endpoint for the B2B Access module.
 *
 * The storefront's `src/plugins/b2b-access/sync.ts` probes this at sync time.
 * A 200 response tells the storefront the module is active on the backend and
 * hands over the config it needs to render gate UX (login prompts, hidden-price
 * message). A 404 means the module isn't installed and the storefront's plugin
 * no-ops.
 *
 * Deliberately narrow surface: only what the storefront needs to render, not
 * what an admin sees. Admin-only fields (email templates, category IDs used
 * only for rule evaluation) stay off the wire.
 */
#[ApiResource(
    shortName: 'B2bAccessConfig',
    description: 'B2B Access module frontend configuration (gate messages, matrix summary)',
    provider: B2bAccessConfigProvider::class,
    operations: [
        new Get(
            uriTemplate: '/b2b/access/config',
            description: 'Effective B2B Access config for the current store, for storefront rendering.',
            security: 'true',
        ),
    ],
)]
class B2bAccessConfig
{
    #[ApiProperty(identifier: true, writable: false)]
    public string $id = 'b2b-access';

    /** Master switch. `false` means every other field is meaningless. */
    #[ApiProperty(writable: false)]
    public bool $enabled = false;

    /** Whether guests get redirected off every non-exempt page. */
    #[ApiProperty(writable: false)]
    public bool $requireLogin = false;

    /** Whether prices are hidden site-wide (subject to activation matrix). */
    #[ApiProperty(writable: false)]
    public bool $hidePrice = false;

    /** Whether add-to-cart is refused server-side too (belt-and-braces). */
    #[ApiProperty(writable: false)]
    public bool $blockPurchase = false;

    /** Message rendered on a login wall (redirect target). */
    #[ApiProperty(writable: false)]
    public ?string $loginMessage = null;

    /** Message rendered in place of a hidden price. */
    #[ApiProperty(writable: false)]
    public ?string $hiddenPriceMessage = null;

    /** Redirect target for guests hitting a gated page. Relative path. */
    #[ApiProperty(writable: false)]
    public ?string $loginRedirectUrl = null;
}
