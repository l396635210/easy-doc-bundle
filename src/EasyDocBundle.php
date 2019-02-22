<?php

namespace Liz\Bundle\EasyDocBundle;


use Liz\Bundle\EasyDocBundle\DependencyInjection\EasyDocExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class EasyDocBundle extends Bundle
{
    public function getContainerExtension()
    {
        if (null === $this->extension) {
            $this->extension = new EasyDocExtension();
        }
        return $this->extension;
    }
}
