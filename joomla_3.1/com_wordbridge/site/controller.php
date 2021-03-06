<?php
/**
 * @version     $Id$
 * @package  Wordbridge
 * @copyright   Copyright (C) 2011 Cognidox Ltd
 * @license  GNU AFFERO GENERAL PUBLIC LICENSE v3
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die( 'Restricted access' );

jimport('joomla.application.component.controller');

/**
 * Wordbridge Component Controller
 *
 * @package  Wordbridge
 * @since 1.5
 */
class WordbridgeController extends JControllerLegacy
{
    function __construct()
    {
        global $mainframe;
	$mainframe = JFactory::getApplication();
        parent::__construct();
        $this->registerDefaultTask( 'display' );
    }

    function display( $cachable = false, $urlparams = false )
    {
        // Set a default view if none exists
        if ( ! JRequest::getCmd( 'view' ) )
        {
            JRequest::setVar( 'view', 'entries' );
        }
        parent::display($cachable, $urlparams);
    }
}

