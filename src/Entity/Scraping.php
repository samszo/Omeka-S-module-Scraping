<?php
namespace Scraping\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Job;

/**
 * @Entity
 */
class Scraping extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @OneToOne(
     *     targetEntity="Omeka\Entity\Job"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */

    protected $job;
    /**
     * @OneToOne(
     *     targetEntity="Omeka\Entity\Job"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */
    protected $undoJob;

    /**
     * @Column
     */
    protected $name;

    /**
     * @Column
     */
    protected $params;

    /**
     * @Column(type="integer")
     */
    protected $version;

    /**
     * @OneToMany(
     *     targetEntity="ScrapingItem",
     *     mappedBy="import",
     *     fetch="EXTRA_LAZY"
     * )
     */
    protected $items;

    public function __construct()
    {
        $this->items = new ArrayCollection;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setJob(Job $job)
    {
        $this->job = $job;
    }

    public function getJob()
    {
        return $this->job;
    }

    public function setUndoJob(Job $undoJob)
    {
        $this->undoJob = $undoJob;
    }

    public function getUndoJob()
    {
        return $this->undoJob;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setVersion($version)
    {
        $this->version = $version;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function getItems()
    {
        return $this->items;
    }
}
