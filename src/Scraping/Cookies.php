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
          'PHPSESSID'=>'a1dv2gss7094cb9v73u92ojm15',
          '_ga'=>'GA1.2.1169492492.1675235636',
          '_gid'=>'GA1.2.882806103.1678468172'
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
  