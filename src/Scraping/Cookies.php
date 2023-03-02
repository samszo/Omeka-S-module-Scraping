<?php
namespace scraping\Scraping;

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
       *
       * 
       */
      public function init()      
      {

        $this->arrCookies = [
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
  