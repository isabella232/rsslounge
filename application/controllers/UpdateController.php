<?php

/**
 * Controller for refreshing all feed items. Will be executed
 * by javascript of the frontend and the cronjob (silent)
 *
 * @package    application_controllers
 * @copyright  Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license    GPLv3 (http://www.gnu.org/licenses/gpl-3.0.html)
 */
class UpdateController extends Zend_Controller_Action {

    /**
     * Initialize the controller
     *
     * @return void
     */
    public function init() {
        // suppress view rendering
        Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer')->setNoRender(true);
    }
    

    /**
     * cronjob silent update
     *
     * @return void
     */
    public function silentAction() {
        // logging
        $logger = Zend_Registry::get('logger');
        $logger->log('start silent update', Zend_Log::DEBUG);
        
        // update feeds
        $updater = Zend_Controller_Action_HelperBroker::getStaticHelper('updater');
        
        // get all feeds
        $feedModel = new application_models_feeds();
        $feeds = $feedModel->fetchAll();
        
        $logger->log('update '.$feeds->count().' feeds', Zend_Log::DEBUG);
        foreach($feeds as $feed)
            $updater->feed($feed);
        
        // set last update
        $settingsModel = new application_models_settings();
        $settingsModel->write('lastrefresh',Zend_Date::now()->get(Zend_Date::TIMESTAMP));
        
        // delete orphaned thumbnails
        $updater->cleanupThumbnails();
        $logger->log('finished silent update', Zend_Log::DEBUG);
    }
    
    
    /**
     * will be executed by the javascript ajax call
     * and signals that it finished successfully a complete
     * update using ajax
     *
     * @return void
     */
    public function finishAction() {
        // set lastrefresh if timeout is already 0
        $updater = Zend_Controller_Action_HelperBroker::getStaticHelper('updater');
        if($updater->timeout()==0) {
        
            // save last refresh (current session will also be updated by models save)
            $lastrefresh = Zend_Date::now()->get(Zend_Date::TIMESTAMP);
            $settingsModel = new application_models_settings();
            $settingsModel->save(array(
                'lastrefresh' => $lastrefresh
            ));
            
            // delete orphaned thumbnails
            $updater->cleanupThumbnails();
        }
        
        // return new timeout and unread items
        $itemCounter = Zend_Controller_Action_HelperBroker::getStaticHelper('itemcounter');
        $this->_helper->json(
                array(
                    'timeout'      => $updater->timeout(),
                    'lastrefresh'  => isset($lastrefresh) ? $lastrefresh : Zend_Registry::get('session')->lastrefresh,
                    'categories'   => $itemCounter->unreadItemsCategories(),
                    'feeds'        => $itemCounter->unreadItemsFeeds()
                )
            );
    }
    
    
    /**
     * updates a given feed (for executing via ajax call)
     *
     * @return void
     */
    public function feedAction() {
        // timeout bigger than 0 = no feed refresh needed
        $updater = Zend_Controller_Action_HelperBroker::getStaticHelper('updater');
        if($updater->timeout()!=0) {
            $this->finishAction();
            return;
        }
        
        // get and check feed
        $feedModel = new application_models_feeds();
        $feeds = $feedModel->find($this->getRequest()->getParam('id'));
        
        if($feeds->count()==0)
            $this->_helper->json(array(
                'error' => $this->view->translate('No feed with given ID found')
            ));
        
        // update feed (fetch new data from source)
        $feed = $feeds->current();
        $result = $updater->feed($feed);
        
        // if necessary update icon
        $updateIcon = $feed->dirtyicon==1;
        if($updateIcon)
            $feedModel->saveIcon($feed);
        
        // create answer
        if(!is_numeric($result)) {
            $this->_helper->json(array(
                'error' => $result
            ));
        } else {
            // return category and feed unread items
            $this->_helper->json(
                array(
                    'success'       => true,
                    'icon'          => $updateIcon,
                    'lastrefresh'   => $result
                )
            );
        }
    }

}

