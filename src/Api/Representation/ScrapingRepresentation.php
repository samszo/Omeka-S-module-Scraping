<?php
namespace Scraping\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;

class ScrapingRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'scraping';
    }

    public function getJsonLdType()
    {
        return 'o-module-scraping:Scraping';
    }

    public function getJsonLd()
    {
        return [
            'o:job' => $this->job()->getReference(),
            'o-module-scraping:undo_job' => $this->undoJob()->getReference(),
            'o-module-scraping:name' => $this->resource->getName(),
            'o-module-scraping:url' => $this->resource->getUrl(),
            'o-module-scraping:version' => $this->resource->getVersion(),
        ];
    }

    public function job()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getJob());
    }

    public function undoJob()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getUndoJob());
    }

    public function version()
    {
        return $this->resource->getVersion();
    }

    public function name()
    {
        return $this->resource->getName();
    }

    public function params()
    {
        return $this->resource->getParams();
    }

    public function importItemCount($action='create')
    {
        $expr = new Comparison('action', '=', $action);
        $criteria = new Criteria();
        $criteria->where($expr);
        return $this->resource->getItems()->matching($criteria)->count();
        return $this->resource->getItems()->count();
    }
}
