<?php
namespace Scraping\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Omeka\Api\Exception\RuntimeException;
use Laminas\Dom\Query;
use Laminas\Http\Client;
use Omeka\Stdlib\Message;

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
        'dbo'          => 'http://dbpedia.org/ontology',
        'drammar'          => 'http://www.purl.org/drammar',
        'schema'          => 'http://schema.org',
        'thea'          => 'https://jardindesconnaissances.univ-paris8.fr/onto/theatre#',
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
     * proriété pour gérer le décodage des caractères
     *
     * @var array
     */
    protected $utf8decode=false;
    /**
     * proriété pour gérer les liens vers les références
     *
     * @var array
     */
    protected $arrRef=[];

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
            if($p['utf8decode'])$this->utf8decode=true;
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
        $results = $dom->queryXpath($data["xpath"]);                    
        foreach ($results as $result) {
            $url = $result->getAttribute('href');
            if (filter_var($url, FILTER_FLAG_HOST_REQUIRED) == false) {
                if(substr($url, 0)!="/")$url="/".$url;
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
        if ($this->shouldStop()) {
            $this->logger->warn(new Message(
                'The job "Scraping:saveItem" was stopped: %d ', // @translate
                $data['id']
            ));
            return;
        }
        //création de l'item
        if($data['url'])$oItem = $this->ajoutePageWeb($data);
        else $oItem = $this->ajoutePageFragment($data);

        //exécution du mapping              
        $body = $oItem->value('bibo:content')->__toString();
        $dom = new Query($body);
        //enregistre les fragments et les infos supplémentaires
        //ATTENTION : le mapping fragment est toujours en dernier
        $infos = [];
        $numFrag = 0;
        foreach ($data['mapping'] as $i=>$m) {
            if($m['key']=='fragments'){
                $numFrag++;
                if(count($infos)){
                    $oItem=$this->updateItem('items',$oItem->id(),$infos);
                    //enregistrement des références
                    if(isset($data['setId'])){
                        $ident =  $oItem->value('dcterms:identifier')->__toString();
                        $this->arrRef[$data['setId']][$ident]=$oItem->id();
                    }

                    $infos=[];
                }
                $results = $dom->execute($m['xpath']);                    
                foreach ($results as $j=>$result) {
                    $body = $this->utf8decode ? utf8_decode($result->ownerDocument->saveXML($result)) : $result->ownerDocument->saveXML($result);
                    $m['id']=$oItem->id().'_'.$i.'_'.$j;
                    $m['titre']=$oItem->displayTitle().' - fragment : '.$numFrag.'_'.$j ;
                    $m['desc']=$m['xpath'];
                    $m['isPartOf']=$oItem->id();
                    $m['body']=$body;
                    $f = $this->saveItem($m);
                }    
            }else{
                //ajoute la propriété à l'item
                $results = $dom->execute($m['xpath']);
                foreach ($results as $j=>$result) {
                    $val = $this->utf8decode ? utf8_decode($result->nodeValue) : $result->nodeValue;
                    if(isset($m['start'])){
                        if(isset($m['end']))
                            $val = substr($val, $m['start'], $m['end']);
                        else
                            $val = substr($val, $m['start']);
                    }
                    if(isset($m['multi'])){
                        $vals = explode($m['multi'],$val);
                    }else
                        $vals = [$val];
                    foreach($vals as $v) {
                        $resource = false;
                        if(isset($m['getId'])){
                            $v =  $this->arrRef[$m['getId']][$v];
                            $resource = true;
                        }
                        if(isset($m['find']) && isset($m['replace'])){
                            $v = str_replace($m['find'], $m['replace'],$v);
                        }

                        $infos = $this->mapValues([$m['key']=>$v], $infos, $resource);                    
                    }            
                }
                //vérification de la valeur par défaut
                if($results->count()==0 && $m['val']){
                    $infos = $this->mapValues([$m['key']=>$m['val']], $infos);                    
                }
            }
        }
        if(count($infos)){
            $oItem=$this->updateItem('items',$oItem->id(),$infos);
            //enregistrement des références
            if(isset($data['setId'])){
                $ident =  $oItem->value('dcterms:identifier')->__toString();
                $this->arrRef[$data['setId']][$ident]=$oItem->id();
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
        if ($this->shouldStop()) {
            $this->logger->warn(new Message(
                'The job "Scraping:ajoutePageWeb" was stopped: %d ', // @translate
                $data['url']
            ));
            return;
        }

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
            $oItem = $this->updateItem('items',$result[0]->id(),$this->mapValues($data));
            $this->logger->info("Le fragment existe déjà : '".$oItem->displayTitle()."' (".$oItem->id().").");
        }else{
            //creation du fragment
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
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
     * Helper to update a resource.
     *
     *
     * @param string $resourceType
     * @param int $id
     * @param array $data
     * @return mixed
     */
    protected function updateItem($resourceType, $id, $data)
    {
        $resource = $this->api->read($resourceType, $id)->getContent();

        // Use arrays to simplify process.
        $currentData = json_decode(json_encode($resource), true);
        $replaced = $this->replacePropertyValues($currentData, $data);
        $newData = array_replace($data, $replaced);

        $fileData = [];
        $options['isPartial'] = true;
        $options['collectionAction'] = 'replace';
        $response = $this->api->update($resourceType, $id, $newData, $fileData, $options);
        return $response->getContent();
    }

    /**
     * Replace current property values by new ones that are set.
     *
     * @param array $currentData
     * @param array $newData
     * @return array Merged values extracted from the current and new data.
     */
    protected function replacePropertyValues(array $currentData, array $newData)
    {
        $currentValues = $this->extractPropertyValuesFromResource($currentData);
        $newValues = $this->extractPropertyValuesFromResource($newData);
        $updatedValues = array_replace($currentValues, $newValues);
        return $updatedValues ;
    }

        /**
     * Extract property values from a full array of metadata of a resource json.
     *
     * @param array $resourceJson
     * @return array
     */
    protected function extractPropertyValuesFromResource($resourceJson)
    {
        static $listOfTerms;
        if (empty($listOfTerms)) {
            $response = $this->api->search('properties', []);
            foreach ($response->getContent() as $member) {
                $term = $member->term();
                $listOfTerms[$term] = $term;
            }
        }
        return array_intersect_key($resourceJson, $listOfTerms);
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
     * @param boolean $resource force la valeur ressource
     * @return array
     */
    public function mapValues(array $ScrapingItem, array $omekaItem=[], $resource=false)
    {
        foreach ($ScrapingItem as $key => $value) {
            if (!$value) {
                continue;
            }
            if (!isset($this->itemFieldMap[$key])) {
                //on crée la clef à partir du json de paramétrage
                if($key=="class"){
                    $arrKey = explode(':',$value);
                    $omekaItem['o:resource_class'] = ['o:id' => $this->resourceClasses[$arrKey[0]][$arrKey[1]]->id()];        
                }else{
                    $arrKey = explode(':',$key);
                    if(count($arrKey)!=2)continue;
                    $this->itemFieldMap[$arrKey[1]][$arrKey[0]]=$arrKey[1];
                    $key = $arrKey[1];    
                }
            }
            foreach ($this->itemFieldMap[$key] as $prefix => $localName) {
                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    if ('bibo' == $prefix && 'uri' == $localName) {
                        $valueObject['@id'] = $value;
                        $valueObject['type'] = 'uri';
                    }else if ('source' == $key || 'isPartOf' == $key || $resource) {
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
