<?php
namespace Scraping\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ScrapingItemAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'scraping_items';
    }

    public function getRepresentationClass()
    {
        return \Scraping\Api\Representation\ScrapingItemRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Scraping\Entity\ScrapingItem::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if ($data['o:item']['o:id']) {
            $item = $this->getAdapter('items')->findEntity($data['o:item']['o:id']);
            $entity->setItem($item);
        }
        if (isset($data['o-module-scraping:import']['o:id'])) {
            $import = $this->getAdapter('scrapings')->findEntity($data['o-module-scraping:import']['o:id']);
            $entity->setImport($import);
        }
        if ($data['o-module-scraping:action']) {
            $entity->setAction($data['o-module-scraping:action']);
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['import_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.import',
                $this->createNamedParameter($qb, $query['import_id']))
            );
        }
    }
}
