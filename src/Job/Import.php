<?php
namespace Scraping\Job;

//TODO:charger la librairie par composer https://github.com/Donatello-za/rake-php-plus
require dirname(dirname(__DIR__)) . '/src/RakePlus/ILangParseOptions.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/LangParseOptions.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/AbstractStopwordProvider.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/StopwordArray.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/StopwordsPatternFile.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/StopwordsPHP.php';
require dirname(dirname(__DIR__)) . '/src/RakePlus/RakePlus.php';


use DonatelloZa\RakePlus\RakePlus;

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
        'skos'          => 'http://www.w3.org/2004/02/skos/core#',
        /*
        'dbo'          => 'http://dbpedia.org/ontology/',
        'drammar'          => 'http://www.purl.org/drammar',
        'schema'          => 'http://schema.org',
        'thea'          => 'https://jardindesconnaissances.univ-paris8.fr/onto/theatre#',
        'doco'          => 'http://purl.org/spar/doco/'
        */
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
     * objet pour gérer l'extraction des mots clef
     *
     * @var object
     */
    protected $rake;
    /**
     * proriété pour gérer la langue des stop words
     *
     * @var string
     */
    protected $lang='fr_FR';
    /**
     * proriété pour optimiser la création de tag
     *
     * @var string
     */
    protected $tags=[];
    /**
     * proriété pour gérer les reprises
     *
     * @var array
     */
    protected $reprises=[];
    

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
        $this->logger->info('Perform start');        


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

        $this->rake = new RakePlus('OK',$this->lang);

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
            $url = isset($data['domaine']) ? $data['domaine'] : "";
            $url .= $result->getAttribute('href');
            if (filter_var($url) == false) {
                if(substr($url, 0)!="/")$url="/".$url;
                $url=$arrUrl['scheme'].'://'.$arrUrl['host'].$url;
            }
            $data['fctCallBack']['data']['oItemParent']=$data['oItem'];            
            $data['fctCallBack']['data']['url']=$url;
            //passe le xpath pour gérer la reprise
            $data['fctCallBack']['data']['xpath']=$data["xpath"];                        
            $action = $data['fctCallBack']['action'];        
            if(is_callable(array($this, $action))){
                $this->$action($data['fctCallBack']['data']);
            }else{
                $this->logger->error('Action non trouvées',$data);        
            }
        }

    }

    /**
     * enregistre des items à partir d'une url et de paramètres
     *
     * @param array     $data
     */
    protected function saveItems($data)
    {

        for ($i=$data['values'][0]; $i <= $data['values'][1]; $i++) { 
            if ($this->shouldStop()) {
                $this->logger->warn(new Message(
                    'The job "Scraping:saveItems" was stopped: %d ', // @translate
                    $i
                ));
                return;
            }
            if($data['increment']=='debfin'){
                $url = str_replace($data['params'][0], $i, $data['urlParams']);
                $url = str_replace($data['params'][1], $i+1, $url);
                $titre = $data['titreParams'].$i.' - '.$i+1; 
            }
            $data['titre']=$titre;     
            $data['url']=$url;     
            $this->saveItem($data);       
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

        //gestion de la reprise 
        if(isset($data['repriseK']) && isset($data['repriseV'])){
            if($data[$data['repriseK']]!=$data['repriseV'] && !in_array($data['xpath'], $this->reprises))return;
            else{ 
                if($data['xpath'] && !in_array($data['xpath'], $this->reprises))$this->reprises[]=$data['xpath'];        
                unset($data['repriseK']);
                unset($data['repriseV']);
            }
        }

        //récupération de l'item
        if(isset($data['oId']))$oItem = $this->api->read('items',$data['oId']);
        if(isset($data['url']))$oItem = $this->ajoutePageWeb($data);
        else $oItem = $this->ajoutePageFragment($data);
        
        //récupération du code html
        $html = $oItem->value('bibo:content')->__toString();
        //vérifie s'il faut forcer l'encoding quand l'xml vient de la base
        if(!isset($data['url']))$html = $this->forceHtmlEncoding($html);

        //création du requeteur
        $dom = new Query($html);

        //enregistre les fragments et les infos supplémentaires
        //ATTENTION : le mapping fragment est toujours en dernier
        $infos = [];
        $numFrag = 0;
        foreach ($data['mapping'] as $i=>$m) {
            //vérifie s'il faut créer un fragment
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
                //récupère les fragments
                $results = $dom->queryXpath($m['xpath']);                    
                foreach ($results as $j=>$result) {
                    $xml = $this->utf8decode ? utf8_decode($result->ownerDocument->saveXML($result)) : $result->ownerDocument->saveXML($result);
                    $m['id']=$oItem->id().'_'.$i.'_'.$j;
                    $m['titre']=$oItem->displayTitle().' - fragment : '.$numFrag.'_'.$j ;
                    $m['desc']=$m['xpath'];
                    $m['isPartOf']=$oItem->id();
                    if(isset($m['function'])){
                        switch ($m['function']) {
                            case 'index':
                                $m['titre'] = $m['val'].($j+1);
                                break;                            
                            case 'unique':
                                //récupère la clef
                                $xv = $this->getXpathValue($this->forceHtmlEncoding($xml), $m['xpath']);
                                $m['id'] = $xv['v'];
                                break;                            
                            case 'url':
                                //récupère l'url
                                $m['url'] = $m['domaine'].$result->value;
                                break;                            
                        }
                    }
                    if(isset($m['suptag'])){
                        $exp='/<'.$m['suptag'].'(.*?)<\/'.$m['suptag'].'>/s';
                        $xml=preg_replace($exp,'',$xml);
                    }
                    if(isset($m['find']) && isset($m['replace'])){
                        $xml = str_replace($m['find'], $m['replace'],$xml);
                    }
                    $m['body']=$xml;
                    //vérifie si la reprise a été faite
                    if (in_array($m['xpath'], $this->reprises)) {
                        unset($m['repriseK']);
                        unset($m['repriseV']);
                    }                    
                    $f = $this->saveItem($m);
                    //vérifie si la reprise est faite
                    if($f && isset($m['repriseK']) && isset($m['repriseV'])){
                        $this->reprises[]=$m['xpath'];        
                    }
                }    
            }else{
                //vérification des fonctions a executer
                if(isset($m['function']) && $m['function']=="count"){
                    $results = $dom->queryXpath($m['xpath']);
                    $infos = $this->mapValues([$m['key']=>$results->count().""], $infos);                    
                }else{
                    //récupère la valeur
                    $xv = $this->getXpathValue('', $m['xpath'], $dom, $m['val']);
                    $val = $xv['v'];
                    //calcul la valeur                     
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
                        if(isset($m['function'])){
                            if($m['function']=="extractPhrasesKeywords"){
                                $this->extractPhrasesKeywords($oItem,$v);
                            }
                            if($m['function']=="extractKeywords"){
                                $this->extractKeywords($oItem,$v);
                            }                        
                            if($m['function']=="setUri"){
                                $v = [$m['key'],$v, $xv['r']->getAttribute('href')];
                                $m['key']="setUri";
                            }
                            if($m['key']=="schema:actionOption"){
                                $v=$this->idImport.'_'.$v;
                            }
                            if($m['function']=="getUriParam"){
                                parse_str(parse_url($v,PHP_URL_QUERY),$uParams);
                                $v = isset($uParams[$m['kParam']]) ? $uParams[$m['kParam']] : '-';
                            }    
                        }                                         
                        $infos = $this->mapValues([$m['key']=>$v], $infos, $resource);                    
                    }
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
     * force l'encodage html
     *
     * @param   string      $html
     * @param   string      $encoding
     * 
     * @return  string
     */
    protected function forceHtmlEncoding($html, $encoding="UTF-8")    
    {
        return '<!DOCTYPE html><html><head><meta charset="'.$encoding.'"/><body>'.$html.'</body></head></html>';        
    }

    /**
     * récupère la première valeur d'un xpath
     *
     * @param   string                  $html
     * @param   string                  $xpath
     * @param   Laminas\Dom\Query       $dom
     * @param   string                  $default
     * 
     * @return  array
     */
    protected function getXpathValue($html, $xpath, $dom=false, $default='')    
    {
        if(!$dom) $dom = new Query($html);
        $results = $dom->queryXpath($xpath);
        foreach ($results as $j=>$result) {
            return [
                "v"=>$this->utf8decode ? utf8_decode($result->nodeValue) : $result->nodeValue,
                "r"=>$result
            ];
        }
        return [
            "v"=>$default,
            "r"=>null
        ];
    }


    /**
     * récupère les phrases et les mots clefs
     *
     * @param   o:item      $oItem
     * @param   string      $text
     * 
     * @return  array
     */
    protected function extractPhrasesKeywords($oItem, $text)    
    {
        $sentences = preg_split('/(?<=[.?!])\s+(?=[a-z])/i', $text);
        foreach ($sentences as $i=>$s) {
            $data = array();
            $data['id']=$oItem->id().'_'.$i;
            $data['titre']=$s;
            $data['key']="fragments";
            $data['relation']="thea:hasReplique";
            $data['class']= stripos($s, "?") ? "schema:Question" : "schema:Comment";
            if(stripos($s, "?"))$data['thea:isQuestion']="1";                                            
            if(stripos($s, "!"))$data['thea:isExclamation']="1";                                            
            $data['isPartOf']=$oItem->id();
            $f = $this->ajoutePageFragment($data);
            //extraction des mots clefs
            //$keywords = $this->rake->extract($s)->keywords();
            $phrase_scores = $this->rake->extract($s,$this->lang)->sortByScore('desc')->scores();
            foreach ($phrase_scores as $w=>$s) {
                $this->ajouteTag($w,$f,$s);
            }
        }

    }
    

    /**
     * récupère les mots clefs
     *
     * @param   o:item      $oItem
     * @param   string      $text
     * 
     * @return  array
     */
    protected function extractKeywords($oItem, $text)    
    {
        $phrase_scores = $this->rake->extract($text,$this->lang)->sortByScore('desc')->scores();
        foreach ($phrase_scores as $w=>$s) {
            $this->ajouteTag($w,$oItem,$s);
        }
    }


    /**
     * récupère le body d'une url
     *
     * @param   array     $data
     * @return  string
     */
    protected function getBody($data)
    {
        if(isset($data['oItem']) && $data['oItem']->value('bibo:content')){
            return $data['oItem']->value('bibo:content')->__toString();
        }else{
            $response = $this->client->setUri($data['url'])->send();
            if ($response->isSuccess()){
                $body = $response->getBody();
                //vérifie si les données sont dans un cdata
                if($data['cdata']){
                    //$dom = new Query($body);
                    $v = $this->getXpathValue($this->forceHtmlEncoding($body), $data['cdata']);//$dom->queryXpath($data['cdata']);
                    $body = $v['r']->ownerDocument->saveXML($v['r']);
               }
               return $body;
            }
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
            if(!isset($data['body']))$data['body']=$this->getBody($data);
            if(!isset($data['titre']))$data['titre']=$this->getPageTitre($data['body']);
            if(isset($data['oItemParent']))$data['source']=$data['oItemParent']->id();

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
        if(isset($data['fctCallBack']) && is_callable(array($this, $data['fctCallBack']['action']))){
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
            if (is_null($value)) {
                continue;
            }
            if (!isset($this->itemFieldMap[$key])) {
                //on crée la clef à partir du json de paramétrage
                //ATTENTION le vocubulaire doit être chargé dans $this->vocabularies cf. ligne 33
                if($key=="class"){
                    $arrKey = explode(':',$value);
                    $omekaItem['o:resource_class'] = ['o:id' => $this->resourceClasses[$arrKey[0]][$arrKey[1]]->id()];
                    continue 1; 
                }elseif($key=="relation"){
                    $arrKey = explode(':',$value);
                    $property = $this->properties[$arrKey[0]][$arrKey[1]];
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    $valueObject['value_resource_id'] = $ScrapingItem['isPartOf'];
                    $valueObject['type'] = 'resource';
                    $omekaItem[$property->term()][] = $valueObject;
                    $ScrapingItem['isPartOf']=false;
                    continue 1; 
                }elseif($key=="setUri"){
                    $arrKey = $value[0];
                    $property = $this->properties[$arrKey[0]][$arrKey[1]];
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    $valueObject['@id'] = $value[1];
                    $valueObject['o:label'] = $value[2];                    
                    $valueObject['type'] = 'uri';
                    $omekaItem[$property->term()][] = $valueObject;
                    continue 1; 
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


    /**
     * Ajoute un tag au format skos
     *
     * @param array     $tag
     * @param object    $oItem
     * @param int       $score
     * 
     * @return array
     */
    protected function ajouteTag($tag, $oItem, $score=0)
    {

        if(isset($this->tags[$tag]))
            $oTag=$this->tags[$tag];
        else{
            //vérifie la présence de l'item pour gérer la création
            $param = array();
            $param['property'][0]['property']= $this->properties["skos"]["prefLabel"]->id()."";
            $param['property'][0]['type']='eq';
            $param['property'][0]['text']=$tag; 
            //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
            $result = $this->api->search('items',$param)->getContent();
            //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
            //$this->logger->info("RECHERCHE COUNT = ".count($result));
            if(count($result)){
                $oTag = $result[0];
                //$this->logger->info("ID TAG EXISTE".$result[0]->id()." = ".json_encode($result[0]));
            }else{
                $param = [];
                $param['o:resource_class'] = ['o:id' => $this->resourceClasses['skos']['Concept']->id()];
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
                $valueObject['@value'] = $tag;
                $valueObject['type'] = 'literal';
                $param[$this->properties["dcterms"]["title"]->term()][] = $valueObject;
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["skos"]["prefLabel"]->id();
                $valueObject['@value'] = $tag;
                $valueObject['type'] = 'literal';
                $param[$this->properties["skos"]["prefLabel"]->term()][] = $valueObject;
                //création du tag
                $result = $this->api->create('items', $param, [], ['continueOnError' => true])->getContent();
                $oTag = $result;
                $importItem = [
                    'o:item' => ['o:id' => $oTag->id()],
                    'o-module-scraping:import' => ['o:id' => $this->idImport],
                    'o-module-scraping:action' => "Création Tag",
                ];
                $this->api->create('scraping_items', $importItem, [], ['continueOnError' => true]);        
            }
            $this->tags[$tag] = $oTag;
        }
        //vérifie s'il faut ajouter la relation à l'item
        $param = array();
        $param['property'][0]['property']= $this->properties["skos"]["semanticRelation"]->id()."";
        $param['property'][0]['type']='res';
        $param['property'][0]['text']=$oTag->id(); 
        $param['id']= $oItem->id(); 
        $result = $this->api->search('items',$param)->getContent();
        if(count($result)==0){
            //ajoute la relation à l'item
            $param = [];
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["skos"]["semanticRelation"]->id();
            $valueObject['value_resource_id'] = $oTag->id();
            $valueObject['type'] = 'resource';
            $param[$this->properties["skos"]["semanticRelation"]->term()][] = $valueObject;
            if($score){
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["skos"]["note"]->id();
                $valueObject['@value'] = $score."";
                $valueObject['type'] = 'literal';
                $param[$this->properties["skos"]["note"]->term()][] = $valueObject;    
            }
            $this->api->update('items', $oItem->id(), $param, []
                , ['isPartial'=>true, 'continueOnError' => true, 'collectionAction' => 'append']);
        }

        return $oTag;
    }    
}
