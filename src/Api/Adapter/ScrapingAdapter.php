<?php
namespace Scraping\Api\Adapter;

use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ScrapingAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'scrapings';
    }

    public function getRepresentationClass()
    {
        return \Scraping\Api\Representation\ScrapingRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Scraping\Entity\Scraping::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();

        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }
        if (isset($data['o-module-scraping:undo_job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o-module-scraping:undo_job']['o:id']);
            $entity->setUndoJob($job);
        }

        if (isset($data['o-module-scraping:version'])) {
            $entity->setVersion($data['o-module-scraping:version']);
        }
        if (isset($data['o-module-scraping:name'])) {
            $entity->setName($data['o-module-scraping:name']);
        }
        if (isset($data['o-module-scraping:params'])) {
            $entity->setParams($data['o-module-scraping:params']);
        }
    }
}
