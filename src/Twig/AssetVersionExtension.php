<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `versioned('/css/app.css')` → "/css/app.css?v=<file modified time>". Browsers cache the stylesheets and script
 * hard; stamping each link with the file's own change time makes every update show up on the next page load
 * instead of after a forced refresh, and leaves unchanged files cached.
 */
final class AssetVersionExtension extends AbstractExtension
{
    /** @var array<string, string> */
    private array $versioned = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('versioned', $this->versioned(...))];
    }

    public function versioned(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..')) {
            return $path;
        }

        return $this->versioned[$path] ??= ($mtime = @filemtime($this->publicDir.$path)) !== false
            ? $path.'?v='.$mtime
            : $path;
    }
}
