<?php
namespace Scraping\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class ScrapingItemRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-scraping:ScrapingItem';
    }

    public function getJsonLd()
    {
        return [
            'o-module-scraping:import' => $this->import()->getReference(),
            'o:item' => $this->job()->getReference(),
            'o-module-scraping:action' => $this->resource->getAction(),
        ];
    }

    public function import()
    {
        return $this->getAdapter('scrapings')
            ->getRepresentation($this->resource->getImport());
    }

    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }

    public function action()
    {
        return $this->getAdapter('action')
            ->getRepresentation($this->resource->getAction());
    }

}
