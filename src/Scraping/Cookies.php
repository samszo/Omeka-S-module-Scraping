<?php
namespace Scraping\Scraping;

class Cookies
  {
  
    var $arrCookies;
  
      /**
       * Construct a Cookies.
       *
       */
      public function __construct()
      {
        $this->arrCookies = [];
        $this->init();
      }
  
      /**
       * initialise le tableau des cookies
       *mv
       * 
       */
      public function init()      
      {

        $this->arrCookies = [
          'PHPSESSID'=>'to196h00u7t6bojerqdfdjovj1',
          '_ga'=>'GA1.2.86155204.1676903784',
          '_gid'=>'GA1.2.1057544247.1677070541'
        ];

      }
  
    /**
     * Ajoute les cookies au client
     *
     * @param object $client
     * @return object
     */
    public function set($client)
    {
      //pour un mode avancé
      foreach ($this->arrCookies as $k => $v) {
          $client->addCookie($k,$v);
      }

      return $client;
  }

  }
  