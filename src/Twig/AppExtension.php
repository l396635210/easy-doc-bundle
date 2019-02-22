<?php

namespace Liz\Bundle\EasyDocBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, you should add a third
            // parameter: ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/2.x/advanced.html#automatic-escaping
            new TwigFilter('html_class_format', [$this, 'htmlClassFormat']),
        ];
    }

    public function htmlClassFormat(string $str){
        return strtr(strtolower($str), [
            '/' => '-',
            '\\' => '-',
        ]);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('env', [$this, 'doEnv']),
        ];
    }

    public function doEnv($value)
    {
        return getenv(strtoupper($value));
    }
}
