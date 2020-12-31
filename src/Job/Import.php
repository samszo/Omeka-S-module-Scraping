<?php
namespace Scraping\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Omeka\Api\Exception\RuntimeException;
use Laminas\Dom\Query;
use Laminas\Http\Client;

class Import extends AbstractJob
{
    /**
     * Scraping API client
     *
     * @var Client
     */
    protected $client;

    /**
     * Scraping API URL
     *
     * @var Url
     */
    protected $url;

    /**
     * Vocabularies to cache.
     *
     * @var array
     */
    protected $vocabularies = [
        'dcterms'       => 'http://purl.org/dc/terms/',
        'dctype'        => 'http://purl.org/dc/dcmitype/',
        'foaf'          => 'http://xmlns.com/foaf/0.1/',
        'bibo'          => 'http://purl.org/ontology/bibo/',
    ];

    /**
     * Cache of selected Omeka resource classes
     *
     * @var array
     */
    protected $resourceClasses = [];

    /**
     * Cache of selected Omeka resource template
     *
     * @var array
     */
    protected $resourceTemplate = [];

    /**
     * Cache of selected Omeka properties
     *
     * @var array
     */
    protected $properties = [];

    /**
     * Priority map between Scraping item types and Omeka resource classes
     *
     * @var array
     */
    protected $itemTypeMap = [];

    /**
     * Priority map between Scraping item fields and Omeka properties
     *
     * @var array
     */
    protected $itemFieldMap = [];

    /**
     * Priority map between Scraping creator types and Omeka properties
     *
     * @var array
     */
    protected $creatorTypeMap = [];
    /**
     * proriété pour gérer la page de base
     *
     * @var object
     */
    protected $itemBase;
    /**
     * proriété pour gérer les annotations
     *
     * @var array
     */
    protected $annotations = [];
    /**
     * objet pour gérer les logs
     *
     * @var object
     */
    protected $logger;
    /**
     * objet pour gérer l'api
     *
     * @var object
     */
    protected $api;
    /**
     * proriété pour gérer l'identifiant de l'import
     *
     * @var array
     */
    protected $idImport;
    /**
     * proriété pour gérer l'identifiant de la collection
     *
     * @var object
     */
    protected $itemSet;
    /**
     * proriété pour gérer les propriété de l'url
     *
     * @var array
     */
    protected $arrUrl;


    /**
     * Perform the import.
     *
     * Accepts the following arguments:
     *
     * - itemSet:       The Scraping item set ID (int)
     *
     *
     */
    public function perform()
    {
        // Raise the memory limit to accommodate very large imports.
        ini_set('memory_limit', '500M');

        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');


        $this->itemSet = $this->api->read('item_sets', $this->getArg('itemSet'))->getContent();

        $this->cacheResourceClasses();
        $this->cacheResourceTemplate();
        $this->cacheProperties();

        $this->itemTypeMap = $this->prepareMapping('item_type_map');
        $this->itemFieldMap = $this->prepareMapping('item_field_map');
        $this->creatorTypeMap = $this->prepareMapping('creator_type_map');

        $apiVersion = $this->getArg('version', 0);
        $this->idImport = $this->getArg('idImport');
        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient')
            ->setOptions(['timeout' => 20]);

        //traitement des params
        //cf. modules/Scraping/data/exemples/cantiques.json
        $params = json_decode($this->getArg('params'),true);
        foreach($params as $p){
            $action = $p['action'];            
            if(is_callable(array($this, $action))){
                $this->$action($p['data']);
            }else{
                $this->logger->error('Action non trouvées',$p);        
            }
        }
    }

    /**
     * Récupère les url d'un a à partir d'un Xpath
     *
     * @param array $data
     * @return oItem
     */
    protected function getUrl($data)
    {
        if(!isset($data['body']))$data['body']=$this->getBody($data);
        $arrUrl = parse_url($data['url']);
        $dom = new Query($data['body']);
        $results = $dom->execute($data["xpath"]);                    
        foreach ($results as $result) {
            $url = $result->getAttribute('href');
            if (filter_var($url, FILTER_FLAG_HOST_REQUIRED) == false) {
                $url=$arrUrl['scheme'].'://'.$arrUrl['host'].$url;
            }
            $data['fctCallBack']['data']['oItemParent']=$data['oItem'];            
            $data['fctCallBack']['data']['url']=$url;            
            $action = $data['fctCallBack']['action'];        
            if(is_callable(array($this, $action))){
                $this->$action($data['fctCallBack']['data']);
            }else{
                $this->logger->error('Action non trouvées',$data);        
            }
        }

    }

    /**
     * enregistre un item à partir d'une url et d'un mapping
     *
     * @param array     $data
     * @return oItem
     */
    protected function saveItem($data)
    {
        /*
        $body = $this->getBody($data);
        $dom = new Query($body);
        $fragments = [];
        foreach ($data['mapping'] as $m) {
            if($m['key']!='fragments'){
                $results = $dom->execute($m['xpath']);                    
                foreach ($results as $result) {
                    $data[$m['key']]= $result->nodeValue;
                }    
            }
        }
        */
        //création de l'item
        $oItem = $this->ajoutePageWeb($data);              
        $body = $oItem->value('bibo:content')->__toString();
        $dom = new Query($body);
        //enregistre les fragments
        foreach ($data['mapping'] as $i=>$m) {
            if($m['key']=='fragments'){
                $results = $dom->execute($m['xpath']);                    
                foreach ($results as $j=>$result) {
                    $d = ['id'=>$oItem->id().'_'.$i.'_'.$j
                        ,'titre'=>$oItem->displayTitle().' - fragment : '.$i.'_'.$j 
                        ,'desc'=>$m['xpath']
                        ,'isPartOf'=>$oItem->id()
                        ,'body'=>$result->nodeValue
                    ];
                    $f = $this->ajoutePageFragment($d);
                }    
            }
        }
 
        return $oItem;
    }

    /**
     * récupère le body d'une url
     *
     * @param   array     $data
     * @return  string
     */
    protected function getBody($data)
    {
        if($data['oItem'] && $data['oItem']->value('bibo:content')){
            return $data['oItem']->value('bibo:content')->__toString();
        }else{
            $response = $this->client->setUri($data['url'])->send();
            if ($response->isSuccess())
                return $response->getBody();
            throw new RuntimeException('Impossible de récupérer la page Web : '.$response->getReasonPhrase());			    
        }
    }

    /**
     * récupère le titre d'une page
     *
     * @param string     $dody
     * @return string
     */
    protected function getPageTitre($body)
    {
        $titre = '-';
        $dom = new Query($body);
        $results = $dom->execute('/html/head/title');                    
        foreach ($results as $result) {
            $titre = $result->nodeValue;
        }
        return $titre;
    }

    /**
     * Ajoute la page Web
     * @param array     $data

     * @return oItem
     */
    protected function ajoutePageWeb($data)
    {
        //vérifie la présence de l'item pour ne pas écraser les données
        $param = array();
        $param['property'][0]['property']= $this->properties['bibo']['uri']->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$data['url']; 

        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));

        if(count($result)){
            $oItem = $result[0];
            $this->logger->info("La page Web existe déjà : '".$oItem->displayTitle()."' (".$oItem->id().").");
        }else{
            //récupération des données
            if(!$data['body'])$data['body']=$this->getBody($data);
            if(!$data['titre'])$data['titre']=$this->getPageTitre($data['body']);
            if($data['oItemParent'])$data['source']=$data['oItemParent']->id();

            //creation de la page Web
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['bibo']['Webpage']->id()];
            $oItem['o:resource_templates'] = ['o:id' => $this->resourceTemplate['scrapping web page']->id()];
            $oItem = $this->mapValues($data, $oItem);

            $response = $this->api->create('items', $oItem, [], ['continueOnError' => true]);
            //$this->logger->info("UPDATE ITEM".$result[0]->id()." = ".json_encode($result[0]));
            $oItem = $response->getContent();
            //enregistre la progression du traitement
            $importItem = [
                'o:item' => ['o:id' => $oItem->id()],
                'o-module-scraping:import' => ['o:id' => $this->idImport],
                'o-module-scraping:action' => "Création web page",
            ];
            $this->api->create('scraping_items', $importItem, [], ['continueOnError' => true]);
        }               
        if($data['fctCallBack'] && is_callable(array($this, $data['fctCallBack']['action']))){
            $data['fctCallBack']['data']['oItem']=$oItem;
            $data['fctCallBack']['data']['url']=$data['url'];
            $action = $data['fctCallBack']['action'];
            $this->$action($data['fctCallBack']['data']);
        }
        return $oItem;
    }

    /**
     * Ajoute un fragment de la page Web
     * @param array     $data

     * @return oItem
     */
    protected function ajoutePageFragment($data)
    {
        //vérifie la présence de l'item pour ne pas écraser les données
        $param = array();
        $param['property'][0]['property']= $this->properties['dcterms']['isReferencedBy']->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$data['id']; 

        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));

        if(count($result)){
            $oItem = $result[0];
            $this->logger->info("Le fragment existe déjà : '".$oItem->displayTitle()."' (".$oItem->id().").");
        }else{
            //creation du fragment
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['dctype']['Text']->id()];
            $oItem['o:resource_templates'] = ['o:id' => $this->resourceTemplate['scrapping page fragment']->id()];
            $oItem = $this->mapValues($data, $oItem);

            $response = $this->api->create('items', $oItem, [], ['continueOnError' => true]);
            //$this->logger->info("UPDATE ITEM".$result[0]->id()." = ".json_encode($result[0]));
            $oItem = $response->getContent();
            //enregistre la progression du traitement
            $importItem = [
                'o:item' => ['o:id' => $oItem->id()],
                'o-module-scraping:import' => ['o:id' => $this->idImport],
                'o-module-scraping:action' => "Création page fragment",
            ];
            $this->api->create('scraping_items', $importItem, [], ['continueOnError' => true]);
            //
        }               
        if($data['fctCallBack'] && is_callable(array($this, $data['fctCallBack']['action']))){
            $data['fctCallBack']['data']['oItem']=$oItem;
            $action = $data['fctCallBack']['action'];
            $this->$action($data['fctCallBack']['data']);
        }
        return $oItem;
    }    

    /**
     * Cache selected resource classes.
     */
    public function cacheResourceClasses()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $classes = $api->search('resource_classes', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($classes as $class) {
                $this->resourceClasses[$prefix][$class->localName()] = $class;
            }
        }
    }

    /**
     * Cache selected properties.
     */
    public function cacheProperties()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $properties = $api->search('properties', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($properties as $property) {
                $this->properties[$prefix][$property->localName()] = $property;
            }
        }
    }

    /**
     * Cache selected resource template.
     */
    public function cacheResourceTemplate()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $arrRT = ["scrapping web page","scrapping page fragment"];
        foreach ($arrRT as $label) {
            $rt = $api->search('resource_templates', [
                'label' => $label,
            ])->getContent();

            $this->resourceTemplate[$label]=$rt[0];
        }
    }

    /**
     * Convert a mapping with terms into a mapping with prefix and local name.
     *
     * @param string $mapping
     * @return array
     */
    protected function prepareMapping($mapping)
    {
        $map = require dirname(dirname(__DIR__)) . '/data/mapping/' . $mapping . '.php';
        foreach ($map as &$term) {
            if ($term) {
                $value = explode(':', $term);
                $term = [$value[0] => $value[1]];
            } else {
                $term = [];
            }
        }
        return $map;
    }

    /**
     * Map Scraping item data to Omeka item values.
     *
     * @param array $ScrapingItem The Scraping item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapValues(array $ScrapingItem, array $omekaItem)
    {
        foreach ($ScrapingItem as $key => $value) {
            if (!$value) {
                continue;
            }
            if (!isset($this->itemFieldMap[$key])) {
                continue;
            }
            foreach ($this->itemFieldMap[$key] as $prefix => $localName) {
                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    if ('bibo' == $prefix && 'uri' == $localName) {
                        $valueObject['@id'] = $value;
                        $valueObject['type'] = 'uri';
                    }else if ('source' == $key || 'isPartOf' == $key) {
                        $valueObject['value_resource_id'] = $value;
                        $valueObject['type'] = 'resource';
                    } else {
                        $valueObject['@value'] = $value;
                        $valueObject['type'] = 'literal';
                    }
                    $omekaItem[$property->term()][] = $valueObject;
                    continue 2;
                }
            }
        }
        return $omekaItem;
    }

}
