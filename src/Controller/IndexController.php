<?php
namespace Scraping\Controller;

use DateTime;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Scraping\Form\ImportForm;
use Scraping\Job;

class IndexController extends AbstractActionController
{

    /**
     * @var Client
     */
    protected $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function importAction()
    {
        $form = $this->getForm(ImportForm::class);

        
        $request = $this->getRequest();

        if ($request->isPost()) {
            $data = array_merge_recursive($request->getPost()->toArray());
    
            if ($data['itemSet'] && $data['params']) {
                $timestamp =  new DateTime();
                $timestamp = (int) $timestamp->format('U');
                $args = [
                    'itemSet'       => $data['itemSet'],
                    'params'        => $data['params'],
                    'version'       => 1,
                    'timestamp'     => $timestamp,
                 ];

                $import = $this->api()->create('scrapings', [
                    'o-module-scraping:version' => $args['version'],
                    'o-module-scraping:name' => 'Scrapings '.$timestamp,
                    'o-module-scraping:params' => $data['params'],
                ])->getContent();
                $args['idImport']=$import->id();
                $job = $this->jobDispatcher()->dispatch(Job\Import::class, $args);
                $this->api()->update('scrapings', $import->id(), [
                    'o:job' => ['o:id' => $job->getId()],
                ]);
                $message = new Message(
                    'Importing from Scraping. %s', // @translate
                    sprintf(
                        '<a href="%s">%s</a>',
                        htmlspecialchars($this->url()->fromRoute(null, [], true)),
                        $this->translate('Import another?')
                    ));
                $message->setEscapeHtml(false);
                $this->messenger()->addSuccess($message);
                return $this->redirect()->toRoute('admin/Scraping/default', ['action' => 'browse']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('id');
        $response = $this->api()->search('scrapings', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults(), $this->params()->fromQuery('page'));

        $view = new ViewModel;
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    public function undoConfirmAction()
    {
        $import = $this->api()
            ->read('scrapings', $this->params('import-id'))->getContent();
        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $import->url('undo'));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('scraping/index/undo-confirm');
        $view->setVariable('import', $import);
        $view->setVariable('form', $form);
        return $view;
    }

    public function undoAction()
    {
        if ($this->getRequest()->isPost()) {
            $import = $this->api()
                ->read('scrapings', $this->params('import-id'))->getContent();
            if (in_array($import->job()->status(), ['completed', 'stopped', 'error'])) {
                $form = $this->getForm(ConfirmForm::class);
                $form->setData($this->getRequest()->getPost());
                if ($form->isValid()) {
                    $args = ['import' => $import->id()];
                    $job = $this->jobDispatcher()->dispatch(Job\UndoImport::class, $args);
                    $this->api()->update('scrapings', $import->id(), [
                        'o-module-scraping:undo_job' => ['o:id' => $job->getId()],
                    ]);
                    $this->messenger()->addSuccess('Undoing Scraping import'); // @translate
                } else {
                    $this->messenger()->addFormErrors($form);
                }
            }
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

}
