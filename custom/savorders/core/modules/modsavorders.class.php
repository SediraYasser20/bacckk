<?php
/* Copyright (C) 2003      Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2012 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2022 	   NextGestion        <contact@nextgestion.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       htdocs/savorders/core/modules/modsavorders.class.php
 *  \ingroup    savorders
 *  \brief      Description and activation file for module savorders
 */
include_once DOL_DOCUMENT_ROOT .'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module savorders
 */
class modsavorders extends DolibarrModules
{
	/**
	 *   Constructor. Define names, constants, directories, boxes, permissions
	 *
	 *   @param      DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $langs,$conf;

		$this->db = $db;
		$this->numero = 19060010;
		$this->rights_class = 'savorders';
		$this->family = "NextGestion";
		$this->editor_name = 'NextGestion';
		$this->editor_url = 'https://www.nextgestion.com';
		$this->name = preg_replace('/^mod/i','',get_class($this));
		$this->description = "Adds SAV management (extrafields, buttons, and tab) to Orders.";
		$this->version = '1.5'; // Incremented version
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->special = 0;
		$this->picto='order';

		$this->module_parts = array(
			'hooks' => array('ordercard', 'ordersuppliercard'),
			'css' 	=> array('/savorders/css/savorders.css'),
		);

		$this->dirs = array();
		$this->config_page_url = array('admin.php@savorders');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(5,0);
		$this->need_dolibarr_version = array(3,0);
		$this->langfiles = array("savorders@savorders", "savtab@savtab"); // Load both lang files

		$this->const = array();

		// ---- FIX: ADD THE TAB DECLARATION HERE ----
		// This is the modern, correct way to add a tab to an object card.
		// It makes the entire 'savtab' module and its hook redundant.
		$this->tabs = array(
			// Format: 'objecttype:+tabname:Title:module@langfile:$user->rights->perm->read:/path/to/tab.php?id=__ID__'
			'order:+sav:SAV:savorders@savorders:$user->rights->commande->lire:/custom/savorders/sav_tab.php?id=__ID__'
		);

		if (! isset($conf->savorders->enabled)) {
			$conf->savorders=new stdClass();
			$conf->savorders->enabled=0;
		}
		$this->dictionaries=array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 *		Function called when module is enabled.
	 *
	 *      @param      string	$options    Options when enabling module
	 *      @return     int             	1 if OK, 0 if KO
	 */
	public function init($options='')
	{
		global $conf, $langs;
		// IMPORTANT: Call parent constructor to register hooks AND TABS
		parent::__construct($this->db);

		$sqlm = array();

		dol_include_once('/savorders/class/savorders.class.php');
		$savorders = new savorders($this->db);
		$savorders->initsavordersModule($this->version);

		return $this->_init($sqlm, $options);
	}

	/**
	 *		Function called when module is disabled.
	 *
	 *      @param      string	$options    Options when enabling module
	 *      @return     int             	1 if OK, 0 if KO
	 */
	public function remove($options='')
	{
		$sql = array();
		$sql = array(
			'DELETE FROM `'.MAIN_DB_PREFIX.'extrafields` WHERE `name` like "savorders%"',
		);
		return $this->_remove($sql, $options);
	}
}