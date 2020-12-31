<?php
namespace Scraping\Form;

use Omeka\Form\Element\ItemSetSelect;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Validator\Callback;

class ImportForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'itemSet',
            'type' => ItemSetSelect::class,
            'options' => [
                'label' => 'Import into', // @translate
                'info' => 'Required. Import items into this item set.', // @translate
                'empty_option' => 'Select item set…', // @translate
                'query' => ['is_open' => true],
            ],
            'attributes' => [
                'required' => true,
                'class' => 'chosen-select',
                'id' => 'library-item-set',
            ],
        ]);

        $this->add([
            'name' => 'params',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => "Paramètres de l'algorithme d'extraction", // @translate
                'info' => "Required au format json. Pour plus d'info cf.", // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'params',
            ],
        ]);

        $inputFilter = $this->getInputFilter();

        $inputFilter->add([
            'name' => 'itemSet',
            'required' => true,
            'filters' => [
                ['name' => 'Int'],
            ],
            'validators' => [
                ['name' => 'Digits'],
            ],
        ]);

    }
}
