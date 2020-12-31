<?php
namespace Scraping;

return [
    'api_adapters' => [
        'invokables' => [
            'scrapings' => Api\Adapter\ScrapingAdapter::class,
            'scraping_items' => Api\Adapter\ScrapingItemAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'controllers' => [
        'factories' => [
            'Scraping\Controller\Index' => Service\IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack'      => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label'      => 'Scraping', // @translate
                'route'      => 'admin/Scraping',
                'resource'   => 'Scraping\Controller\Index',
                'pages'      => [
                    [
                        'label' => 'Import', // @translate
                        'route'    => 'admin/Scraping',
                        'action' => 'import',
                        'resource' => 'Scraping\Controller\Index',
                    ],
                    [
                        'label' => 'Past Imports', // @translate
                        'route'    => 'admin/Scraping/default',
                        'action' => 'browse',
                        'resource' => 'Scraping\Controller\Index',
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'Scraping' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/Scraping',
                            'defaults' => [
                                '__NAMESPACE__' => 'Scraping\Controller',
                                'controller' => 'index',
                                'action' => 'import',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'id' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:import-id[/:action]',
                                    'constraints' => [
                                        'import-id' => '\d+',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                            'default' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
