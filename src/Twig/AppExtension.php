<?php

namespace Liz\Bundle\EasyDocBundle\Twig;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
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
            new TwigFunction('doctrineAssociationText', [$this, 'doctrineAssociationText']),
        ];
    }

    public function doctrineAssociationText($value)
    {
        switch ($value){
            case ClassMetadataInfo::TO_ONE:
                $text = 'To-One';
                break;
            case ClassMetadataInfo::TO_MANY:
                $text = 'To-Many';
                break;
            case ClassMetadataInfo::MANY_TO_MANY:
                $text = 'Many-To-Many';
                break;
            case ClassMetadataInfo::MANY_TO_ONE:
                $text = 'Many-To-One';
                break;
            case ClassMetadataInfo::ONE_TO_MANY:
                $text = 'One-To-Many';
                break;
            case ClassMetadataInfo::ONE_TO_ONE:
                $text = 'One-To-One';
                break;
            default:
                $text = 'None';
        }
        return $text;
    }
}
