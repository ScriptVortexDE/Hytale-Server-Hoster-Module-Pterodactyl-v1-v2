<?php

namespace HytaleHosting\Core\Support;

/**
 * The Blade layout the admin pages extend. Pterodactyl 1.x pages use the Panel's
 * own admin layout; 2.0 has a React admin area, so the module brings its own.
 */
final class ViewLayout
{
    public function __construct(private string $layout)
    {
    }

    public function name(): string
    {
        return $this->layout;
    }
}
